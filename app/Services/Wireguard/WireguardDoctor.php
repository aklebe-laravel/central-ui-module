<?php

declare(strict_types = 1);

namespace Modules\CentralUi\app\Services\Wireguard;

use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Modules\CentralUi\app\Services\Pfsense\PfsenseApi;
use RuntimeException;
use Throwable;
use ZipArchive;

/** Diagnoses the environment, the network config, the storage and the pfSense side. */
final class WireguardDoctor
{
    public function __construct(
        private readonly NetworkRepository $networks,
        private readonly PfsenseApi $api,
        private readonly MemberConfigArchive $archive,
        private readonly HostNumberAllocator $hostNumbers,
    ) {
    }

    /** @return list<DoctorResult> */
    public function run(): array
    {
        return [
            ...$this->checkEnvironment(),
            ...$this->checkNetworkConfig(),
            ...$this->checkStorage(),
            ...$this->checkPfsense(),
        ];
    }

    /** @return list<DoctorResult> */
    private function checkEnvironment(): array
    {
        $results = [];

        $results[] = function_exists('sodium_crypto_scalarmult_base') ? DoctorResult::ok('PHP sodium', 'Extension available (key generation)') : DoctorResult::fail('PHP sodium', 'Extension missing, keys cannot be generated');

        $results[] = class_exists(ZipArchive::class) ? DoctorResult::ok('PHP zip', 'Extension available (config archives)') : DoctorResult::fail('PHP zip', 'Extension missing, config archives cannot be created');

        try {
            $lock = Cache::lock('central-ui:doctor', 5);

            if ($lock->get()) {
                $lock->release();
                $results[] = DoctorResult::ok('Cache locks', 'The cache store supports locks');
            } else {
                $results[] = DoctorResult::warn('Cache locks', 'Test lock could not be acquired');
            }
        } catch (Throwable $e) {
            $results[] = DoctorResult::fail('Cache locks', 'The cache store does not support locks: '.$e->getMessage());
        }

        return $results;
    }

    /** @return list<DoctorResult> */
    private function checkNetworkConfig(): array
    {
        $names = $this->networks->tunnelNames();

        if ($names === []) {
            return [DoctorResult::fail('Networks', 'No networks configured (Admin > Proxmox > WireGuard Networks)')];
        }

        $results = [];
        $prefixes = [];

        foreach ($names as $tunnel) {
            try {
                $network = $this->networks->find($tunnel);
            } catch (InvalidArgumentException $e) {
                $results[] = DoctorResult::fail("Network {$tunnel}", $e->getMessage());

                continue;
            }

            $problems = $this->networkProblems($network);

            if ($problems === []) {
                $results[] = DoctorResult::ok("Network {$tunnel}",
                    sprintf('%s0/%d, endpoint %s, config %s', $network->prefix, $network->mask, $network->endpoint, $this->archive->fileName($tunnel)),);
            }

            foreach ($problems as $problem) {
                $results[] = DoctorResult::fail("Network {$tunnel}", $problem);
            }

            $prefixes[$network->prefix][] = $tunnel;
        }

        foreach ($prefixes as $prefix => $tunnels) {
            if (count($tunnels) > 1) {
                $results[] = DoctorResult::fail('Network prefixes', "Prefix {$prefix} is used by several networks: ".implode(', ', $tunnels));
            }
        }

        $grouped = [];

        foreach ($this->networks->groups() as $group) {
            foreach ($group as $tunnel) {
                if (!in_array($tunnel, $names, true)) {
                    $results[] = DoctorResult::fail('Host groups', "A host group references the unknown network {$tunnel}");
                }

                $grouped[$tunnel] = ($grouped[$tunnel] ?? 0) + 1;
            }

            if (count($group) < 2) {
                $results[] = DoctorResult::warn('Host groups', 'A group with a single network has no effect: '.implode(', ', $group));
            }
        }

        foreach ($grouped as $tunnel => $count) {
            if ($count > 1) {
                $results[] = DoctorResult::fail('Host groups', "Network {$tunnel} is part of {$count} groups");
            }
        }

        return $results;
    }

    /** @return list<string> */
    private function networkProblems(WireguardNetwork $network): array
    {
        $problems = [];

        if (!preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}\.$/', $network->prefix)) {
            $problems[] = "Prefix \"{$network->prefix}\" must look like \"10.0.99.\"";
        }

        if ($network->firstHost < 1 || $network->firstHost > 254) {
            $problems[] = "first_host {$network->firstHost} is outside 1-254";
        }

        try {
            $this->archive->assertFileNameValid($network->tunnel);
        } catch (InvalidArgumentException $e) {
            $problems[] = $e->getMessage();
        }

        return $problems;
    }

    /** @return list<DoctorResult> */
    private function checkStorage(): array
    {
        try {
            $this->archive->assertWritable();

            return [DoctorResult::ok('Config storage', 'The configured disk is writable')];
        } catch (Throwable $e) {
            return [DoctorResult::fail('Config storage', 'The configured disk is not writable: '.$e->getMessage())];
        }
    }

    /** @return list<DoctorResult> */
    private function checkPfsense(): array
    {
        try {
            $pfsenseTunnels = $this->api->tunnels();
        } catch (RuntimeException $e) {
            return [DoctorResult::fail('pfSense API', $e->getMessage())];
        }

        $results = [DoctorResult::ok('pfSense API', 'Reachable and authenticated')];

        try {
            $version = $this->api->restApiVersion();

            $results[] = $version !== null ? DoctorResult::ok('REST API version', "Package version {$version}") : DoctorResult::warn('REST API version', 'The API did not report its version');
        } catch (RuntimeException $e) {
            $results[] = DoctorResult::warn('REST API version', 'Could not be determined: '.$e->getMessage());
        }

        $existing = array_column($pfsenseTunnels, 'name');
        $defined = $this->definedNetworks();

        foreach ($defined as $network) {
            $results[] = in_array($network->tunnel, $existing, true) ? DoctorResult::ok("Tunnel {$network->tunnel}", 'Exists on the pfSense') : DoctorResult::fail("Tunnel {$network->tunnel}", 'Does not exist on the pfSense');
        }

        try {
            $peers = $this->api->peers();
        } catch (RuntimeException $e) {
            return [...$results, DoctorResult::fail('Peers', $e->getMessage())];
        }

        foreach ($defined as $network) {
            array_push($results, ...$this->peerChecks($network, $peers));
        }

        foreach ($this->networks->groups() as $group) {
            $networks = array_values(array_filter($defined, fn(WireguardNetwork $n) => in_array($n->tunnel, $group, true)));

            foreach ($this->hostNumbers->problems($peers, $networks) as $problem) {
                $results[] = DoctorResult::warn('Host numbers ('.implode(', ', $group).')', $problem);
            }
        }

        return $results;
    }

    /**
     * @param  list<array<string, mixed>>  $peers
     *
     * @return list<DoctorResult>
     */
    private function peerChecks(WireguardNetwork $network, array $peers): array
    {
        $results = [];
        $ips = [];
        $count = 0;

        foreach ($peers as $peer) {
            if (($peer['tun'] ?? null) !== $network->tunnel) {
                continue;
            }

            $count++;

            if (($peer['descr'] ?? '') === '') {
                $results[] = DoctorResult::warn("Peers {$network->tunnel}", 'A peer without description cannot be managed by this module');
            }

            foreach ($peer['allowedips'] ?? [] as $allowed) {
                $address = (string) $allowed['address'];

                if ($network->hostOf($address) === null) {
                    $results[] = DoctorResult::warn("Peers {$network->tunnel}", "Peer '".($peer['descr'] ?? '')."' has {$address} outside {$network->prefix}0");
                }

                $ips[$address][] = (string) ($peer['descr'] ?? '');
            }
        }

        foreach ($ips as $address => $names) {
            if (count($names) > 1) {
                $results[] = DoctorResult::fail("Peers {$network->tunnel}", "Address {$address} is used by several peers: ".implode(', ', $names));
            }
        }

        if ($results === []) {
            $results[] = DoctorResult::ok("Peers {$network->tunnel}", "{$count} peer(s), no problems found");
        }

        return $results;
    }

    /** @return list<WireguardNetwork> networks that are completely configured */
    private function definedNetworks(): array
    {
        $networks = [];

        foreach ($this->networks->tunnelNames() as $tunnel) {
            try {
                $networks[] = $this->networks->find($tunnel);
            } catch (InvalidArgumentException) {
                // reported by checkNetworkConfig()
            }
        }

        return $networks;
    }
}
