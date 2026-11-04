# CentralUi Module

A module for the [Mercy Scaffold](https://github.com/aklebe-laravel/mercy-scaffold).

Together with [central-ui-theme](https://github.com/aklebe-laravel/central-ui-theme) it is a web desktop for Proxmox:
VM cards with status and start/stop (with a grace period), VM groups with permissions and time windows, and the
WireGuard VPN members on a pfSense firewall. A ready-to-use project is
[proxtop-web](https://github.com/aklebe-proxtop/proxtop-web).

## Installation

Add the module and its theme to `requires` of the project config and make the theme active:

```yaml
# project/config/setup.yaml
kernel:
  theme: "central-ui"
requires:
  - vendor: "aklebe-laravel"
    type: "module"
    version: "^2.0"           # or branch: "master" with branch_prefix: "dev-"
    dev_mode: true
    repository: 'git@github.com:${{module_vendor_name}}/${{module_snake_name_git}}.git'
    names: [ "central-ui-module" ]
  - vendor: "aklebe-laravel"
    type: "theme"
    version: "^2.0"
    dev_mode: true
    repository: 'git@github.com:${{module_vendor_name}}/${{module_snake_name_git}}.git'
    names: [ "central-ui-theme" ]
```

See [Require modules and themes](https://github.com/aklebe-laravel/deploy-env-module#require-modules-and-themes).

## Environment

These settings belong to this module and are not part of the Mercy scaffold templates.
Add them to the `env:` section of the project config (`project/config/setup.yaml`, the key itself into `secrets.yaml`).
The deployment writes them into the `.env`:

```yaml
env:
  # pfSense REST API (package pfSense-pkg-RESTAPI)
  PFSENSE_URL: "https://pfsense.example.internal"
  PFSENSE_API_KEY: ""
  PFSENSE_VERIFY_TLS: true
```

Also add `PFSENSE_API_KEY` to the existing `env_strict_literals` list, so a `$` in the key is kept literally.

## Wireguard VPN

List all Peers

```
sail artisan wg:member:list
```

Create new WG Peer

```
sail artisan wg:member:set alice_phone all
```

Create new WG Peer (and/or force renew keys)

```
sail artisan wg:member:set alice_phone all --renew
```

Delete WG Peer

```
sail artisan wg:member:remove alice_phone all
```

### Networks

The networks (pfSense tunnels) are stored in the table `proxtop_wireguard_networks`:
`/manage-data-all/ProxtopWireguardNetwork` (menu Admin > Proxmox > WireGuard Networks, deployment `0004`).

- `tunnel`: pfSense tunnel name like `tun_wg1` (unique)
- `host_group`: networks with the same group share their host numbers (last IP octet), empty = no group.
  `"*"`/`"all"` in the commands stands for every network that belongs to a group.
- `prefix`, `first_host`, `mask`, `endpoint`, `allowed_ips`, `dns`, `mtu`: used for new client configs

Changes never touch existing peers on the pfSense: they keep their IPs, and the peers of a deleted network
are only no longer managed by Proxtop.

Business import template (copy it to `project/business_imports/module-deploy-env/`):
`resources/business-import-templates/module-deploy-env/`.

`wg:doctor` checks the network definitions.

### Frontend

`/manage-data-all/WireguardMember` (menu Admin > Proxmox > WireGuard Members, deployment `0003`).
Same logic as the commands (`WireguardMemberService`), one row per member:

- New entry: name + networks (preselected: all networks that belong to a host group)
- Edit: checked networks get a peer, unchecked networks lose it, "Renew keys" = `--renew`
- Delete (row): removes the member from all configured networks
- Download (row or form): the zip with the client configs (contains private keys)

Only peers of the configured networks with a valid member name are listed.
The peer list is cached for `central-ui.wireguard.list_cache_seconds` (default 30), changes clear the cache.
Access: `developer` or `admin`.

## VM desktop

Each VM card has a switch. Switching off starts a grace period with a countdown, during it the shutdown
can be cancelled. Start and shutdown run as queued jobs (`StartVmJob`, `ShutdownVmJob`), the scheduler only
decides and dispatches, Proxmox data is refreshed by `RefreshProxmoxDataJob`. Time window rules ("Stop currently not allowed") do not apply during the grace period, so cancelling is always possible.

Requirements:

- The cache store must support locks (database, redis, file, memcached), and scheduler and queue workers must
  use the same store (`ShouldBeUnique`, `WithoutOverlapping`).
- After an update run `php artisan queue:restart` and restart the scheduler: long running processes keep the old code.
- Optional (recommended): own queues, so long Proxmox API calls never delay a start or stop:
  config `central-ui.queues.vm_actions` (e.g. `vm-actions`) plus a worker with `--queue=vm-actions`,
  the same for `central-ui.queues.proxmox_refresh`. Without these keys everything runs on `default`.

## Caching and statistics

All Proxmox data comes from queued jobs and is cached. The desktop cards never call the API, they only
read the cache. Every piece of data has its own key, nothing large is read for a single VM.

| Cache key (prefix `central-ui.proxmox.`) | Written by                          | Read by                       | Size         |
|------------------------------------------|-------------------------------------|-------------------------------|--------------|
| `server.<serverId>.vms`                  | resource job (`receiveResources`)   | resource job, RRD jobs        | small        |
| `vm.<vmId>.rrd_stats.<timeframe>`        | RRD job of the timeframe            | resource job                  | < 1 KB       |
| `vm.<vmId>.prepared`                     | resource job (payload rebuild)      | desktop cards (`VmStatus`)    | a few KB     |
| `resources`                              | resource job                        | console commands only         | all payloads |
| `server.<serverId>.backups`              | backup job (`ProxmoxBackupService`) | backup bar (`BackupOverview`) | small        |
| `server.<serverId>.backup_results`       | backup job (last result per guest)  | backup job                    | small        |
| `backups.task.<md5(upid)>`               | backup job (log state of a task)    | backup job                    | < 1 KB       |
| `backups.hot`, `backups.hash`            | backup job                          | scheduler, backup job         | tiny         |

`<vmId>` is the database id (`proxtop_server_vms.id`), not the Proxmox VMID.
The raw RRD history is not cached (`timeframe=day` is ~450 KB per VM), only its statistics.

### Flow

```
scheduler (every 2s, only dispatches)
 ├─ resource job   every scheduler.api.resource.interval (30s)
 │    cluster/resources → server.<id>.vms
 │    per VM: vms + rrd_stats + templates → vm.<id>.prepared → broadcast → cards re-render
 └─ RRD job        one per timeframe, see ProxmoxService::RrdTimeframes
      hour: every scheduler.api.rrd.interval     (60s)   → phase 1 (10 min), phase 2 (60 min)
      day:  every scheduler.api.rrd_day.interval (1800s) → phase 3 (whole day)
      per running VM: rrddata → RrdStats (avg/max per metric) → vm.<id>.rrd_stats.<timeframe>
```

The intervals are core configs of the module (Admin, deployment `0007`). `0` disables a timeframe.

### Live updates in the browser

Every Livewire listener costs one server round trip per card and open browser, so only what changes the
card re-renders it:

| Broadcast (channel)                                          | Re-renders              | Why                                                      |
|--------------------------------------------------------------|-------------------------|----------------------------------------------------------|
| `ProxmoxApiResourceDataUpdated` (`proxmox-resource-updates`) | every card (`VmStatus`) | new payload in `vm.<id>.prepared`                        |
| `LocalDesktopUpdated` (`vm-updates`)                         | every card (`VmStatus`) | start/shutdown/grace of any user                         |
| `ProxmoxApiRrdDataUpdated` (`proxmox-rrd-updates`)           | nothing                 | statistics reach the cards with the next resource update |
| `ProxmoxApiBackupDataUpdated` (`proxmox-backup-updates`)     | backup bar only         | sent only when jobs or running backups changed           |

- The desktop (`VmDesktop`) listens to none of them (its `init()` runs on every request).
- The pulses on the cards (`partials/update-bubble.blade.php`, Alpine `updateBubble` in `centralUi.js`) show
  all three channels without any server request: one Echo subscription per channel for the whole page.
- The metric boxes (`partials/resource-card.blade.php`) are plain Blade, they update with the card.
- Avoid timestamps in `wire:key`: a changing key re-mounts everything below it on every update.
- Websocket down: `liveUpdateFallback` refreshes the cards every 20 seconds (`vm-desktop:poll`).

### Extending

All definitions are constants in `ProxmoxService` (and `RrdStats` for the metrics), nothing else has to change:

- **New metric** from the RRD entries (like `maxcpu`): add it to `RrdStats::METRICS`.
  Every phase then contains `avg.<metric>` and `max.<metric>`. Use it in a `prepare*Stats()` method
  with `$this->rrdValue($phases, 'phase1', '<metric>')` (or `'max'` as 4th argument).
- **New phase** (like 6 hours): add it to `ProxmoxService::RrdPhases` with its timeframe and minutes.
- **New timeframe** (like `week`): add it to `ProxmoxService::RrdTimeframes` with config path and default
  interval (and a core config row in a new deployment). The scheduler dispatches its job automatically.
- **New cached data**: one key per VM/server with `buildCacheKey()`, a getter/setter pair in `ProxmoxService`
  and a row in the table above. Never put a list of all VMs with large data into one key.

### Debugging

```
php artisan central-ui:fetch                       # fetch everything now and rebuild the payloads
php artisan tinker
> app(\Modules\CentralUi\app\Services\ProxmoxService::class)->getVmRrdPhases(5)
> Cache::get('central-ui.proxmox.vm.5.prepared')
```

## Backups

The desktop toolbar shows a backup chip (`BackupOverview`, hidden when empty), its popover lists running backups
with progress, failed backups and the next scheduled backup jobs. A VM card shows the badge "Backup" while Proxmox holds the
config lock `backup` of the guest (field `lock` of `cluster/resources`, no extra API call).

Only the Proxmox VE API is used (no Proxmox Backup Server):

| Endpoint                        | Used for                                                                    |
|---------------------------------|-----------------------------------------------------------------------------|
| `cluster/backup`                | enabled jobs, `next-run`, guests (`vmid`, `all`, `pool`, `exclude`, `node`) |
| `cluster/tasks`                 | running `vzdump` tasks (no `endtime`) and finished ones (last results)      |
| `nodes/{node}/tasks/{upid}/log` | progress: current guest, percent (qemu), finished and failed guests         |

The log is read incrementally (`BackupTaskLog`, only new lines per run).

**Last results:** every finished `vzdump` task is read once (at most 5 per server and run) and gives the last
result per guest: ok, or failed with the reason of the log line `Backup of VM x failed - <reason>`. If a task
fails before any guest (storage not reachable), all its guests fail with the task status. The API does not link
a task to its job, so the result of a job is the latest result of each of its guests ("Last: OK",
"Last: 1 of 3 failed"). A failed guest stays in the list "Last backup failed" until a later backup succeeds.

The bubble of the chip shows the state by color (calculated in the browser every 15s, `backupSignal` in
`centralUi.js`, windows in `BackupOverview::SignalWindows`), first match wins: backup running (orange, pulsing),
job within 15 minutes (yellow, pulsing), job within 2 hours (light yellow), last backup failed within 6 hours (red), last backup succeeded within 6 hours (green), otherwise blue.

**Permission:** the API token needs `Sys.Audit` on `/` (with privilege separation: on the token itself).
Without it `cluster/backup` answers 403 (the bar shows an error to admins) and `cluster/tasks` only lists the
token's own tasks, so running backups of the scheduler (`root@pam`) are missing.

Who sees what:

- `developer`/`admin`: all jobs and guests, job name (comment or id), storage, mode, node, API errors
- other users: only jobs and backups of the VMs on their own desktop (same filter as the cards)

Intervals (core configs, deployment `0008`): `scheduler.api.backups.interval` (60s) and
`scheduler.api.backups.interval_hot` (15s, while a backup runs or a job starts within 2 minutes).
`0` disables the backup bar data. A broadcast is only sent when the data changed.

Debugging:

```
php artisan tinker
> app(\Modules\CentralUi\app\Services\Backup\ProxmoxBackupService::class)->receiveAndCache()
> Cache::get('central-ui.proxmox.server.1.backups')
```

## Desktop toolbar

The header of the cards (`VmDesktopItems`) is a toolbar in every layout (`BaseDataTable::HEADER_VIEW_TOOLBAR` of
the DataTable module): search, then the elements of config `central-ui.desktop_toolbar` (backups, server power).
Other modules add or change elements in their file `config/combined-module-central-ui.php`:

```php
return [
    'desktop_toolbar' => [
        // view gets $_config and $collectionName, 'css_group' is the css of its wrapper
        'my_item' => ['view' => 'my-module::toolbar.my-item', 'position' => 6000, 'css_group' => ''],
        // remove an element of this module
        'server_power' => ['view' => null],
    ],
];
```

Rows per page and the other filters are only shown in the list layout.

## Server power

The button "Server" in the toolbar shuts down the whole server, including Proxtop itself. Only for `admin`, always
confirmed by a message box of the driver (`config/message-boxes.php`). The confirmation warns if a backup runs or
starts within `central-ui.power.backup_warning_seconds` (15 minutes); the request is checked again on the server.

How the server is shut down is a driver (config `central-ui.power.driver`, env `SERVER_POWER_DRIVER`):

| Driver              | What happens                                                                                                                          | Needs                                                                         |
|---------------------|---------------------------------------------------------------------------------------------------------------------------------------|-------------------------------------------------------------------------------|
| `proxmox` (default) | Proxmox API `POST nodes/{node}/status command=shutdown` for every online node (Proxmox stops the VMs first), at once, no grace period | `Sys.PowerMgmt` for the API token; optional `SERVER_POWER_NODES` (comma list) |
| `http`              | a power service of the host decides, e.g. `pve-power` with grace period and Telegram buttons                                          | `SERVER_POWER_URL`, `SERVER_POWER_TOKEN`                                      |
| `none`              | no button                                                                                                                             |                                                                               |

Without `SERVER_POWER_DRIVER`: `http` if `SERVER_POWER_URL` is set, otherwise `proxmox`. Own drivers implement
`Services\Power\ServerPowerDriver` and are added to `central-ui.power.drivers` (plus their message boxes).

```yaml
# project/config/secrets.yaml or the network of the instance, driver http
env:
  SERVER_POWER_URL: "https://power-api.example.internal/shutdown"
  SERVER_POWER_TOKEN: "<same as POWER_API_TOKEN on the host>"
  # SERVER_POWER_VERIFY_TLS: false   # only for https with a self signed certificate
```

Contract of `http` (see `HttpShutdown`), always `Authorization: Bearer <token>`. `SERVER_POWER_URL` ends with
`/shutdown`, the other actions are its siblings:

| Request             | Body                                                                                    | For                       |
|---------------------|-----------------------------------------------------------------------------------------|---------------------------|
| `POST .../shutdown` | `{"action": "shutdown", "source": "proxtop", "requested_by": "<name>", "force": false}` | start the grace period    |
| `GET .../status`    |                                                                                         | countdown (`shutdown_at`) |
| `POST .../extend`   | `{"minutes": 30, "requested_by": "<name>"}`                                             | extend (every user)       |
| `POST .../cancel`   | `{"requested_by": "<name>"}`                                                            | stop (admins only)        |

Answer: `{"ok": true, "shutdown_at": <UNIX time or null>, "message": "..."}`.

### Countdown

Drivers with grace period implement `Services\Power\ServerPowerSchedule` (`http` does, `proxmox` has no grace
period). The queue job (`RefreshProxmoxDataJob`, part `power`) asks for the planned shutdown every
`SERVER_POWER_STATUS_INTERVAL` seconds (30), every 10 seconds while one is planned, caches it and broadcasts
`ServerPowerUpdated` (channel `server-power-updates`) on changes. While a shutdown is planned **every user** sees the
countdown instead of the button (the browser counts down itself). Everyone can extend it (`central-ui.power.extend_minutes`, 30 and 120), only admins can stop it. A shutdown planned elsewhere (power
button, Telegram, `shutdown +15` via SSH) shows up as well, because the host reports its systemd schedule.

## Proxmox API token

The API token of a server (Admin > Proxmox > Servers) needs these privileges (with privilege separation: on the
token itself, otherwise on its user):

| Privilege                                                   | Path     | For                                                  |
|-------------------------------------------------------------|----------|------------------------------------------------------|
| `VM.Audit`                                                  | `/`      | `cluster/resources` (guests), `rrddata` (statistics) |
| `VM.PowerMgmt`                                              | `/`      | start and shutdown of the VMs                        |
| `VM.GuestAgent.Unrestricted` (PVE 9) / `VM.Monitor` (PVE 8) | `/`      | commands in a VM by the guest agent (`agent/exec`)   |
| `Sys.Audit`                                                 | `/`      | nodes and storages, backup jobs, tasks and task logs |
| `Sys.PowerMgmt` (only power driver `proxmox`)               | `/nodes` | shutdown of the whole server (button "Server")       |

With the power driver `http` the shutdown of the server does not use this token (no `Sys.PowerMgmt` needed).
`Sys.Audit` is required for the backups: without it `cluster/backup` answers 403 and the backup tasks of the
scheduler (`root@pam`) are not visible.

## Time zones

Internally everything is UTC (`app.timezone`, database, calculations). Local are only the times people see and
type in (Setting of the WebsiteBase module):

- `APP_DISPLAY_TIMEZONE` (config `website-base.timezone`, e.g. `Europe/Berlin`): time zone of the installation.
  The schedules of the VM groups (cron expression, start and end date) are local times of it, including daylight
  saving time ("0 8 * * *" opens at 08:00 local time all year). Empty: UTC (former behavior).
- User setting `timezone` (profile, WebsiteBase deployment `0018`): times shown to this user (backups, server
  power, online since). Empty: the installation.

In code: `app('website_base_settings')->toDisplayTime($timestamp)->format('H:i')` for display,
`installationTimezone()` for shared local times, `ProxtopServerVm::scheduleTiming()` for a schedule.

## License

MIT, see [LICENSE.md](LICENSE.md).
