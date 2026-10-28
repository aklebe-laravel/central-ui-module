<?php

namespace Modules\CentralUi\app\Console;

use Modules\CentralUi\app\Models\ProxtopServer;
use Modules\CentralUi\app\Services\ProxmoxService;
use Modules\DeployEnv\app\Console\Base\DeployEnvBase;
use Symfony\Component\Console\Command\Command as CommandResult;

class ProxmoxInfo extends DeployEnvBase
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'central-ui:proxmox-info {info-tag}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '...';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $proxmoxService = app(ProxmoxService::class);
        $infoTag = $this->argument('info-tag');

        //// testing commands in vm
        //$vm = ProxtopServerVm::with([])->whereId(27)->first();
        ////$result = $proxmox->runCommandInVmAndWait($vm, 'ls');
        ////$result = $proxmox->runCommandInVmAndWait($vm, 'ls -la /tmp && echo "Es geht!"');
        ////$result = $proxmox->runCommandInVmAndWait($vm, 'echo', ['Hällo Wörld']);
        //$result = $proxmox->runCommandInVmAndWait($vm, "ss -t -n state established '( ! sport = :22 )'");
        ////Log::info('Result: '.strlen($result). ' Bytes.');
        //Log::info(print_r($result, true));

        switch ($infoTag) {
            case 'resources':
                $result = $proxmoxService->getCache('resources', []);
                $this->info("Cached resources: ");
                $this->comment(print_r($result, true));
                break;

            case 'raw':
                $servers = ProxtopServer::with([])->where('api_allowed', true)->get();
                foreach ($servers as $server) {
                    $result = $proxmoxService->getServerApiCache($server->getKey(), 'vms');
                    $this->info(sprintf("Cached raw resources server: %s", $server->getKey()));
                    $this->comment(print_r($result, true));
                }
                break;

            default:
                break;
        }


        return CommandResult::SUCCESS;
    }

}
