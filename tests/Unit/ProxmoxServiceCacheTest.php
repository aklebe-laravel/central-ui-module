<?php
declare(strict_types = 1);

namespace Modules\CentralUi\tests\Unit;

use Illuminate\Support\Facades\Cache;
use Modules\CentralUi\app\Models\ProxtopServerVm;
use Modules\CentralUi\app\Services\ProxmoxService;
use Modules\SystemBase\tests\Unit\Isolated\IsolatedTestCase;
use ReflectionProperty;

/**
 * Every piece of Proxmox data has its own cache key (no shared array of several MB).
 */
class ProxmoxServiceCacheTest extends IsolatedTestCase
{
    /**
     * @var ProxmoxService
     */
    protected ProxmoxService $proxmox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->proxmox = new ProxmoxService();

        // forgetLegacyCache() runs once per process, reset it for every test
        (new ReflectionProperty(ProxmoxService::class, 'legacyCacheForgotten'))->setValue(null, false);
    }

    public function test_server_vms_have_own_key_per_server(): void
    {
        $this->proxmox->setServerVms(1, [['vmid' => 100]]);
        $this->proxmox->setServerVms(2, [['vmid' => 200]]);

        $this->assertSame([['vmid' => 100]], $this->proxmox->getServerVms(1));
        $this->assertSame([['vmid' => 200]], Cache::get('central-ui.proxmox.server.2.vms'));
        $this->assertSame([], $this->proxmox->getServerVms(3));
    }

    public function test_rrd_stats_have_own_key_per_vm_and_timeframe(): void
    {
        $this->proxmox->setVmRrdStats(5, 'day', ['phases' => ['phase3' => ['entries' => 3]]]);
        $this->proxmox->setVmRrdStats(5, 'hour', ['phases' => ['phase1' => ['entries' => 1]]]);

        $this->assertSame(['phases' => ['phase3' => ['entries' => 3]]], $this->proxmox->getVmRrdStats(5, 'day'));
        $this->assertSame(['phases' => ['phase1' => ['entries' => 1]]], Cache::get('central-ui.proxmox.vm.5.rrd_stats.hour'));
        $this->assertSame([], $this->proxmox->getVmRrdStats(6, 'day'));
    }

    public function test_every_phase_has_a_known_timeframe(): void
    {
        foreach (ProxmoxService::RrdPhases as $phase => $definition) {
            $this->assertArrayHasKey($definition['timeframe'], ProxmoxService::RrdTimeframes, $phase);
        }
    }

    public function test_stats_are_calculated_per_timeframe_and_merged(): void
    {
        $now = 1_800_000_000;
        $rrd = [];
        // 90 minutes, one entry per minute, cpu 0.1 before the last 10 minutes, 0.5 within
        for ($i = 89; $i >= 0; $i--) {
            $rrd[] = ['time' => $now - $i * 60, 'cpu' => ($i < 10) ? 0.5 : 0.1, 'mem' => 1000];
        }

        $hourStats = $this->proxmox->calculateRrdStats('hour', $rrd);
        $this->assertSame(['phase1', 'phase2'], array_keys($hourStats['phases']));
        $this->proxmox->setVmRrdStats(4, 'hour', $hourStats);

        $phases = $this->proxmox->getVmRrdPhases(4);

        $this->assertEqualsWithDelta(0.5, $phases['phase1']['avg']['cpu'], 0.0001);
        $this->assertSame(10, $phases['phase1']['entries']);
        $this->assertSame(10, $phases['phase1']['minutes']);
        // 10 x 0.5 + 50 x 0.1 over 60 entries
        $this->assertEqualsWithDelta(10 / 60, $phases['phase2']['avg']['cpu'], 0.0001);
        $this->assertSame(60, $phases['phase2']['entries']);
        // day not fetched yet: empty, all values 0
        $this->assertSame(0, $phases['phase3']['entries']);
        $this->assertSame(0.0, $phases['phase3']['avg']['cpu']);
    }

    public function test_prepared_payload_whole_and_by_path(): void
    {
        $this->assertSame([], $this->proxmox->getCachedVmProperty(7, null, []));

        $this->proxmox->setCachedVmProperty(7, null, ['status' => 'running', 'schedules' => ['a' => 1]]);
        $this->assertSame('running', $this->proxmox->getCachedVmProperty(7, 'status'));
        $this->assertSame(1, $this->proxmox->getCachedVmProperty(7, 'schedules.a'));
        $this->assertSame('x', $this->proxmox->getCachedVmProperty(7, 'missing', 'x'));

        $this->proxmox->setCachedVmProperty(7, 'status', 'stopped');
        $this->assertSame(['status' => 'stopped', 'schedules' => ['a' => 1]], $this->proxmox->getCachedVmProperty(7));

        // other VMs are not touched
        $this->assertNull($this->proxmox->getCachedVmProperty(8));
    }

    public function test_model_and_id_address_the_same_key(): void
    {
        $vm = new ProxtopServerVm(['id' => 9, 'server_id' => 1]);

        $this->proxmox->setCachedVmProperty($vm, null, ['name' => 'nine']);
        $this->proxmox->setVmRrdStats($vm, 'day', ['phases' => []]);

        $this->assertSame(['name' => 'nine'], $this->proxmox->getCachedVmProperty(9));
        $this->assertSame(['phases' => []], $this->proxmox->getVmRrdStats(9, 'day'));
    }

    public function test_many_prepared_payloads_at_once(): void
    {
        $this->proxmox->setCachedVmProperty(1, null, ['name' => 'one']);
        $this->proxmox->setCachedVmProperty(3, null, ['name' => 'three']);

        $this->assertSame([
            1 => ['name' => 'one'],
            2 => [],
            3 => ['name' => 'three'],
        ], $this->proxmox->getCachedVmProperties([1, 2, new ProxtopServerVm(['id' => 3])]));

        $this->assertSame([], $this->proxmox->getCachedVmProperties([]));
    }

    public function test_simple_values_by_name(): void
    {
        $this->proxmox->setCache('resources', [['id' => 1]]);

        $this->assertSame([['id' => 1]], $this->proxmox->getCache('resources'));
        $this->assertSame([['id' => 1]], Cache::get('central-ui.proxmox.resources'));
        $this->assertSame('default', $this->proxmox->getCache('missing', 'default'));
    }

    public function test_legacy_cache_keys_are_removed(): void
    {
        Cache::forever('proxmox_server_api', ['1' => ['vms' => []]]);
        Cache::forever('proxtop_api_cache', ['resources' => []]);
        $this->proxmox->setCachedVmProperty(1, null, ['name' => 'one']);

        $this->proxmox->forgetLegacyCache();

        $this->assertNull(Cache::get('proxmox_server_api'));
        $this->assertNull(Cache::get('proxtop_api_cache'));
        // new keys stay
        $this->assertSame(['name' => 'one'], $this->proxmox->getCachedVmProperty(1));
    }
}
