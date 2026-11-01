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

        // RRD statistics of every timeframe (the scheduler fetches them in different intervals)
        foreach (array_keys(ProxmoxService::RrdTimeframes) as $timeframe) {
            if (!$proxmox->receiveRrdData($timeframe, function ($msg) {
                $this->info($msg);
            })
            ) {
                return CommandResult::FAILURE;
            }
        }

        // rebuild the payloads of the cards with the new statistics
        $proxmox->receiveResourcesAndBroadcast();

        return CommandResult::SUCCESS;
    }

}
