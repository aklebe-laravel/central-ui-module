<?php
declare(strict_types = 1);

namespace Modules\CentralUi\app\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Modules\CentralUi\app\Events\LocalDesktopUpdated;
use Modules\CentralUi\app\Jobs\RefreshProxmoxDataJob;
use Modules\CentralUi\app\Jobs\ShutdownVmJob;
use Modules\CentralUi\app\Jobs\StartVmJob;
use Modules\CentralUi\app\Models\ProxtopServerAction;
use Modules\SystemBase\app\Providers\Base\ScheduleBaseServiceProvider;

class ScheduleServiceProvider extends ScheduleBaseServiceProvider
{
    /**
     * Bootstrap module scheduled tasks inside a single closure.
     *
     * RULE: this tick must stay FAST (a few milliseconds). It only decides what is
     * due and dispatches jobs. Everything slow (Proxmox API, payload rebuild) runs
     * in queue jobs. A closure cannot run in the background, so anything slow in
     * here would delay every later tick, including the processing of due
     * shutdown/start actions.
     *
     * @param  Schedule  $schedule
     *
     * @return void
     */
    protected function bootEnabledSchedule(Schedule $schedule): void
    {
        $schedule->call(function () {
            // Note: static variables only live as long as the process that runs the
            // sub-minute loop (typically one minute with schedule:run / schedule:work).
            static $intervals = [];
            static $lastRuns = [];
            static $lastConfigCheck = 0;

            $currentTime = time();

            // 1. Throttle container resolution: re-read the config every 20 seconds only
            if ($currentTime - $lastConfigCheck >= 20 || empty($intervals)) {
                $config = app('website_base_config');

                $intervals['resource'] = (int) $config->getValue('scheduler.api.resource.interval', 30, module: 'central-ui');
                $intervals['rrd'] = (int) $config->getValue('scheduler.api.rrd.interval', 60, module: 'central-ui');
                $intervals['desktop'] = (int) $config->getValue('scheduler.frontend.desktop.interval', 0, module: 'central-ui');
                // 'scheduler.api.action.interval' is no longer used: due actions are
                // checked on EVERY tick now (a cheap indexed query), see step 4.

                $lastConfigCheck = $currentTime;
            }

            // 2. Proxmox resources + payload rebuild: only dispatch, the job does the work
            if ($currentTime - ($lastRuns['resource'] ?? 0) >= $intervals['resource']) {
                $lastRuns['resource'] = $currentTime;

                RefreshProxmoxDataJob::dispatch(RefreshProxmoxDataJob::PartResources);
            }

            // 3. RRD history: only dispatch, the job does the work
            if ($currentTime - ($lastRuns['rrd'] ?? 0) >= $intervals['rrd']) {
                $lastRuns['rrd'] = $currentTime;

                RefreshProxmoxDataJob::dispatch(RefreshProxmoxDataJob::PartRrd);
            }

            // 4. Due database actions (start / shutdown / expiring pending locks).
            // Start/Shutdown jobs are idempotent: they claim the action atomically
            // (see VmActionGuard), so dispatching one twice is harmless.
            $actions = ProxtopServerAction::where('scheduled_at', '<=', now())->get();
            foreach ($actions as $action) {
                switch ($action->action) {
                    case ProxtopServerAction::ActionStart:
                        StartVmJob::dispatch($action->vm_id);
                        break;

                    case ProxtopServerAction::ActionShutDown:
                        ShutdownVmJob::dispatch($action->vm_id);
                        break;

                    // Pending locks are timeouts: when they are due, they simply expire
                    case ProxtopServerAction::ActionPendingLock:
                    case ProxtopServerAction::ActionPendingToStart:
                    case ProxtopServerAction::ActionPendingToShutdown:
                        ProxtopServerAction::removeAction($action->vm_id, $action->action);
                        break;
                }
            }

            // 5. Optional periodic frontend sync (0 or -1 = disabled)
            if ($intervals['desktop'] > 0) {
                if ($currentTime - ($lastRuns['desktop'] ?? 0) >= $intervals['desktop']) {
                    $lastRuns['desktop'] = $currentTime;

                    broadcast(new LocalDesktopUpdated());
                }
            }
        })
            // 2-second grid: a due shutdown starts at most ~2s late (was ~5s)
            ->everyTwoSeconds()
            ->name('proxmox-core-dynamic-loop')
            // Lock expiry in MINUTES. Was 5: after a killed scheduler process
            // (deploy, container restart) nothing was processed for up to 5 minutes.
            ->withoutOverlapping(1);
    }
}