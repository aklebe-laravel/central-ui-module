<?php

declare(strict_types = 1);

namespace Modules\CentralUi\app\Services\Wireguard;

use InvalidArgumentException;

/**
 * Source of the WireGuard network definitions.
 *
 * Bound to DatabaseNetworkRepository (table proxtop_wireguard_networks).
 * The tests use ArrayNetworkRepository.
 */
interface NetworkRepository
{
    /** @return list<string> names (= pfSense tunnel names) of all defined networks */
    public function tunnelNames(): array;

    /** @throws InvalidArgumentException if the network is unknown or incompletely defined */
    public function find(string $tunnel): WireguardNetwork;

    /** @return list<WireguardNetwork> */
    public function all(): array;

    /** @return list<list<string>> groups of tunnel names that share their host numbers */
    public function groups(): array;

    /** @return list<WireguardNetwork> all networks sharing host numbers with $network (including itself) */
    public function hostGroup(WireguardNetwork $network): array;

    /** @return list<WireguardNetwork> every network that belongs to a shared host group */
    public function inSharedGroups(): array;
}
