<?php

namespace Modules\CentralUi\app\Console;

use Illuminate\Support\Facades\Log;
use Modules\CentralUi\app\Services\ProxmoxService;
use Modules\DeployEnv\app\Console\Base\DeployEnvBase;
use Symfony\Component\Console\Command\Command as CommandResult;

class FetchProxmoxStatus extends DeployEnvBase
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'central-ui:fetch';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch data from Proxmox server';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $proxmox = app(ProxmoxService::class);

        // first receive all resources at once (standard and performant)
        if (!$proxmox->receiveResources(function ($msg) {
            $this->info($msg);
        })
        ) {
            return CommandResult::FAILURE;
        }

        // receive rrd data for at least 24h
        if (!$proxmox->receiveRrdData('day', function ($msg) use ($proxmox) {
            $this->info($msg);
            //$this->info(print_r($proxmox->getServerApiCache(1, "rrd_data.day.2", []), true));
        })
        ) {
            return CommandResult::FAILURE;
        }

        //if (!$proxmox->receiveRrdData('hour', function ($msg) {
        //    $this->info($msg);
        //
        //})
        //) {
        //    return CommandResult::FAILURE;
        //}

        return CommandResult::SUCCESS;
    }

}
