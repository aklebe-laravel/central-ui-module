<?php

declare(strict_types = 1);

namespace Modules\CentralUi\app\Services\Wireguard;

use RuntimeException;

/**
 * Assigns host numbers (last IP octet) within a group of networks that share their numbers.
 *
 * A number that is used in any network of the group is reserved for that member in the whole group
 * and blocked for every other member, even in networks where the member has no peer (yet).
 */
final class HostNumberAllocator
{
    /**
     * Host number for a member in a group: the number the member already has in the group,
     * otherwise the smallest number that is free in the whole group.
     *
     * @param  list<array<string, mixed>>  $peers
     * @param  list<WireguardNetwork>      $group
     */
    public function hostFor(string $member, array $peers, array $group): int
    {
        $owners = $this->owners($peers, $group);
        $label = $this->label($group);

        $own = array_keys(array_filter($owners, fn(array $names) => isset($names[$member])));

        if (count($own) > 1) {
            throw new RuntimeException(sprintf("'%s' has different host numbers in the group (%s): %s. Please clean up with wg:member:remove first.",
                $member,
                $label,
                implode(', ', $own),));
        }

        if (count($own) === 1) {
            $host = (int) $own[0];
            $others = array_diff(array_keys($owners[$host]), [$member]);

            if ($others !== []) {
                throw new RuntimeException(sprintf("Host number .%d of '%s' is also used by %s in the group (%s). Please clean up with wg:member:remove first.",
                    $host,
                    $member,
                    implode(', ', $others),
                    $label,));
            }

            return $host;
        }

        $start = max(array_map(fn(WireguardNetwork $network) => $network->firstHost, $group));

        for ($host = $start; $host <= 254; $host++) {
            if (!isset($owners[$host])) {
                return $host;
            }
        }

        throw new RuntimeException("No free host number left in the group ({$label}).");
    }

    /**
     * Inconsistencies among the existing peers of a group (used by the doctor).
     *
     * @param  list<array<string, mixed>>  $peers
     * @param  list<WireguardNetwork>      $group
     *
     * @return list<string>
     */
    public function problems(array $peers, array $group): array
    {
        $problems = [];
        $hostsByMember = [];

        foreach ($this->owners($peers, $group) as $host => $names) {
            if (count($names) > 1) {
                $problems[] = sprintf('Host number .%d is used by several members: %s', $host, implode(', ', array_keys($names)));
            }

            foreach (array_keys($names) as $name) {
                $hostsByMember[$name][] = $host;
            }
        }

        foreach ($hostsByMember as $name => $hosts) {
            if (count($hosts) > 1) {
                $problems[] = sprintf("Member '%s' has different host numbers in the group: %s", $name, implode(', ', $hosts));
            }
        }

        return $problems;
    }

    /**
     * @param  list<array<string, mixed>>  $peers
     * @param  list<WireguardNetwork>      $group
     *
     * @return array<int, array<string, true>> host number => names of the members using it in the group
     */
    private function owners(array $peers, array $group): array
    {
        $owners = [];

        foreach ($group as $network) {
            foreach ($peers as $peer) {
                if (($peer['tun'] ?? null) !== $network->tunnel) {
                    continue;
                }

                foreach ($peer['allowedips'] ?? [] as $allowed) {
                    $host = $network->hostOf((string) $allowed['address']);

                    if ($host !== null) {
                        $owners[$host][(string) ($peer['descr'] ?? '')] = true;
                    }
                }
            }
        }

        return $owners;
    }

    /** @param  list<WireguardNetwork>  $group */
    private function label(array $group): string
    {
        return implode(', ', array_map(fn(WireguardNetwork $network) => $network->tunnel, $group));
    }
}
