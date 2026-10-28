<?php

declare(strict_types = 1);

namespace Modules\CentralUi\app\Services\Wireguard;

use Modules\CentralUi\app\Models\ProxtopWireguardNetwork;

/**
 * Reads the network definitions from the table proxtop_wireguard_networks
 * (admin frontend: Admin > Proxmox > WireGuard Networks, or a business import).
 *
 * Networks with the same host_group share their host numbers (last IP octet).
 *
 * The rows are read once per instance. The container binding is not a singleton,
 * so long-running processes (queue workers, scheduler) see changes on the next resolve.
 */
final class DatabaseNetworkRepository implements NetworkRepository
{
    private ?ArrayNetworkRepository $loaded = null;

    public function tunnelNames(): array
    {
        return $this->repository()
            ->tunnelNames();
    }

    public function find(string $tunnel): WireguardNetwork
    {
        return $this->repository()
            ->find($tunnel);
    }

    public function all(): array
    {
        return $this->repository()
            ->all();
    }

    public function groups(): array
    {
        return $this->repository()
            ->groups();
    }

    public function hostGroup(WireguardNetwork $network): array
    {
        return $this->repository()
            ->hostGroup($network);
    }

    public function inSharedGroups(): array
    {
        return $this->repository()
            ->inSharedGroups();
    }

    private function repository(): ArrayNetworkRepository
    {
        if ($this->loaded !== null) {
            return $this->loaded;
        }

        $definitions = [];
        $groups = [];

        foreach (
            ProxtopWireguardNetwork::query()
                ->ordered()
                ->get() as $row
        ) {
            $definitions[$row->tunnel] = [
                'prefix'      => $row->prefix,
                'first_host'  => $row->first_host,
                'mask'        => $row->mask,
                'endpoint'    => $row->endpoint,
                'allowed_ips' => $row->allowed_ips,
                'dns'         => $row->dns,
                'mtu'         => $row->mtu,
            ];

            if ($row->host_group !== null && $row->host_group !== '') {
                $groups[$row->host_group][] = $row->tunnel;
            }
        }

        return $this->loaded = new ArrayNetworkRepository($definitions, array_values($groups));
    }
}
