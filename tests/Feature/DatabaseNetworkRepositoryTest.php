<?php

declare(strict_types = 1);

namespace Modules\CentralUi\tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\CentralUi\app\Models\ProxtopWireguardNetwork;
use Modules\CentralUi\app\Services\Wireguard\DatabaseNetworkRepository;
use Tests\TestCase;

/**
 * Needs the migrated table proxtop_wireguard_networks. Every change is rolled back.
 */
final class DatabaseNetworkRepositoryTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // start from an empty table inside the transaction
        ProxtopWireguardNetwork::query()
            ->delete();
    }

    private function createNetwork(string $tunnel, ?string $group, float $position, string $prefix): void
    {
        ProxtopWireguardNetwork::create([
            'position'    => $position,
            'tunnel'      => $tunnel,
            'host_group'  => $group,
            'prefix'      => $prefix,
            'endpoint'    => 'home.test:51820',
            'allowed_ips' => '0.0.0.0/0',
            'dns'         => '10.123.50.4',
        ]);
    }

    public function test_rows_are_ordered_by_position_and_grouped_by_host_group(): void
    {
        $this->createNetwork('tun_wg3', null, 3000, '10.123.97.');
        $this->createNetwork('tun_wg2', 'members', 2000, '10.123.98.');
        $this->createNetwork('tun_wg1', ' members ', 1000, '10.123.99.');   // trimmed on save
        $this->createNetwork('tun_wg4', '', 4000, '10.123.96.');            // empty = no group

        $repository = new DatabaseNetworkRepository();

        $this->assertSame(['tun_wg1', 'tun_wg2', 'tun_wg3', 'tun_wg4'], $repository->tunnelNames());
        $this->assertSame([['tun_wg1', 'tun_wg2']], $repository->groups());

        $network = $repository->find('tun_wg1');
        $this->assertSame('10.123.99.', $network->prefix);
        $this->assertSame(2, $network->firstHost);   // database defaults
        $this->assertSame(24, $network->mask);
        $this->assertSame(1280, $network->mtu);

        $this->assertSame(['tun_wg3'], array_map(fn($n) => $n->tunnel, $repository->hostGroup($repository->find('tun_wg3'))));
    }

    public function test_an_empty_table_reports_a_helpful_error(): void
    {
        $this->expectExceptionMessage('No WireGuard networks configured');

        (new DatabaseNetworkRepository())->find('tun_wg1');
    }
}
