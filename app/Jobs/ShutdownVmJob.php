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

class ShutdownVmJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Lifetime of the "pending" lock. It is removed earlier when Proxmox reports
     * the new status (see ProxmoxService::getCachedPreparedVmData()) and by the
     * scheduler when it expires.
     */
    private const int PENDING_LOCK_SECONDS = 180;

    /**
     * Never retry: a retry could shut the VM down a second time.
     * (After the claim below a retry would be a no-op anyway.)
     */
    public int $tries = 1;

    /**
     * The scheduler dispatches a due action on every tick until the job removed it.
     * "Unique" drops the duplicates while this VM's job is queued or running.
     */
    public int $uniqueFor = 30;

    protected int $vmId;

    public function __construct($vmId)
    {
        $this->vmId = (int) $vmId;

        // Optional: own queue/worker, so slow API refresh jobs can never delay start/stop.
        // Falls back to 'default' when the config key does not exist.
        $this->onQueue((string) config('central-ui.queues.vm_actions', 'default'));
    }

    public function uniqueId(): string
    {
        return (string) $this->vmId;
    }

    public function handle(): void
    {
        // Step 1: claim the action. Only the caller that deletes the row may shut the VM down.
        // If the user cancelled in time, the row is gone and we simply do nothing.
        // The pending lock is created in the same transaction, so there is no moment
        // in which the VM has neither a shutdown action nor a pending lock (the UI
        // would flicker back to "running" in that gap).
        $claimed = DB::transaction(function (): bool {
            if (!VmActionGuard::claimDue($this->vmId, ProxtopServerAction::ActionShutDown)) {
                return false;
            }

            ProxtopServerAction::createAction(
                $this->vmId,
                ProxtopServerAction::ActionPendingToShutdown,
                1,
                now()->addSeconds(self::PENDING_LOCK_SECONDS)
            );

            return true;
        });

        if (!$claimed) {
            return;
        }

        // Step 2: tell all frontends "busy" right away, before the (slower) API call.
        broadcast(new LocalDesktopUpdated());

        // Step 3: the actual Proxmox API call.
        if ($vm = ProxtopServerVm::with([])->find($this->vmId)) {
            app(ProxmoxService::class)->sendShutdownVm($vm);
        }
    }

    /**
     * Called when handle() throws. Release the pending lock, otherwise the card
     * would stay "busy" until the lock expires.
     */
    public function failed(Throwable $exception): void
    {
        ProxtopServerAction::removeAction($this->vmId, ProxtopServerAction::ActionPendingToShutdown);
        broadcast(new LocalDesktopUpdated());
    }
}