<?php

declare(strict_types = 1);

namespace Modules\CentralUi\tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Modules\CentralUi\app\Services\Wireguard\ArrayNetworkRepository;
use Modules\CentralUi\app\Services\Wireguard\NetworkRepository;
use Modules\CentralUi\app\Services\Wireguard\WireguardMemberService;
use Tests\TestCase;

/**
 * The overview feeds the WireguardMember data table and form.
 */
final class WireguardMemberOverviewTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Cache::flush();

        config([
            'central-ui.pfsense.url'                  => 'https://pfsense.test',
            'central-ui.pfsense.api_key'              => 'test-key',
            'central-ui.wireguard.config_disk'        => 'local',
            'central-ui.wireguard.config_dir'         => 'wg_configs',
            'central-ui.wireguard.list_cache_seconds' => 30,
        ]);

        // the networks are bound instead of read from the database
        $this->app->instance(NetworkRepository::class, new ArrayNetworkRepository([
            'tun_wg1' => $this->network('10.123.99.'),
            'tun_wg2' => $this->network('10.123.98.'),
        ], [['tun_wg1', 'tun_wg2']]));
    }

    private function network(string $prefix): array
    {
        return [
            'prefix'      => $prefix,
            'endpoint'    => 'home.test:51821',
            'allowed_ips' => '0.0.0.0/0',
            'dns'         => '10.123.50.4',
        ];
    }

    private function peer(int $id, string $tunnel, string $name, string $ip): array
    {
        return ['id' => $id, 'tun' => $tunnel, 'descr' => $name, 'allowedips' => [['address' => $ip, 'mask' => 32]]];
    }

    public function test_overview_groups_peers_by_member_and_skips_foreign_tunnels_and_invalid_names(): void
    {
        Http::fake([
            '*/vpn/wireguard/peers' => Http::response([
                'data' => [
                    $this->peer(0, 'tun_wg2', 'bob', '10.123.98.2'),
                    $this->peer(1, 'tun_wg1', 'bob', '10.123.99.2'),
                    $this->peer(2, 'tun_wg1', 'anna', '10.123.99.3'),
                    $this->peer(3, 'tun_surf', 'surfshark', '10.14.0.2'),  // not a configured network
                    $this->peer(4, 'tun_wg1', '', '10.123.99.9'),            // no valid member name
                ],
            ]),
        ]);

        Storage::disk('local')
            ->put('wg_configs/bob.zip', 'zip');

        $rows = app(WireguardMemberService::class)->overview();

        $this->assertSame([
            [
                'member'      => 'anna',
                'networks'    => [['tunnel' => 'tun_wg1', 'ip' => '10.123.99.3']],
                'has_configs' => false,
            ],
            [
                'member'      => 'bob',
                // ordered like the network definitions
                'networks'    => [['tunnel' => 'tun_wg1', 'ip' => '10.123.99.2'], ['tunnel' => 'tun_wg2', 'ip' => '10.123.98.2']],
                'has_configs' => true,
            ],
        ], $rows);
    }

    public function test_overview_is_cached_until_fresh_is_requested(): void
    {
        Http::fake([
            '*/vpn/wireguard/peers' => Http::sequence()
                ->push(['data' => [$this->peer(0, 'tun_wg1', 'anna', '10.123.99.2')]])
                ->push(['data' => [$this->peer(0, 'tun_wg1', 'anna', '10.123.99.2'), $this->peer(1, 'tun_wg1', 'bob', '10.123.99.3')]]),
        ]);

        $service = app(WireguardMemberService::class);

        $this->assertCount(1, $service->overview());
        $this->assertCount(1, $service->overview()); // from the cache
        $this->assertCount(2, $service->overview(true));
        $this->assertNotNull($service->member('bob'));
        Http::assertSentCount(2);
    }
}
