<?php

declare(strict_types = 1);

namespace Modules\CentralUi\app\Services\Wireguard;

use InvalidArgumentException;

/**
 * Network definitions from plain arrays.
 *
 * Used by DatabaseNetworkRepository (rows of proxtop_wireguard_networks) and directly by the tests.
 *
 * Definition per tunnel (key = pfSense tunnel name, e.g. "tun_wg1"):
 * required: prefix, endpoint, allowed_ips, dns; optional: first_host (2), mask (24), mtu (1280).
 */
class ArrayNetworkRepository implements NetworkRepository
{
    private const array REQUIRED_FIELDS = ['prefix', 'endpoint', 'allowed_ips', 'dns'];

    /**
     * @param  array<string, array<string, mixed>>  $definitions  ordered like they should be listed
     * @param  list<list<string>>                   $groups       tunnel names that share their host numbers
     */
    public function __construct(
        private readonly array $definitions,
        private readonly array $groups = [],
    ) {
    }

    public function tunnelNames(): array
    {
        return array_map('strval', array_keys($this->definitions));
    }

    public function find(string $tunnel): WireguardNetwork
    {
        if ($this->definitions === []) {
            throw new InvalidArgumentException('No WireGuard networks configured (Admin > Proxmox > WireGuard Networks).');
        }

        if (!isset($this->definitions[$tunnel])) {
            throw new InvalidArgumentException(sprintf('Unknown network "%s" (available: %s).',
                $tunnel,
                implode(', ', array_keys($this->definitions)),));
        }

        $definition = $this->definitions[$tunnel];

        $missing = array_filter(self::REQUIRED_FIELDS, fn(string $field) => ($definition[$field] ?? null) === null || $definition[$field] === '');

        if ($missing !== []) {
            throw new InvalidArgumentException(sprintf('Network "%s" is incompletely configured (missing: %s).',
                $tunnel,
                implode(', ', $missing),));
        }

        return new WireguardNetwork(tunnel: $tunnel,
            prefix: (string) $definition['prefix'],
            firstHost: (int) ($definition['first_host'] ?? 2),
            mask: (int) ($definition['mask'] ?? 24),
            endpoint: (string) $definition['endpoint'],
            allowedIps: (string) $definition['allowed_ips'],
            dns: (string) $definition['dns'],
            mtu: (int) ($definition['mtu'] ?? 1280),);
    }

    public function all(): array
    {
        return array_map(fn(string $tunnel) => $this->find($tunnel), $this->tunnelNames());
    }

    public function groups(): array
    {
        return array_values(array_map(fn(array $group) => array_values(array_map('strval', $group)), $this->groups));
    }

    public function hostGroup(WireguardNetwork $network): array
    {
        foreach ($this->groups() as $group) {
            if (in_array($network->tunnel, $group, true)) {
                return array_map(fn(string $tunnel) => $this->find($tunnel), $group);
            }
        }

        return [$network];
    }

    public function inSharedGroups(): array
    {
        $tunnels = array_unique(array_merge([], ...$this->groups()));

        return array_map(fn(string $tunnel) => $this->find($tunnel), array_values($tunnels));
    }
}
