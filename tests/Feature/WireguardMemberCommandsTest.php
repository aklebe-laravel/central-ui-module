<?php

declare(strict_types = 1);

namespace Modules\CentralUi\tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\ResponseSequence;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Modules\CentralUi\app\Services\Wireguard\ArrayNetworkRepository;
use Modules\CentralUi\app\Services\Wireguard\NetworkRepository;
use Tests\TestCase;
use ZipArchive;

/**
 * Documents which requests and responses we expect from the pfSense REST API.
 * If the real API deviates, this is where it shows first.
 */
final class WireguardMemberCommandsTest extends TestCase
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
            'central-ui.wireguard.lock_wait_seconds'      => 0,
        ]);

        $this->useNetworks();
    }

    /**
     * Binds the networks instead of the database.
     * tun_wg1 and tun_wg2 share their host numbers, tun_wg3 (e.g. a commercial VPN) stands alone.
     *
     * @param  array<string, array<string, mixed>>  $overrides  per tunnel, merged into the definition
     */
    private function useNetworks(array $overrides = []): void
    {
        $definitions = [
            'tun_wg1' => $this->network('10.123.99.', 'home.test:51821', '10.123.50.0/24, 10.123.99.0/24'),
            'tun_wg2' => $this->network('10.123.98.', 'home.test:51822', '0.0.0.0/0'),
            'tun_wg3' => $this->network('10.123.97.', 'extern.test:51823', '0.0.0.0/0'),
        ];

        foreach ($overrides as $tunnel => $values) {
            $definitions[$tunnel] = array_merge($definitions[$tunnel], $values);
        }

        $this->app->instance(NetworkRepository::class, new ArrayNetworkRepository($definitions, [['tun_wg1', 'tun_wg2']]));
    }

    private function network(string $prefix, string $endpoint, string $allowedIps): array
    {
        return [
            'prefix'      => $prefix,
            'first_host'  => 2,
            'mask'        => 24,
            'endpoint'    => $endpoint,
            'allowed_ips' => $allowedIps,
            'dns'         => '10.123.50.4, 10.123.50.5',
            'mtu'         => 1280,
        ];
    }

    private function fakePfsense(array|ResponseSequence $peers): void
    {
        Http::fake([
            '*/vpn/wireguard/peers'   => $peers instanceof ResponseSequence ? $peers : Http::response(['data' => $peers]),
            '*/vpn/wireguard/tunnels' => Http::response([
                'data' => [
                    ['name' => 'tun_wg1', 'publickey' => 'SERVERPUB1='],
                    ['name' => 'tun_wg2', 'publickey' => 'SERVERPUB2='],
                    ['name' => 'tun_wg3', 'publickey' => 'SERVERPUB3='],
                ],
            ]),
            '*/vpn/wireguard/peer'    => Http::response(['data' => ['id' => 99]]),
            '*/vpn/wireguard/apply'   => Http::response(['data' => []]),
        ]);
    }

    private function peer(int $id, string $tunnel, string $name, string $ip): array
    {
        return ['id' => $id, 'tun' => $tunnel, 'descr' => $name, 'allowedips' => [['address' => $ip, 'mask' => 32]]];
    }

    private function wasPosted(string $tunnel, string $ip): callable
    {
        return fn(Request $r) => $r->method() === 'POST'
                                 && str_ends_with($r->url(), '/vpn/wireguard/peer')
                                 && $r['tun'] === $tunnel
                                 && $r['allowedips'][0]['address'] === $ip;
    }

    private function assertNothingChanged(): void
    {
        Http::assertNotSent(fn(Request $r) => $r->method() !== 'GET');
    }

    /** @return list<string> file names inside the archive of a member */
    private function archiveEntries(string $member): array
    {
        $zip = new ZipArchive();
        $this->assertTrue($zip->open(Storage::disk('local')
                ->path("wg_configs/{$member}.zip")) === true);

        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();

        sort($names);

        return $names;
    }

    private function createArchive(string $member, array $names): void
    {
        Storage::disk('local')
            ->makeDirectory('wg_configs');

        $zip = new ZipArchive();
        $zip->open(Storage::disk('local')
            ->path("wg_configs/{$member}.zip"),
            ZipArchive::CREATE);
        foreach ($names as $name) {
            $zip->addFromString($name, 'placeholder');
        }
        $zip->close();
    }

    public function test_set_creates_a_new_member_in_several_networks_with_the_same_host_number(): void
    {
        // In the group .2 (bob) and .3 (eve) are taken, so the first free number is .4
        $this->fakePfsense([
            $this->peer(0, 'tun_wg1', 'bob', '10.123.99.2'),
            $this->peer(1, 'tun_wg1', 'eve', '10.123.99.3'),
            $this->peer(2, 'tun_wg2', 'bob', '10.123.98.2'),
        ]);

        $this->artisan('wg:member:set anna tun_wg1,tun_wg2')
            ->assertSuccessful();

        Http::assertSent($this->wasPosted('tun_wg1', '10.123.99.4'));
        Http::assertSent($this->wasPosted('tun_wg2', '10.123.98.4'));
        $this->assertSame(['family_tun_wg1.conf', 'family_tun_wg2.conf'], $this->archiveEntries('anna'));
    }

    public function test_a_host_number_of_another_member_is_blocked_in_the_whole_group(): void
    {
        // test1a only has tun_wg1 (.8). test1c must not get .8 in tun_wg2 either.
        $this->useNetworks([
            'tun_wg1' => ['first_host' => 8],
            'tun_wg2' => ['first_host' => 8],
        ]);
        $this->fakePfsense([
            $this->peer(0, 'tun_wg1', 'test1a', '10.123.99.8'),
        ]);

        $this->artisan('wg:member:set test1c tun_wg2')
            ->assertSuccessful();

        Http::assertSent($this->wasPosted('tun_wg2', '10.123.98.9'));
    }

    public function test_adding_a_network_later_works_thanks_to_the_reservation(): void
    {
        // test1c has tun_wg2 with .9 (.8 belongs to test1a), so tun_wg1 can get .9 without conflict
        $this->fakePfsense([
            $this->peer(0, 'tun_wg1', 'test1a', '10.123.99.8'),
            $this->peer(1, 'tun_wg2', 'test1c', '10.123.98.9'),
        ]);

        $this->artisan('wg:member:set test1c tun_wg1')
            ->assertSuccessful();

        Http::assertSent($this->wasPosted('tun_wg1', '10.123.99.9'));
    }

    public function test_networks_outside_the_group_assign_their_numbers_on_their_own(): void
    {
        $this->fakePfsense([
            $this->peer(0, 'tun_wg3', 'bob', '10.123.97.2'),
        ]);

        $this->artisan('wg:member:set anna tun_wg1,tun_wg3')
            ->assertSuccessful();

        Http::assertSent($this->wasPosted('tun_wg1', '10.123.99.2'));
        Http::assertSent($this->wasPosted('tun_wg3', '10.123.97.3'));
    }

    public function test_set_only_creates_missing_peers_by_default(): void
    {
        $this->fakePfsense([
            $this->peer(0, 'tun_wg1', 'anna', '10.123.99.4'),
        ]);

        $this->artisan('wg:member:set anna tun_wg1,tun_wg2')
            ->assertSuccessful();

        Http::assertNotSent(fn(Request $r) => $r->method() === 'PATCH');
        Http::assertSent($this->wasPosted('tun_wg2', '10.123.98.4'));
        $this->assertSame(['family_tun_wg2.conf'], $this->archiveEntries('anna'));
    }

    public function test_set_with_renew_renews_existing_peers_and_creates_missing_ones(): void
    {
        $this->fakePfsense([
            $this->peer(0, 'tun_wg1', 'anna', '10.123.99.4'),
            $this->peer(1, 'tun_wg2', 'bob', '10.123.98.2'),
        ]);

        $this->artisan('wg:member:set', ['name' => 'anna', 'networks' => 'tun_wg1,tun_wg2', '--renew' => true])
            ->assertSuccessful();

        Http::assertSent(fn(Request $r) => $r->method() === 'PATCH' && $r['id'] === 0 && isset($r['publickey']));
        Http::assertSent($this->wasPosted('tun_wg2', '10.123.98.4'));
        $this->assertSame(['family_tun_wg1.conf', 'family_tun_wg2.conf'], $this->archiveEntries('anna'));
    }

    public function test_set_fails_before_any_change_if_a_host_number_is_used_twice_in_the_group(): void
    {
        // Legacy data: anna (tun_wg1) and bob (tun_wg2) both use .4
        $this->fakePfsense([
            $this->peer(0, 'tun_wg1', 'anna', '10.123.99.4'),
            $this->peer(1, 'tun_wg2', 'bob', '10.123.98.4'),
        ]);

        $this->artisan('wg:member:set anna tun_wg1,tun_wg2')
            ->assertFailed();

        $this->assertNothingChanged();
    }

    public function test_an_invalid_member_name_is_rejected(): void
    {
        $this->fakePfsense([]);

        $this->artisan('wg:member:set ../evil tun_wg1')
            ->assertFailed();

        Http::assertNothingSent();
    }

    public function test_a_config_name_that_is_too_long_is_rejected_before_anything_is_sent(): void
    {
        config(['central-ui.wireguard.config_prefix' => 'family_family_']);
        $this->fakePfsense([]);

        $this->artisan('wg:member:set anna tun_wg1')
            ->assertFailed();

        Http::assertNothingSent();
    }

    public function test_set_fails_while_another_change_holds_the_lock(): void
    {
        $this->fakePfsense([]);

        $lock = Cache::lock('central-ui:wireguard', 30);
        $this->assertTrue($lock->get());

        $this->artisan('wg:member:set anna tun_wg1')
            ->assertFailed();

        Http::assertNothingSent();
        $lock->release();
    }

    public function test_an_asterisk_stands_for_all_networks_of_the_shared_host_groups(): void
    {
        $this->fakePfsense([]);

        $this->artisan('wg:member:set', ['name' => 'anna', 'networks' => '*'])
            ->assertSuccessful();

        Http::assertSent($this->wasPosted('tun_wg1', '10.123.99.2'));
        Http::assertSent($this->wasPosted('tun_wg2', '10.123.98.2'));
        // tun_wg3 is in no group and not part of "*"
        Http::assertNotSent(fn(Request $r) => $r->method() === 'POST'
                                              && str_ends_with($r->url(), '/vpn/wireguard/peer')
                                              && $r['tun'] === 'tun_wg3');
    }

    public function test_all_is_an_alias_for_the_asterisk(): void
    {
        $this->fakePfsense([]);

        $this->artisan('wg:member:set anna all')
            ->assertSuccessful();

        Http::assertSent($this->wasPosted('tun_wg1', '10.123.99.2'));
        Http::assertSent($this->wasPosted('tun_wg2', '10.123.98.2'));
    }

    public function test_remove_looks_the_peer_up_again_before_every_deletion(): void
    {
        $before = [
            $this->peer(0, 'tun_wg1', 'anna', '10.123.99.2'),
            $this->peer(1, 'tun_wg1', 'bob', '10.123.99.3'),
            $this->peer(2, 'tun_wg2', 'anna', '10.123.98.2'),
        ];
        // After the first deletion the list indexes have shifted
        $afterFirstDeletion = [
            $this->peer(0, 'tun_wg1', 'bob', '10.123.99.3'),
            $this->peer(1, 'tun_wg2', 'anna', '10.123.98.2'),
        ];

        $this->fakePfsense(Http::sequence()
            ->push(['data' => $before])
            ->push(['data' => $afterFirstDeletion])
            ->whenEmpty(Http::response(['data' => []])),);

        $this->artisan('wg:member:remove anna tun_wg1,tun_wg2')
            ->assertSuccessful();

        $ids = Http::recorded()
            ->filter(fn(array $pair) => $pair[0]->method() === 'DELETE')
            ->map(fn(array $pair) => $pair[0]['id'])
            ->values()
            ->all();

        $this->assertSame([0, 1], $ids);
    }

    public function test_remove_removes_the_configs_from_the_archive(): void
    {
        $this->fakePfsense([
            $this->peer(0, 'tun_wg1', 'anna', '10.123.99.2'),
            $this->peer(1, 'tun_wg2', 'anna', '10.123.98.2'),
        ]);
        $this->createArchive('anna', ['family_tun_wg1.conf', 'family_tun_wg2.conf']);

        $this->artisan('wg:member:remove anna tun_wg1')
            ->assertSuccessful();
        $this->assertSame(['family_tun_wg2.conf'], $this->archiveEntries('anna'));

        $this->artisan('wg:member:remove anna tun_wg2')
            ->assertSuccessful();
        Storage::disk('local')
            ->assertMissing('wg_configs/anna.zip');
    }

    public function test_remove_continues_if_a_network_has_no_peer(): void
    {
        $this->fakePfsense([
            $this->peer(0, 'tun_wg1', 'anna', '10.123.99.2'),
        ]);

        $this->artisan('wg:member:remove anna tun_wg1,tun_wg2')
            ->assertSuccessful();

        $deletes = Http::recorded()
            ->filter(fn(array $pair) => $pair[0]->method() === 'DELETE');
        $this->assertCount(1, $deletes);
    }

    public function test_remove_fails_if_the_member_exists_in_none_of_the_networks(): void
    {
        $this->fakePfsense([]);

        $this->artisan('wg:member:remove anna tun_wg1,tun_wg2')
            ->assertFailed();

        Http::assertNotSent(fn(Request $r) => $r->method() === 'DELETE');
    }
}
