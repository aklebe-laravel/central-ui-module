<?php
declare(strict_types = 1);

namespace Modules\CentralUi\app\Services\Backup;

use Illuminate\Support\Facades\Cache;
use Modules\CentralUi\app\Models\ProxtopServer;
use Modules\CentralUi\app\Services\ProxmoxService;

/**
 * Backups of the Proxmox VE servers: scheduled jobs (next run) and running vzdump tasks (progress).
 *
 * Only the Proxmox VE API, no Proxmox Backup Server:
 *   GET cluster/backup                       backup jobs incl. 'next-run' (UNIX time)
 *   GET cluster/tasks                        recent tasks of the cluster, running = no 'endtime'
 *   GET nodes/{node}/tasks/{upid}/log        log of a running vzdump task, read incrementally (BackupTaskLog)
 *
 * The API token needs 'Sys.Audit' on '/': without it cluster/backup answers 403 (shown as error to admins)
 * and cluster/tasks only lists the token's own tasks (the jobs run as root@pam, so no running backups).
 *
 * Written by RefreshProxmoxDataJob (part 'backups'), read by the desktop (BackupOverview). Cache keys
 * (prefix 'central-ui.proxmox.'):
 *   server.<serverId>.backups     jobs + running tasks of a server (see receiveServer())
 *   backups.task.<md5(upid)>      log state of a running task (BackupTaskLog), expires after a day
 *   backups.hot                   true while a backup runs or one starts soon (scheduler refreshes faster)
 *   backups.hash                  hash of all server data, a broadcast is only sent when it changed
 */
class ProxmoxBackupService
{
    /**
     * Max lines per log request and requests per task and run (a job with many guests writes thousands of lines).
     */
    public const int LogLinesPerRequest = 1000;
    public const int LogRequestsPerRun = 10;

    /**
     * A job starting within this many seconds counts as "hot" (see isHot()).
     */
    public const int HotBeforeSeconds = 120;

    /**
     * Log states of finished tasks are not deleted, they expire.
     */
    public const int TaskStateTtlSeconds = 86400;

    /**
     * Guest types of cluster/resources.
     */
    public const array GuestTypes = ['qemu', 'lxc'];

    /**
     * @param  ProxmoxService  $proxmox
     */
    public function __construct(protected ProxmoxService $proxmox)
    {
    }

    /**
     * Fetches the backup data of all servers with API access.
     *
     * @return bool  true if the data changed (the caller broadcasts then)
     */
    public function receiveAndCache(): bool
    {
        $hashes = [];
        $hot = false;

        /** @var ProxtopServer $server */
        foreach (
            ProxtopServer::query()
                ->where('api_allowed', true)
                ->get() as $server
        ) {
            $data = $this->receiveServer($server);
            Cache::forever($this->cacheKeyServer((int) $server->getKey()), $data);

            $hot = $hot || self::isHot($data, time());
            // 'fetched_at' changes every run, it must not cause a broadcast
            $hashes[$server->getKey()] = hash('xxh64', json_encode(array_diff_key($data, ['fetched_at' => true])));
        }

        Cache::forever($this->proxmox->buildCacheKey('backups', 'hot'), $hot);

        $hash = hash('xxh64', json_encode($hashes));
        $changed = Cache::get($this->proxmox->buildCacheKey('backups', 'hash')) !== $hash;
        Cache::forever($this->proxmox->buildCacheKey('backups', 'hash'), $hash);

        return $changed;
    }

    /**
     * Cached data of a server, see receiveServer(). [] if never fetched.
     *
     * @param  int  $serverId
     *
     * @return array
     */
    public function getServerBackups(int $serverId): array
    {
        return Cache::get($this->cacheKeyServer($serverId)) ?? [];
    }

    /**
     * True while a backup runs or a job starts soon. Read by the scheduler (cheap, one cache key).
     *
     * @return bool
     */
    public function isHotCached(): bool
    {
        return (bool) Cache::get($this->proxmox->buildCacheKey('backups', 'hot'), false);
    }

    /**
     * @param  ProxtopServer  $server
     *
     * @return array{ok: bool, error: ?string, fetched_at: int, jobs: array, running: array}
     */
    public function receiveServer(ProxtopServer $server): array
    {
        $result = ['ok' => true, 'error' => null, 'fetched_at' => time(), 'jobs' => [], 'running' => []];

        // the guests of the server (cluster/resources, fetched by the resource job every 30s)
        $resources = $this->proxmox->getServerVms((int) $server->getKey());

        $jobsJson = json_decode($this->proxmox->send($server, 'cluster/backup'), true);
        if (!is_array($jobsJson) || !isset($jobsJson['data'])) {
            // send() logged the details (403 without Sys.Audit, timeout, ...)
            $result['ok'] = false;
            $result['error'] = 'cluster/backup';
        } else {
            foreach ($jobsJson['data'] as $job) {
                if ($normalized = self::normalizeJob((array) $job, $resources)) {
                    $result['jobs'][] = $normalized;
                }
            }
            usort($result['jobs'], fn(array $a, array $b) => ($a['next_run'] ?? PHP_INT_MAX) <=> ($b['next_run'] ?? PHP_INT_MAX));
        }

        $tasksJson = json_decode($this->proxmox->send($server, 'cluster/tasks'), true);
        if (!is_array($tasksJson) || !isset($tasksJson['data'])) {
            $result['ok'] = false;
            $result['error'] ??= 'cluster/tasks';
        } else {
            foreach ($tasksJson['data'] as $task) {
                if (self::isRunningBackupTask((array) $task)) {
                    $result['running'][] = $this->buildRunningTask($server, (array) $task, $resources);
                }
            }
        }

        return $result;
    }

    /**
     * A backup job of cluster/backup in the form of the cache. Disabled jobs are skipped.
     *
     * @param  array  $job        item of cluster/backup
     * @param  array  $resources  cluster/resources of the server
     *
     * @return array|null  null = disabled
     */
    public static function normalizeJob(array $job, array $resources): ?array
    {
        // 'enabled' is missing for enabled jobs on older versions
        if ((string) ($job['enabled'] ?? '1') === '0') {
            return null;
        }

        $selection = [
            'vmids'   => BackupTaskLog::parseIdList((string) ($job['vmid'] ?? '')),
            'all'     => (string) ($job['all'] ?? '0') === '1',
            'pool'    => ($job['pool'] ?? '') !== '' ? (string) $job['pool'] : null,
            'exclude' => BackupTaskLog::parseIdList((string) ($job['exclude'] ?? '')),
        ];

        return [
            'id'       => (string) ($job['id'] ?? ''),
            'comment'  => (string) ($job['comment'] ?? ''),
            'schedule' => (string) ($job['schedule'] ?? ''),
            'next_run' => isset($job['next-run']) ? (int) $job['next-run'] : null,
            'storage'  => (string) ($job['storage'] ?? ''),
            'mode'     => (string) ($job['mode'] ?? ''),
            'node'     => ($job['node'] ?? '') !== '' ? (string) $job['node'] : null,
            'vmids'    => self::resolveGuests($selection, $resources, $job['node'] ?? null),
        ];
    }

    /**
     * Guest ids of a selection (job or start line of a task).
     *
     * - explicit ids: as they are
     * - all:  every guest (on $node if given)
     * - pool: every guest of the pool (on $node if given)
     * Excluded ids are removed in every case.
     *
     * @param  array{vmids: int[], all: bool, pool: ?string, exclude: int[]}  $selection
     * @param  array                                                          $resources  cluster/resources
     * @param  string|null                                                    $node
     *
     * @return int[]
     */
    public static function resolveGuests(array $selection, array $resources, ?string $node = null): array
    {
        $ids = $selection['vmids'] ?? [];

        if (($selection['all'] ?? false) || ($selection['pool'] ?? null)) {
            foreach ($resources as $resource) {
                if (!in_array($resource['type'] ?? null, self::GuestTypes, true) || !isset($resource['vmid'])) {
                    continue;
                }
                if ($node && ($resource['node'] ?? null) !== $node) {
                    continue;
                }
                if (($selection['pool'] ?? null) && ($resource['pool'] ?? null) !== $selection['pool']) {
                    continue;
                }
                $ids[] = (int) $resource['vmid'];
            }
        }

        $ids = array_diff(array_unique($ids), $selection['exclude'] ?? []);
        sort($ids);

        return array_values($ids);
    }

    /**
     * @param  array  $task  item of cluster/tasks
     *
     * @return bool
     */
    public static function isRunningBackupTask(array $task): bool
    {
        return ($task['type'] ?? null) === 'vzdump'
               && !isset($task['endtime'])
               && !empty($task['upid'])
               && !empty($task['node']);
    }

    /**
     * True while a backup runs or a job starts within HotBeforeSeconds (also a job whose start was missed
     * by a few seconds, until the next fetch sees its task).
     *
     * @param  array  $serverData  see receiveServer()
     * @param  int    $now
     *
     * @return bool
     */
    public static function isHot(array $serverData, int $now): bool
    {
        if (!empty($serverData['running'])) {
            return true;
        }
        foreach ($serverData['jobs'] ?? [] as $job) {
            if (($job['next_run'] ?? null) !== null && $job['next_run'] <= $now + self::HotBeforeSeconds) {
                return true;
            }
        }

        return false;
    }

    /**
     * A running vzdump task with the progress from its log.
     *
     * @param  ProxtopServer  $server
     * @param  array          $task       item of cluster/tasks
     * @param  array          $resources  cluster/resources of the server
     *
     * @return array
     */
    protected function buildRunningTask(ProxtopServer $server, array $task, array $resources): array
    {
        $state = $this->refreshTaskState($server, $task);

        // start line not read yet (log not reachable): a single guest backup has the guest as task id
        $selection = $state['selection'] ?? ['vmids' => BackupTaskLog::parseIdList((string) ($task['id'] ?? '')), 'all' => false, 'pool' => null, 'exclude' => []];

        // 'all' and 'pool' of a task mean the guests of the node the task runs on
        $vmids = self::resolveGuests($selection, $resources, (string) $task['node']);
        if ($state['current'] !== null && !in_array($state['current'], $vmids, true)) {
            $vmids[] = $state['current'];
        }

        return [
            'upid'       => (string) $task['upid'],
            'node'       => (string) $task['node'],
            'user'       => (string) ($task['user'] ?? ''),
            'started_at' => (int) ($task['starttime'] ?? 0),
            'vmids'      => $vmids,
            'finished'   => $state['finished'],
            'failed'     => $state['failed'],
            'current'    => $state['current'],
            'percent'    => $state['percent'],
        ];
    }

    /**
     * Reads the new lines of a task log and updates its cached state.
     *
     * @param  ProxtopServer  $server
     * @param  array          $task
     *
     * @return array state of BackupTaskLog
     */
    protected function refreshTaskState(ProxtopServer $server, array $task): array
    {
        $key = $this->proxmox->buildCacheKey('backups', 'task', md5((string) $task['upid']));
        $state = Cache::get($key) ?? BackupTaskLog::emptyState();

        $uri = sprintf('nodes/%s/tasks/%s/log', rawurlencode((string) $task['node']), rawurlencode((string) $task['upid']));
        for ($i = 0; $i < self::LogRequestsPerRun; $i++) {
            // 'start' is 0-based, the line numbers 'n' start at 1: start = number of lines already read
            $json = json_decode($this->proxmox->send($server, $uri, queryParams: [
                'start' => $state['offset'],
                'limit' => self::LogLinesPerRequest,
            ]), true);
            $lines = is_array($json) ? ($json['data'] ?? []) : [];
            if (!$lines) {
                break;
            }

            $offsetBefore = $state['offset'];
            $state = BackupTaskLog::apply($state, $lines);

            // end of the log, or no progress (protects against an endless loop)
            if (count($lines) < self::LogLinesPerRequest || $state['offset'] === $offsetBefore) {
                break;
            }
        }

        Cache::put($key, $state, self::TaskStateTtlSeconds);

        return $state;
    }

    /**
     * @param  int  $serverId
     *
     * @return string
     */
    protected function cacheKeyServer(int $serverId): string
    {
        return $this->proxmox->buildCacheKey('server', $serverId, 'backups');
    }
}
