<?php

declare(strict_types = 1);

namespace Modules\CentralUi\app\Console;

use Modules\CentralUi\app\Services\Wireguard\NetworkRepository;
use Modules\CentralUi\app\Services\Wireguard\WireguardMemberService;

final class WireguardMemberListCommand extends AbstractMemberCommand
{
    protected $signature = 'wg:member:list
        {network? : Optionally restrict the list to one network (tunnel name)}';

    protected $description = 'Lists the WireGuard peers of the pfSense';

    public function handle(WireguardMemberService $members, NetworkRepository $repository): int
    {
        return $this->guarded(function () use ($members, $repository): void {
            $network = $this->argument('network') ? $repository->find((string) $this->argument('network')) : null;

            $this->table(['Tunnel', 'Member', 'IP'], $members->list($network));
        });
    }
}
