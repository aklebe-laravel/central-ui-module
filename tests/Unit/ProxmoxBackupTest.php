<?php
declare(strict_types = 1);

namespace Modules\CentralUi\tests\Unit;

use Modules\CentralUi\app\Http\Livewire\BackupOverview;
use Modules\CentralUi\app\Services\Backup\BackupTaskLog;
use Modules\CentralUi\app\Services\Backup\ProxmoxBackupService;
use Modules\SystemBase\tests\Unit\Isolated\IsolatedTestCase;

/**
 * Backup bar of the desktop: task log parser, job selection and the filter per user.
 * No API, no database.
 */
class ProxmoxBackupTest extends IsolatedTestCase
{
    /**
     * cluster/resources of a small cluster: two nodes, one pool, a storage entry (no guest).
     */
    protected function resources(): array
    {
        return [
            ['type' => 'qemu', 'vmid' => 101, 'node' => 'pve1', 'name' => 'web', 'pool' => 'family'],
            ['type' => 'lxc', 'vmid' => 102, 'node' => 'pve1', 'name' => 'dns'],
            ['type' => 'qemu', 'vmid' => 201, 'node' => 'pve2', 'name' => 'game', 'pool' => 'family'],
            ['type' => 'storage', 'node' => 'pve1', 'storage' => 'local'],
        ];
    }

    public function test_start_line_with_explicit_ids(): void
    {
        $this->assertSame(['vmids' => [101, 102], 'all' => false, 'pool' => null, 'exclude' => []],
            BackupTaskLog::parseSelection(' 101 102 --mode snapshot --storage pbs --notes-template \'{{guestname}}\''));
    }

    public function test_start_line_with_all_and_exclude(): void
    {
        $this->assertSame(['vmids' => [], 'all' => true, 'pool' => null, 'exclude' => [105, 106]],
            BackupTaskLog::parseSelection(' --all 1 --exclude 105,106 --mode snapshot --node pve1'));
    }

    public function test_log_progress_is_applied_incrementally(): void
    {
        $state = BackupTaskLog::apply(BackupTaskLog::emptyState(), [
            ['n' => 1, 't' => 'INFO: starting new backup job: vzdump 101 102 --mode snapshot'],
            ['n' => 2, 't' => 'INFO: Starting Backup of VM 101 (qemu)'],
            ['n' => 3, 't' => 'INFO:  12% (2.4 GiB of 20.0 GiB) in 10s, read: 245.8 MiB/s, write: 230.1 MiB/s'],
            ['n' => 4, 't' => 'INFO:  45% (9.0 GiB of 20.0 GiB) in 40s, read: 230.4 MiB/s, write: 228.0 MiB/s'],
        ]);
        $this->assertSame(4, $state['offset']);
        $this->assertSame(101, $state['current']);
        $this->assertSame(45, $state['percent']);

        // the API may return the last known line again: no change
        $state = BackupTaskLog::apply($state, [
            ['n' => 4, 't' => 'INFO: 100% (20.0 GiB of 20.0 GiB) in 1m, read: 1 MiB/s, write: 1 MiB/s'],
            ['n' => 5, 't' => 'INFO: Finished Backup of VM 101 (00:01:23)'],
            ['n' => 6, 't' => 'INFO: Starting Backup of VM 102 (lxc)'],
            ['n' => 7, 't' => 'ERROR: Backup of VM 102 failed - unable to open file'],
        ]);
        $this->assertSame(7, $state['offset']);
        $this->assertSame([101], $state['finished']);
        $this->assertSame([102], $state['failed']);
        $this->assertNull($state['current']);
        $this->assertNull($state['percent']);
    }

    public function test_job_selection_all_pool_node_and_exclude(): void
    {
        $resources = $this->resources();

        $this->assertSame([101, 102, 201], ProxmoxBackupService::normalizeJob(['all' => 1], $resources)['vmids']);
        $this->assertSame([101], ProxmoxBackupService::normalizeJob(['all' => 1, 'node' => 'pve1', 'exclude' => '102'], $resources)['vmids']);
        $this->assertSame([101, 201], ProxmoxBackupService::normalizeJob(['pool' => 'family'], $resources)['vmids']);
        $this->assertSame([102, 201], ProxmoxBackupService::normalizeJob(['vmid' => '201,102'], $resources)['vmids']);
        $this->assertNull(ProxmoxBackupService::normalizeJob(['enabled' => 0, 'all' => 1], $resources));
    }

    public function test_running_task_detection(): void
    {
        $this->assertTrue(ProxmoxBackupService::isRunningBackupTask(['type' => 'vzdump', 'upid' => 'UPID:pve1:1', 'node' => 'pve1']));
        $this->assertFalse(ProxmoxBackupService::isRunningBackupTask(['type' => 'vzdump', 'upid' => 'UPID:pve1:1', 'node' => 'pve1', 'endtime' => 1, 'status' => 'OK']));
        $this->assertFalse(ProxmoxBackupService::isRunningBackupTask(['type' => 'qmstart', 'upid' => 'UPID:pve1:1', 'node' => 'pve1']));
    }

    public function test_hot_while_running_or_shortly_before_a_job(): void
    {
        $now = 1_000_000;
        $this->assertTrue(ProxmoxBackupService::isHot(['running' => [['upid' => 'x']]], $now));
        $this->assertTrue(ProxmoxBackupService::isHot(['jobs' => [['next_run' => $now + 60]]], $now));
        $this->assertFalse(ProxmoxBackupService::isHot(['jobs' => [['next_run' => $now + 3600]], 'running' => []], $now));
    }

    public function test_overview_of_a_user_only_contains_own_vms(): void
    {
        $now = 1_000_000;
        $serverData = [
            1 => [
                'ok'      => true,
                'jobs'    => [
                    ['id' => 'backup-a', 'comment' => 'Nightly', 'next_run' => $now + 7200, 'storage' => 'pbs', 'mode' => 'snapshot', 'vmids' => [101, 102]],
                    ['id' => 'backup-b', 'comment' => 'Other', 'next_run' => $now + 3600, 'storage' => 'pbs', 'mode' => 'snapshot', 'vmids' => [201]],
                ],
                'running' => [
                    ['upid' => 'U1', 'node' => 'pve1', 'started_at' => $now - 300, 'vmids' => [101, 102, 201], 'finished' => [201], 'failed' => [], 'current' => 101, 'percent' => 50],
                ],
            ],
        ];
        $names = ['1:101' => 'Web', '1:102' => 'DNS', '1:201' => 'Game'];

        // user with VM 101 only: one job, its own VM, no job name, no storage
        $user = BackupOverview::buildOverview($serverData, [1 => 'pve'], $names, ['1:101' => true], false, $now);
        $this->assertCount(1, $user['upcoming']);
        $this->assertSame(['Web'], $user['upcoming'][0]['names']);
        $this->assertSame('', $user['upcoming'][0]['label']);
        $this->assertSame('', $user['upcoming'][0]['storage']);
        $this->assertSame(1, $user['running'][0]['total']);
        $this->assertSame(0, $user['running'][0]['done']);
        $this->assertSame('Web', $user['running'][0]['current_name']);
        $this->assertSame(50, $user['running'][0]['progress']);

        // admin: everything, sorted by next run
        $admin = BackupOverview::buildOverview($serverData, [1 => 'pve'], $names, null, true, $now);
        $this->assertSame(['Other', 'Nightly'], array_column($admin['upcoming'], 'label'));
        $this->assertSame(3, $admin['running'][0]['total']);
        $this->assertSame(1, $admin['running'][0]['done']);
        $this->assertSame(['DNS'], $admin['running'][0]['waiting']);
        $this->assertSame(50, $admin['running'][0]['progress']);
    }

    public function test_api_errors_only_for_admins(): void
    {
        $serverData = [1 => ['ok' => false, 'error' => 'cluster/backup', 'jobs' => [], 'running' => []]];

        $this->assertSame(['pve'], BackupOverview::buildOverview($serverData, [1 => 'pve'], [], null, true, 0)['errors']);
        $this->assertSame([], BackupOverview::buildOverview($serverData, [1 => 'pve'], [], ['1:101' => true], false, 0)['errors']);
    }
}
