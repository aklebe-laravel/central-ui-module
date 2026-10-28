<?php
declare(strict_types = 1);

namespace Modules\CentralUi\app\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Modules\CentralUi\app\Events\LocalDesktopUpdated;
use Modules\CentralUi\app\Models\ProxtopServerAction;
use Modules\CentralUi\app\Models\ProxtopServerVm;
use Modules\CentralUi\app\Services\ProxmoxService;
use Modules\CentralUi\app\Services\VmActionGuard;
use Throwable;

class StartVmJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Lifetime of the "pending" lock. It is removed earlier when Proxmox reports
     * the new status (see ProxmoxService::getCachedPreparedVmData()) and by the
     * scheduler when it expires.
     */
    private const int PENDING_LOCK_SECONDS = 180;

    /**
     * Never retry: see ShutdownVmJob.
     */
    public int $tries = 1;

    /**
     * Drop duplicate dispatches of the scheduler while this VM's job is queued or running.
     */
    public int $uniqueFor = 30;

    protected int $vmId;

    public function __construct($vmId)
    {
        $this->vmId = (int) $vmId;

        // Optional: own queue/worker. Falls back to 'default' when the config key does not exist.
        $this->onQueue((string) config('central-ui.queues.vm_actions', 'default'));
    }

    public function uniqueId(): string
    {
        return (string) $this->vmId;
    }

    public function handle(): void
    {
        // Claim the action and create the pending lock atomically (see ShutdownVmJob).
        $claimed = DB::transaction(function (): bool {
            if (!VmActionGuard::claimDue($this->vmId, ProxtopServerAction::ActionStart)) {
                return false;
            }

            ProxtopServerAction::createAction(
                $this->vmId,
                ProxtopServerAction::ActionPendingToStart,
                1,
                now()->addSeconds(self::PENDING_LOCK_SECONDS)
            );

            return true;
        });

        if (!$claimed) {
            return;
        }

        // Tell all frontends "busy" right away, before the (slower) API call.
        broadcast(new LocalDesktopUpdated());

        if ($vm = ProxtopServerVm::with([])->find($this->vmId)) {
            app(ProxmoxService::class)->sendStartVm($vm);
        }
    }

    /**
     * Called when handle() throws. Release the pending lock so the card does not stay "busy".
     */
    public function failed(Throwable $exception): void
    {
        ProxtopServerAction::removeAction($this->vmId, ProxtopServerAction::ActionPendingToStart);
        broadcast(new LocalDesktopUpdated());
    }
}