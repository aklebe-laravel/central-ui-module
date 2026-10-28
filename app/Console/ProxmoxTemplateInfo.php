<?php

namespace Modules\CentralUi\app\Console;

use Modules\CentralUi\app\Models\ProxtopPropertyTemplate;
use Modules\DeployEnv\app\Console\Base\DeployEnvBase;
use Symfony\Component\Console\Command\Command as CommandResult;

class ProxmoxTemplateInfo extends DeployEnvBase
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'central-ui:template-info {template-id}';

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
        $id = (int)$this->argument('template-id');
        $template = ProxtopPropertyTemplate::with([])->whereId($id)->first();
        $this->comment(sprintf("Template: '%s'", $template->name));
        $this->comment(sprintf("Count of vms: %s", $template->vms->count()));
        $this->comment(sprintf("Count of schedules: %s", $template->schedules->count()));

        return CommandResult::SUCCESS;
    }

}
