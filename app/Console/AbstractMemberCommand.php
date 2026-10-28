<?php

declare(strict_types = 1);

namespace Modules\CentralUi\app\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Modules\CentralUi\app\Services\Wireguard\NetworkRepository;
use Modules\CentralUi\app\Services\Wireguard\WireguardNetwork;
use RuntimeException;

abstract class AbstractMemberCommand extends Command
{
    /** Placeholders for "all networks that belong to a host group". */
    private const ALL_NETWORKS = ['*', 'all'];

    /**
     * Reads the "networks" argument (comma separated tunnel names, e.g. "tun_wg1,tun_wg2").
     * "*" or "all" stands for all networks that belong to a host group and can be combined with other networks.
     *
     * Shell caveat: an unquoted * is expanded to file names by the shell. Quote it or use "all".
     *
     * @return list<WireguardNetwork>
     */
    protected function networks(NetworkRepository $repository): array
    {
        $tokens = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) $this->argument('networks'))))));

        $networks = [];

        foreach ($tokens as $token) {
            $expanded = in_array($token, self::ALL_NETWORKS, true) ? $repository->inSharedGroups() : [$repository->find($token)];

            foreach ($expanded as $network) {
                $networks[$network->tunnel] = $network;
            }
        }

        if ($networks === []) {
            throw new InvalidArgumentException('No networks given (e.g. tun_wg1 or tun_wg1,tun_wg2; "*" or "all" = all networks of the host groups).',);
        }

        return array_values($networks);
    }

    /**
     * Runs the action and turns expected errors into a clean message.
     * If the action returns an int, it is used as exit code, otherwise SUCCESS.
     */
    protected function guarded(callable $action): int
    {
        try {
            $result = $action();

            return is_int($result) ? $result : self::SUCCESS;
        } catch (RuntimeException|InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
