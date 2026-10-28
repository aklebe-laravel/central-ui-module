<?php

declare(strict_types = 1);

namespace Modules\CentralUi\app\Console;

use Modules\CentralUi\app\Services\Wireguard\NetworkRepository;
use Modules\CentralUi\app\Services\Wireguard\WireguardMemberService;

final class WireguardMemberRemoveCommand extends AbstractMemberCommand
{
    protected $signature = 'wg:member:remove
        {name : Member name}
        {networks : Comma-separated networks. "*" or "all" stands for every network in shared_host_groups (quote the asterisk in the shell)}';

    protected $description = 'Deletes WireGuard peers from the pfSense and their stored client configs; networks without a peer are skipped';

    public function handle(WireguardMemberService $members, NetworkRepository $repository): int
    {
        return $this->guarded(function () use ($members, $repository): int {
            $name = (string) $this->argument('name');
            $result = $members->remove($name, $this->networks($repository));

            foreach ($result['removed'] as $tunnel) {
                $this->line("  {$tunnel}: deleted");
            }
            foreach ($result['missing'] as $tunnel) {
                $this->warn("  {$tunnel}: '{$name}' not found (skipped)");
            }

            if ($result['removed'] === []) {
                $this->error("'{$name}' was not found in any of the given networks.");

                return self::FAILURE;
            }

            $this->info("Peer {$name} deleted.");

            return self::SUCCESS;
        });
    }
}
