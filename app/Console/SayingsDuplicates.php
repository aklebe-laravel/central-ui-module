<?php

namespace Modules\CentralUi\app\Console;

use Illuminate\Support\Facades\Log;
use Modules\CentralUi\app\Models\Saying;
use Modules\CentralUi\app\Services\ProxmoxService;
use Modules\DeployEnv\app\Console\Base\DeployEnvBase;
use Symfony\Component\Console\Command\Command as CommandResult;

class SayingsDuplicates extends DeployEnvBase
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'central-ui:sayings-duplicates';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Find duplicates and likeness';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $list = Saying::getLikenessItems();
        $this->message(print_r($list, true));

        return CommandResult::SUCCESS;
    }

}
