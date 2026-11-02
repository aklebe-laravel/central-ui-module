<?php
declare(strict_types = 1);

namespace Modules\CentralUi\app\Services\Backup;

/**
 * Reads the log of a running vzdump task (Proxmox VE) and keeps a small state of its progress.
 *
 * The log is read incrementally: the state remembers the last line number ('offset'), the next call only
 * fetches and applies the new lines (see ProxmoxBackupService::refreshTaskState()). No API, no cache here,
 * so it is easy to test.
 *
 * Log lines this class understands (Proxmox VE 7/8/9):
 *   INFO: starting new backup job: vzdump 101 102 --mode snapshot --storage pbs
 *   INFO: starting new backup job: vzdump --all 1 --exclude 105,106 --mode snapshot --node pve
 *   INFO: starting new backup job: vzdump --pool family --mode snapshot
 *   INFO: Starting Backup of VM 101 (qemu)
 *   INFO:  45% (9.0 GiB of 20.0 GiB) in 1m 2s, read: 150.0 MiB/s, write: 140.0 MiB/s   (qemu only)
 *   INFO: Finished Backup of VM 101 (00:01:23)
 *   ERROR: Backup of VM 102 failed - ...
 */
final class BackupTaskLog
{
    /**
     * @return array{offset: int, selection: array{vmids: int[], all: bool, pool: ?string, exclude: int[]}|null, finished: int[], failed: int[], current: ?int, percent: ?int}
     */
    public static function emptyState(): array
    {
        return [
            // number of the last line already applied (Proxmox counts from 1)
            'offset'    => 0,
            // which guests the task backs up (from the start line), null = start line not seen yet
            'selection' => null,
            'finished'  => [],
            'failed'    => [],
            // guest that is backed up right now
            'current'   => null,
            // progress of 'current' in percent, null = unknown (lxc, or no progress line yet)
            'percent'   => null,
        ];
    }

    /**
     * Applies new log lines to the state.
     *
     * @param  array                                   $state  from emptyState() or a previous call
     * @param  array<int, array{n?: int, t?: string}>  $lines  'data' of GET nodes/{node}/tasks/{upid}/log
     *
     * @return array the new state
     */
    public static function apply(array $state, array $lines): array
    {
        $state += self::emptyState();

        foreach ($lines as $line) {
            $number = (int) ($line['n'] ?? 0);
            $text = (string) ($line['t'] ?? '');

            // already applied (the API may return the last known line again)
            if ($number && $number <= $state['offset']) {
                continue;
            }
            if ($number) {
                $state['offset'] = $number;
            }

            if (preg_match('/starting new backup job: vzdump(.*)$/', $text, $m)) {
                $state['selection'] = self::parseSelection($m[1]);
                continue;
            }

            if (preg_match('/Starting Backup of VM (\d+)\b/', $text, $m)) {
                $state['current'] = (int) $m[1];
                $state['percent'] = null;
                continue;
            }

            if (preg_match('/Finished Backup of VM (\d+)\b/', $text, $m)) {
                $state = self::endGuest($state, (int) $m[1], 'finished');
                continue;
            }

            if (preg_match('/Backup of VM (\d+) failed/', $text, $m)) {
                $state = self::endGuest($state, (int) $m[1], 'failed');
                continue;
            }

            // progress line of a qemu backup, only while a guest is running
            if ($state['current'] !== null && preg_match('/^INFO:\s+(\d{1,3})% \(/', $text, $m)) {
                $state['percent'] = min(100, (int) $m[1]);
            }
        }

        return $state;
    }

    /**
     * Arguments of the vzdump command in the start line.
     *
     * Bare numbers are guest ids, every option has a value ("--all 1", "--exclude 105,106").
     *
     * @param  string  $arguments  like " 101 102 --mode snapshot" or " --all 1 --exclude 105"
     *
     * @return array{vmids: int[], all: bool, pool: ?string, exclude: int[]}
     */
    public static function parseSelection(string $arguments): array
    {
        $result = ['vmids' => [], 'all' => false, 'pool' => null, 'exclude' => []];

        $tokens = preg_split('/\s+/', trim($arguments), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        for ($i = 0; $i < count($tokens); $i++) {
            $token = $tokens[$i];

            if (str_starts_with($token, '--')) {
                $value = trim((string) ($tokens[$i + 1] ?? ''), "'\"");
                $i++;

                match (substr($token, 2)) {
                    'all' => $result['all'] = ($value === '1'),
                    'pool' => $result['pool'] = ($value !== '') ? $value : null,
                    'exclude' => $result['exclude'] = self::parseIdList($value),
                    'vmid' => $result['vmids'] = array_merge($result['vmids'], self::parseIdList($value)),
                    default => null,
                };
                continue;
            }

            if (ctype_digit($token)) {
                $result['vmids'][] = (int) $token;
            }
        }

        $result['vmids'] = array_values(array_unique($result['vmids']));

        return $result;
    }

    /**
     * @param  string  $list  like "101,102" or "101 102"
     *
     * @return int[]
     */
    public static function parseIdList(string $list): array
    {
        $ids = [];
        foreach (preg_split('/[\s,;]+/', $list, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $id) {
            if (ctype_digit($id)) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  array   $state
     * @param  int     $vmid
     * @param  string  $list  'finished' or 'failed'
     *
     * @return array
     */
    private static function endGuest(array $state, int $vmid, string $list): array
    {
        if (!in_array($vmid, $state[$list], true)) {
            $state[$list][] = $vmid;
        }
        if ($state['current'] === $vmid) {
            $state['current'] = null;
            $state['percent'] = null;
        }

        return $state;
    }
}
