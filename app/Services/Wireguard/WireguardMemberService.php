<?php

declare(strict_types = 1);

namespace Modules\CentralUi\app\Services\Wireguard;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Modules\CentralUi\app\Services\Pfsense\PfsenseApi;
use Modules\SystemBase\app\Services\Base\BaseService;
use RuntimeException;

final class WireguardMemberService extends BaseService
{
    private const LOCK_NAME = 'central-ui:wireguard';

    /** Cache key of the peer list used for overviews (not for changes, they always read fresh). */
    private const PEERS_CACHE_KEY = 'central-ui:wireguard:peers';

    public function __construct(
        private readonly PfsenseApi $api,
        private readonly WireguardKeyGenerator $keys,
        private readonly MemberConfigArchive $archive,
        private readonly NetworkRepository $networks,
        private readonly HostNumberAllocator $hostNumbers,
    ) {
        parent::__construct();
    }

    /**
     * Creates the member in the given networks. Where the peer already exists nothing happens,
     * unless $renewExisting is set: then the key is renewed (the old client config stops working).
     *
     * Inside a group of networks (same host_group) the member always has the same host number
     * (last IP octet), and that number is blocked for all other members in the whole group.
     *
     * Changes are serialized with a lock, so parallel requests cannot assign the same number.
     *
     * @param  list<WireguardNetwork>  $networks
     *
     * @return list<MemberConfig> only the networks that were changed
     */
    public function set(string $member, array $networks, bool $renewExisting = false): array
    {
        $this->assertValidMember($member);

        // Config problems must surface before anything is changed on the pfSense.
        foreach ($networks as $network) {
            $this->archive->assertFileNameValid($network->tunnel);
        }

        try {
            return $this->locked(fn(): array => $this->applySet($member, $networks, $renewExisting));
        } finally {
            // also after a failure: some changes may already be done on the pfSense
            $this->forgetCachedPeers();
        }
    }

    /**
     * Deletes the member from the given networks. Networks without a peer for the member are skipped;
     * stored client configs are removed in any case.
     *
     * @param  list<WireguardNetwork>  $networks
     *
     * @return array{removed: list<string>, missing: list<string>} tunnel names
     */
    public function remove(string $member, array $networks): array
    {
        $this->assertValidMember($member);

        try {
            return $this->locked(fn(): array => $this->applyRemove($member, $networks));
        } finally {
            $this->forgetCachedPeers();
        }
    }

    /** @return list<array{tunnel: string, member: string, ip: string}> */
    public function list(?WireguardNetwork $network = null): array
    {
        $rows = [];

        foreach ($this->api->peers() as $peer) {
            if ($network !== null && ($peer['tun'] ?? null) !== $network->tunnel) {
                continue;
            }

            $rows[] = [
                'tunnel' => (string) $peer['tun'],
                'member' => (string) ($peer['descr'] ?? ''),
                'ip'     => $this->peerIp($peer),
            ];
        }

        return $rows;
    }

    /**
     * All members of the configured networks, one row per member (used by the frontend).
     * Peers of other tunnels (e.g. a commercial VPN) and peers without a valid member name are left out.
     *
     * The peer list is cached for a few seconds (central-ui.wireguard.list_cache_seconds),
     * because a data table asks for it on every interaction. set() and remove() clear the cache.
     *
     * @return list<array{member: string, networks: list<array{tunnel: string, ip: string}>, has_configs: bool}>
     */
    public function overview(bool $fresh = false): array
    {
        $tunnels = $this->networks->tunnelNames();
        $members = [];

        foreach ($this->cachedPeers($fresh) as $peer) {
            $tunnel = (string) ($peer['tun'] ?? '');
            $member = (string) ($peer['descr'] ?? '');

            if (!in_array($tunnel, $tunnels, true) || !$this->isValidMember($member)) {
                continue;
            }

            $members[$member] ??= ['member' => $member, 'networks' => [], 'has_configs' => false];
            $members[$member]['networks'][] = ['tunnel' => $tunnel, 'ip' => $this->peerIp($peer)];
        }

        foreach ($members as &$row) {
            // same order as the network definitions
            usort($row['networks'], fn(array $a, array $b) => array_search($a['tunnel'], $tunnels, true) <=> array_search($b['tunnel'], $tunnels, true));
            $row['has_configs'] = $this->archive->exists($row['member']);
        }
        unset($row);

        ksort($members, SORT_NATURAL | SORT_FLAG_CASE);

        return array_values($members);
    }

    /** One member of overview(), or null if the member has no peer in the configured networks. */
    public function member(string $member, bool $fresh = false): ?array
    {
        foreach ($this->overview($fresh) as $row) {
            if ($row['member'] === $member) {
                return $row;
            }
        }

        return null;
    }

    public function forgetCachedPeers(): void
    {
        Cache::forget(self::PEERS_CACHE_KEY);
    }

    public function isValidMember(string $member): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,62}$/', $member);
    }

    /** The member name becomes part of a file path, so it is validated strictly. */
    public function assertValidMember(string $member): void
    {
        if (!$this->isValidMember($member)) {
            throw new RuntimeException('Invalid member name. Allowed: letters, digits, "-" and "_" (max. 63 characters).');
        }
    }

    /** @param  list<WireguardNetwork>  $networks */
    private function applySet(string $member, array $networks, bool $renewExisting): array
    {
        $peers = $this->api->peers();

        $existing = [];
        $missing = [];

        foreach ($networks as $network) {
            if ($this->findPeer($peers, $network, $member) === null) {
                $missing[] = $network;
            } else {
                $existing[] = $network;
            }
        }

        $toRenew = $renewExisting ? $existing : [];
        $touched = array_merge($toRenew, $missing);

        if ($touched === []) {
            return [];
        }

        $hostNumbers = $this->hostNumbersForNewPeers($member, $peers, $missing);
        $serverKeys = $this->serverKeys($touched); // up front, so a missing tunnel surfaces before any change

        $prepared = []; // list<array{0: WireguardNetwork, 1: string, 2: string, 3: bool}> [network, ip, private key, created]
        $done = [];

        try {
            // Renew first, then create: creating appends to the list and does not shift IDs.
            foreach ($toRenew as $network) {
                // IDs are list indexes: look the peer up again right before the change.
                $peer = $this->requirePeer($this->api->peers(), $network, $member);
                $keyPair = $this->keys->generate();

                $this->api->updatePeerKey((int) $peer['id'], $keyPair['public']);
                $done[] = "{$network->tunnel} (key renewed)";
                $prepared[] = [$network, $this->peerIp($peer), $keyPair['private'], false];
            }

            foreach ($missing as $network) {
                $keyPair = $this->keys->generate();
                $ip = $network->prefix.$hostNumbers[$network->tunnel];

                $this->api->createPeer($network->tunnel, $member, $keyPair['public'], $ip);
                $done[] = "{$network->tunnel} (created)";
                $prepared[] = [$network, $ip, $keyPair['private'], true];
            }

            $this->api->applyChanges();

            $result = $this->storeConfigs($member, $prepared, $serverKeys);
        } catch (RuntimeException $e) {
            $hint = $done !== [] ? ' Already done on the pfSense: '.implode(', ', $done).'. Check with wg:member:list; running wg:member:set again is safe.' : '';

            $this->error('WireGuard member update failed', ['member' => $member, 'done' => $done, 'error' => $e->getMessage()]);

            throw new RuntimeException($e->getMessage().$hint, previous: $e);
        }

        foreach ($result as $change) {
            $this->info($change->created ? 'WireGuard peer created' : 'WireGuard peer key renewed', ['member' => $member, 'tunnel' => $change->tunnel, 'ip' => $change->ip]);
        }

        return $result;
    }

    /** @param  list<WireguardNetwork>  $networks */
    private function applyRemove(string $member, array $networks): array
    {
        $removed = [];
        $missing = [];

        try {
            foreach ($networks as $network) {
                // Fresh list for every deletion: IDs are list indexes and shift after each delete.
                $peer = $this->findPeer($this->api->peers(), $network, $member);

                if ($peer === null) {
                    $missing[] = $network->tunnel;

                    continue;
                }

                $this->api->deletePeer((int) $peer['id']);
                $removed[] = $network->tunnel;
            }

            if ($removed !== []) {
                $this->api->applyChanges();
            }
        } catch (RuntimeException $e) {
            $hint = $removed !== [] ? ' Already deleted on the pfSense: '.implode(', ', $removed).'. Check with wg:member:list.' : '';

            $this->error('WireGuard member removal failed', ['member' => $member, 'removed' => $removed, 'error' => $e->getMessage()]);

            throw new RuntimeException($e->getMessage().$hint, previous: $e);
        }

        $this->archive->remove($member,
            array_map(fn(WireguardNetwork $network) => $this->archive->fileName($network->tunnel), $networks),);

        foreach ($removed as $tunnel) {
            $this->info('WireGuard peer removed', ['member' => $member, 'tunnel' => $tunnel]);
        }
        foreach ($missing as $tunnel) {
            $this->warning('WireGuard peer to remove was not found', ['member' => $member, 'tunnel' => $tunnel]);
        }

        return ['removed' => $removed, 'missing' => $missing];
    }

    /** @return list<array<string, mixed>> */
    private function cachedPeers(bool $fresh): array
    {
        $seconds = (int) config('central-ui.wireguard.list_cache_seconds', 30);

        if ($fresh || $seconds <= 0) {
            $peers = $this->api->peers();

            if ($seconds > 0) {
                Cache::put(self::PEERS_CACHE_KEY, $peers, $seconds);
            }

            return $peers;
        }

        return Cache::remember(self::PEERS_CACHE_KEY, $seconds, fn(): array => $this->api->peers());
    }

    private function locked(callable $callback): mixed
    {
        try {
            return Cache::lock(self::LOCK_NAME, 60)
                ->block((int) config('central-ui.wireguard.lock_wait_seconds', 10), $callback);
        } catch (LockTimeoutException) {
            throw new RuntimeException('Another WireGuard change is in progress. Please try again in a moment.');
        }
    }

    /**
     * @param  list<array{0: WireguardNetwork, 1: string, 2: string, 3: bool}>  $prepared
     * @param  array<string, string>                                            $serverKeys
     *
     * @return list<MemberConfig>
     */
    private function storeConfigs(string $member, array $prepared, array $serverKeys): array
    {
        $files = [];

        foreach ($prepared as [$network, $ip, $privateKey]) {
            $files[$this->archive->fileName($network->tunnel)] = $this->renderClientConfig($network,
                $ip,
                $privateKey,
                $serverKeys[$network->tunnel],);
        }

        $archivePath = $this->archive->add($member, $files);

        $result = [];

        foreach ($prepared as [$network, $ip, , $created]) {
            $result[] = new MemberConfig($member, $network->tunnel, $ip, $this->archive->fileName($network->tunnel), $archivePath, $created,);
        }

        return $result;
    }

    /**
     * Host numbers for new peers, per network. Networks of one group get the same number.
     *
     * @param  list<array<string, mixed>>  $peers
     * @param  list<WireguardNetwork>      $missing
     *
     * @return array<string, int> tunnel => host number
     */
    private function hostNumbersForNewPeers(string $member, array $peers, array $missing): array
    {
        $numbers = [];
        $perGroup = [];

        foreach ($missing as $network) {
            $group = $this->networks->hostGroup($network);
            $groupId = implode(',', array_map(fn(WireguardNetwork $n) => $n->tunnel, $group));

            $perGroup[$groupId] ??= $this->hostNumbers->hostFor($member, $peers, $group);
            $numbers[$network->tunnel] = $perGroup[$groupId];
        }

        return $numbers;
    }

    /**
     * @param  list<WireguardNetwork>  $networks
     *
     * @return array<string, string> tunnel => public key of the pfSense tunnel
     */
    private function serverKeys(array $networks): array
    {
        $keys = [];

        foreach ($networks as $network) {
            $keys[$network->tunnel] ??= $this->api->tunnelPublicKey($network->tunnel);
        }

        return $keys;
    }

    /** @param  list<array<string, mixed>>  $peers */
    private function findPeer(array $peers, WireguardNetwork $network, string $member): ?array
    {
        $matches = array_values(array_filter($peers, fn(array $peer) => ($peer['tun'] ?? null) === $network->tunnel && ($peer['descr'] ?? null) === $member));

        if (count($matches) > 1) {
            throw new RuntimeException(sprintf("Peer name '%s' is ambiguous in %s (%d peers share it). Fix this on the pfSense first.",
                $member,
                $network->tunnel,
                count($matches),));
        }

        return $matches[0] ?? null;
    }

    /** @param  list<array<string, mixed>>  $peers */
    private function requirePeer(array $peers, WireguardNetwork $network, string $member): array
    {
        return $this->findPeer($peers, $network, $member) ?? throw new RuntimeException("'{$member}' not found in {$network->tunnel} (changed in the meantime?).");
    }

    private function peerIp(array $peer): string
    {
        return (string) ($peer['allowedips'][0]['address'] ?? '');
    }

    private function renderClientConfig(WireguardNetwork $network, string $ip, string $privateKey, string $serverPublicKey): string
    {
        return implode("\n", [
                '[Interface]',
                "PrivateKey = {$privateKey}",
                "Address = {$ip}/{$network->mask}",
                "DNS = {$network->dns}",
                "MTU = {$network->mtu}",
                '',
                '[Peer]',
                "PublicKey = {$serverPublicKey}",
                "Endpoint = {$network->endpoint}",
                "AllowedIPs = {$network->allowedIps}",
                'PersistentKeepalive = 25',
            ])."\n";
    }
}
