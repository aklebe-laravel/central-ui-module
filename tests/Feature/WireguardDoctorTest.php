<?php

declare(strict_types = 1);

namespace Modules\CentralUi\tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Modules\CentralUi\app\Services\Wireguard\ArrayNetworkRepository;
use Modules\CentralUi\app\Services\Wireguard\NetworkRepository;
use Tests\TestCase;

final class WireguardDoctorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        config([
            'central-ui.pfsense.url'                      => 'https://pfsense.test',
            'central-ui.pfsense.api_key'                  => 'test-key',
            'central-ui.wireguard.config_disk'            => 'local',
            'central-ui.wireguard.config_dir'             => 'wg_configs',
            'central-ui.wireguard.config_prefix'          => 'family_',
            'central-ui.wireguard.config_name_max_length' => 15,
        ]);

        $this->useNetworks([['tun_wg1', 'tun_wg2']]);
    }

    /**
     * Binds the networks instead of the database.
     *
     * @param  list<list<string>>  $groups
     */
    private function useNetworks(array $groups): void
    {
        $this->app->instance(NetworkRepository::class, new ArrayNetworkRepository([
            'tun_wg1' => $this->network('10.123.99.'),
            'tun_wg2' => $this->network('10.123.98.'),
        ], $groups));
    }

    private function network(string $prefix): array
    {
        return [
            'prefix'      => $prefix,
            'endpoint'    => 'home.test:51820',
            'allowed_ips' => '0.0.0.0/0',
            'dns'         => '10.123.50.4',
        ];
    }

    private function fakePfsense(array $peers = [], array $tunnels = ['tun_wg1', 'tun_wg2']): void
    {
        Http::fake([
            '*/system/restapi/version' => Http::response(['data' => ['current_version' => 'v2.10.2']]),
            '*/vpn/wireguard/tunnels'  => Http::response([
                'data' => array_map(fn(string $name) => ['name' => $name, 'publickey' => 'PUB='],
                    $tunnels,),
            ]),
            '*/vpn/wireguard/peers'    => Http::response(['data' => $peers]),
        ]);
    }

    private function peer(int $id, string $tunnel, string $name, string $ip): array
    {
        return ['id' => $id, 'tun' => $tunnel, 'descr' => $name, 'allowedips' => [['address' => $ip, 'mask' => 32]]];
    }

    public function test_a_healthy_setup_passes(): void
    {
        $this->fakePfsense([
            $this->peer(0, 'tun_wg1', 'anna', '10.123.99.2'),
            $this->peer(1, 'tun_wg2', 'anna', '10.123.98.2'),
        ]);

        $this->artisan('wg:doctor')
            ->assertSuccessful();
    }

    public function test_a_group_that_references_an_unknown_network_fails(): void
    {
        $this->useNetworks([['tun_wg1', 'tun_wg9']]);
        $this->fakePfsense();

        $this->artisan('wg:doctor')
            ->assertFailed();
    }

    public function test_a_config_name_that_is_too_long_fails(): void
    {
        config(['central-ui.wireguard.config_prefix' => 'family_family_']);
        $this->fakePfsense();

        $this->artisan('wg:doctor')
            ->assertFailed();
    }

    public function test_a_tunnel_that_is_missing_on_the_pfsense_fails(): void
    {
        $this->fakePfsense([], ['tun_wg1']);

        $this->artisan('wg:doctor')
            ->assertFailed();
    }

    public function test_inconsistent_host_numbers_only_produce_a_warning(): void
    {
        $this->fakePfsense([
            $this->peer(0, 'tun_wg1', 'anna', '10.123.99.4'),
            $this->peer(1, 'tun_wg2', 'bob', '10.123.98.4'),
        ]);

        $this->artisan('wg:doctor')
            ->assertSuccessful();
    }

    public function test_an_unreachable_api_fails(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Unauthorized'], 401)]);

        $this->artisan('wg:doctor')
            ->assertFailed();
    }
}
