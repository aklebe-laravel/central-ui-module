<?php

declare(strict_types = 1);

namespace Modules\CentralUi\app\Console;

use Modules\CentralUi\app\Services\Wireguard\NetworkRepository;
use Modules\CentralUi\app\Services\Wireguard\WireguardMemberService;

final class WireguardMemberSetCommand extends AbstractMemberCommand
{
    protected $signature = 'wg:member:set
        {name : Member name (letters, digits, - and _)}
        {networks : Comma-separated networks, e.g. tun_wg1,tun_wg2. "*" or "all" stands for every network that belongs to a host group (quote the asterisk in the shell)}
        {--renew : Also renew the keys of existing peers (their current client configs stop working)}';

    protected $description = 'Creates missing WireGuard peers (same host number in all networks of a group) and stores the client configs in a zip archive';

    public function handle(WireguardMemberService $members, NetworkRepository $repository): int
    {
        return $this->guarded(function () use ($members, $repository): void {
            $name = (string) $this->argument('name');
            $changed = $members->set($name, $this->networks($repository), (bool) $this->option('renew'));

            if ($changed === []) {
                $this->info("Nothing to do: '{$name}' already exists in all given networks (use --renew to renew the keys).");

                return;
            }

            $this->info("Peer {$name} updated.");

            foreach ($changed as $change) {
                $action = $change->created ? 'created' : 'key renewed';
                $this->line("  {$change->tunnel}: {$change->ip} ({$action}) -> {$change->fileName}");
            }

            $this->line("Client configs: {$changed[0]->archivePath}");
        });
    }
}
