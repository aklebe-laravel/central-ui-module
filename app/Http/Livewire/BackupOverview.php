<?php
declare(strict_types = 1);

namespace Modules\CentralUi\app\Http\Livewire;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Modules\Acl\app\Models\AclResource;
use Modules\CentralUi\app\Models\ProxtopObserveServerItem;
use Modules\CentralUi\app\Models\ProxtopServer;
use Modules\CentralUi\app\Models\ProxtopServerVm;
use Modules\CentralUi\app\Services\Backup\ProxmoxBackupService;
use Modules\SystemBase\app\Http\Livewire\BaseComponent;

/**
 * Backup bar above the VM cards of the desktop: running backups (progress) and the next backup jobs.
 *
 * Reads the cache only (ProxmoxBackupService), never the API.
 * - developer/admin: all jobs and guests, plus the job name, storage and API errors
 * - other users: only jobs and backups of the VMs on their own desktop (observe items), nothing else
 * Hidden when there is nothing to show.
 */
class BackupOverview extends BaseComponent
{
    /**
     * Max upcoming jobs in the bar.
     */
    public const int MaxUpcoming = 5;

    /**
     * Max guest names per entry (the rest is counted: "+3").
     */
    public const int MaxNames = 6;

    /**
     * Re-render when the backup data changed (only sent on changes) and while the websocket is down.
     *
     * @return void
     */
    #[On('echo:proxmox-backup-updates,.Modules\CentralUi\app\Events\ProxmoxApiBackupDataUpdated')]
    #[On('vm-desktop:poll')]
    #[On('vm-desktop:refresh')]
    public function refreshBackups(): void
    {
    }

    /**
     * @return Application|Factory|View
     */
    public function render(): Factory|View|Application
    {
        return view('central-ui::livewire.backup-overview', [
            'overview' => $this->loadOverview(),
        ]);
    }

    /**
     * @return array{running: array, upcoming: array, errors: array, is_admin: bool}
     */
    protected function loadOverview(): array
    {
        $empty = ['running' => [], 'upcoming' => [], 'errors' => [], 'is_admin' => false];
        if (!($user = app('website_base_settings')->currentUser())) {
            return $empty;
        }

        // admin is always accepted by hasAclResource()
        $isAdmin = $user->hasAclResource([AclResource::RES_DEVELOPER]);

        $servers = ProxtopServer::query()
            ->where('api_allowed', true)
            ->get();
        if ($servers->isEmpty()) {
            return $empty;
        }

        $backups = app(ProxmoxBackupService::class);
        $serverData = [];
        $hosts = [];
        foreach ($servers as $server) {
            $serverData[$server->getKey()] = $backups->getServerBackups((int) $server->getKey());
            $hosts[$server->getKey()] = (string) ($server->description ?: $server->host);
        }

        if ($isAdmin) {
            // names of all known guests: frontend label, otherwise the Proxmox name
            $allowed = null;
            $names = [];
            ProxtopServerVm::query()
                ->whereIn('server_id', $servers->modelKeys())
                ->get(['id', 'server_id', 'extern_vm_id', 'name', 'name_intern'])
                ->each(function (ProxtopServerVm $vm) use (&$names) {
                    $names[$vm->server_id.':'.$vm->extern_vm_id] = (string) ($vm->name_intern ?: $vm->name);
                });
        } else {
            // only the VMs on the desktop of this user, same filter as the cards (VmDesktopItems)
            $allowed = [];
            $names = [];
            $userAclCodes = ProxtopServerVm::getUserAclCodes($user);
            $user->observeItems()
                ->whereIsEnabledAndHasActiveServer()
                ->with('vm.enabledPropertyTemplates')
                ->get()
                ->filter(fn(ProxtopObserveServerItem $item) => (bool) $item->vm?->isVisibleForAclCodes($userAclCodes))
                ->each(function (ProxtopObserveServerItem $item) use (&$allowed, &$names) {
                    $key = $item->vm->server_id.':'.$item->vm->extern_vm_id;
                    $allowed[$key] = true;
                    $names[$key] = (string) ($item->name_intern ?: $item->vm->getDisplayName());
                });

            if (!$allowed) {
                return $empty;
            }
        }

        return self::buildOverview($serverData, $hosts, $names, $allowed, $isAdmin, time());
    }

    /**
     * The entries of the bar from the cached server data. No database, no cache (testable).
     *
     * @param  array<int, array>         $serverData  server id => ProxmoxBackupService::receiveServer()
     * @param  array<int, string>        $hosts       server id => label for errors
     * @param  array<string, string>     $names       "serverId:vmid" => guest name
     * @param  array<string, bool>|null  $allowed     "serverId:vmid" => true, null = everything (admin)
     * @param  bool                      $isAdmin
     * @param  int                       $now
     *
     * @return array{running: array, upcoming: array, errors: array, is_admin: bool}
     */
    public static function buildOverview(array $serverData, array $hosts, array $names, ?array $allowed, bool $isAdmin, int $now): array
    {
        $running = [];
        $upcoming = [];
        $errors = [];

        foreach ($serverData as $serverId => $data) {
            if ($isAdmin && $data && !($data['ok'] ?? true)) {
                $errors[] = $hosts[$serverId] ?? (string) $serverId;
            }

            $visible = function (int $vmid) use ($serverId, $allowed): bool {
                return $allowed === null || isset($allowed[$serverId.':'.$vmid]);
            };
            $name = function (int $vmid) use ($serverId, $names): string {
                return ($names[$serverId.':'.$vmid] ?? '') ?: sprintf('VM %d', $vmid);
            };

            foreach ($data['running'] ?? [] as $task) {
                $vmids = array_values(array_filter($task['vmids'] ?? [], $visible));
                if (!$vmids) {
                    continue;
                }

                $done = array_values(array_intersect($task['finished'] ?? [], $vmids));
                $failed = array_values(array_intersect($task['failed'] ?? [], $vmids));
                $current = $task['current'] ?? null;
                $currentVisible = ($current !== null) && in_array($current, $vmids, true);
                $percent = $currentVisible ? ($task['percent'] ?? null) : null;

                // overall progress of the visible guests, the running one counts with its percent
                $progress = (count($done) + count($failed) + (($percent ?? 0) / 100)) / count($vmids);

                $running[] = [
                    'key'          => md5((string) ($task['upid'] ?? '')),
                    'node'         => $isAdmin ? (string) ($task['node'] ?? '') : '',
                    'started_at'   => (int) ($task['started_at'] ?? 0),
                    'total'        => count($vmids),
                    'done'         => count($done),
                    'failed_names' => array_map($name, $failed),
                    // another user's guest is backed up right now: "waiting" instead of its name
                    'current_name' => $currentVisible ? $name($current) : null,
                    'percent'      => $percent,
                    'progress'     => (int) floor(min(1, $progress) * 100),
                    'waiting'      => array_map($name, array_values(array_diff($vmids, $done, $failed, $currentVisible ? [$current] : []))),
                ];
            }

            foreach ($data['jobs'] ?? [] as $job) {
                if (($job['next_run'] ?? null) === null) {
                    continue;
                }
                $vmids = array_values(array_filter($job['vmids'] ?? [], $visible));
                if (!$vmids) {
                    continue;
                }

                $upcoming[] = [
                    'key'      => $serverId.'-'.($job['id'] ?? ''),
                    'next_run' => (int) $job['next_run'],
                    'label'    => $isAdmin ? (($job['comment'] ?? '') ?: ($job['id'] ?? '')) : '',
                    'names'    => array_map($name, array_slice($vmids, 0, self::MaxNames)),
                    'more'     => max(0, count($vmids) - self::MaxNames),
                    'count'    => count($vmids),
                    'storage'  => $isAdmin ? (string) ($job['storage'] ?? '') : '',
                    'mode'     => $isAdmin ? (string) ($job['mode'] ?? '') : '',
                    'schedule' => (string) ($job['schedule'] ?? ''),
                    'soon'     => (int) $job['next_run'] <= $now + ProxmoxBackupService::HotBeforeSeconds,
                ];
            }
        }

        usort($running, fn(array $a, array $b) => $a['started_at'] <=> $b['started_at']);
        usort($upcoming, fn(array $a, array $b) => $a['next_run'] <=> $b['next_run']);

        return [
            'running'  => $running,
            'upcoming' => array_slice($upcoming, 0, self::MaxUpcoming),
            'errors'   => $errors,
            'is_admin' => $isAdmin,
        ];
    }
}
