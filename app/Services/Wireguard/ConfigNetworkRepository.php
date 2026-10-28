<?php

declare(strict_types = 1);

namespace Modules\CentralUi\app\Services\Wireguard;

use InvalidArgumentException;

/** Reads the network definitions from config('central-ui.wireguard'). */
final class ConfigNetworkRepository implements NetworkRepository
{
    private const REQUIRED_FIELDS = ['prefix', 'endpoint', 'allowed_ips', 'dns'];

    public function tunnelNames(): array
    {
        return array_map('strval', array_keys($this->definitions()));
    }

    public function find(string $tunnel): WireguardNetwork
    {
        $definitions = $this->definitions();

        if ($definitions === []) {
            throw new InvalidArgumentException('No WireGuard networks configured (central-ui.wireguard.networks is empty).');
        }

        if (!isset($definitions[$tunnel])) {
            throw new InvalidArgumentException(sprintf('Unknown network "%s" (available: %s).',
                $tunnel,
                implode(', ', array_keys($definitions)),));
        }

        $definition = $definitions[$tunnel];

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
        return array_values(array_map(fn(array $group) => array_values(array_map('strval', $group)),
            config('central-ui.wireguard.shared_host_groups', []),));
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

    /** @return array<string, array<string, mixed>> */
    private function definitions(): array
    {
        return config('central-ui.wireguard.networks', []);
    }
}
