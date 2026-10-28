## CentralUi Module

A module for [Mercy Scaffold Application](https://github.com/aklebe-laravel/mercy-scaffold).

This module provides together with [central-ui-theme](https://github.com/aklebe-laravel/central-ui-theme) a desktop for Proxmox.

## Installation

Add the module to `requires` in the project's `setup.yaml`, see
[Require modules and themes](https://github.com/aklebe-laravel/deploy-env-module#require-modules-and-themes).

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

## License

MIT, see [LICENSE.md](LICENSE.md).
