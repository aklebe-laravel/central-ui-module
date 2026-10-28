<?php

namespace Modules\CentralUi\app\Console;

use Carbon\Carbon;
use Modules\CentralUi\app\Models\ProxtopServer;
use Modules\CentralUi\app\Models\ProxtopServerVm;
use Modules\CentralUi\app\Services\ProxmoxService;
use Modules\DeployEnv\app\Console\Base\DeployEnvBase;
use Symfony\Component\Console\Command\Command as CommandResult;

class ProxmoxVmInfo extends DeployEnvBase
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'central-ui:vm-info {extern-vm-id}';

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
        $this->info(date('H:i:s'));
        $externVmId = (int) $this->argument('extern-vm-id');

        /** @var ProxtopServerVm $vm */
        $vm = ProxtopServerVm::with(['mediaItem'])->whereExternVmId($externVmId)->first();

        // vm model
        $this->info(sprintf("VM: '%s'", $vm->name));
        $this->comment("Schedule Info from now: ".print_r($vm->toArray(), true));

        // API raw
        // prepare the collections outside the loop
        $proxmoxService = app(ProxmoxService::class);
        $resources = $proxmoxService->getCache('resources', []);
        $result = $proxmoxService->getVmPayloadData($vm, $resources);
        $this->info("Cached resources: ");
        $this->comment(print_r($result, true));

        // API prepared
        $serverResourcesCollections = [];
        $servers = ProxtopServer::with([])->where('api_allowed', true)->get();
        foreach ($servers as $server) {
            $serverResourcesCollections[$server->getKey()] = collect($proxmoxService->getServerApiCache($server->getKey(), 'vms', []));
        }
        $this->info("Prepared/cached API Data:");
        $this->comment(print_r($proxmoxService->getCachedPreparedVmData($vm, $serverResourcesCollections), true));


        // templates
        $this->comment(sprintf("Property templates: %s", $vm->propertyTemplates->pluck('name')->implode(',')));

        $now = Carbon::now('UTC');
        //$now = Carbon::parse("2026-04-21 07:30:00")->utc();
        $this->comment(sprintf("Current Time Check: %s", $now->toDateTimeString()));

        $result = $vm->getTemplatesNextScheduleWindows($now);
        $this->info("Schedule Info from now: ");
        $this->comment(print_r($result, true));

        //$this->comment(print_r($template->vms->count, true));


        return CommandResult::SUCCESS;
    }

}
