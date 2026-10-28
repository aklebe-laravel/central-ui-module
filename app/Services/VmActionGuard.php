<?php
declare(strict_types = 1);

namespace Modules\CentralUi\app\Services;

use Modules\CentralUi\app\Models\ProxtopServerAction;

/**
 * Atomic "who wins" decision for scheduled VM actions.
 *
 * Problem: the user can cancel a shutdown (web request) at the very moment the
 * queue job wants to execute it. Checking first and deleting afterwards would
 * leave a gap in which both sides think they are allowed to proceed.
 *
 * Solution: both sides try to DELETE the action row with a time condition.
 * The database guarantees that a row is deleted exactly once, so exactly one
 * side gets "1 row affected" and wins:
 *
 *   - cancelBeforeDue(): wins only while scheduled_at is still in the future
 *   - claimDue():        wins only once scheduled_at has been reached
 *
 * Both methods live here on purpose, so the contract is visible in one place.
 */
final class VmActionGuard
{
    /**
     * Used by the queue job. True = this caller now owns the action and must execute it.
     * False = it was cancelled, is not due yet, or another job already claimed it.
     */
    public static function claimDue(int $vmId, string $action): bool
    {
        return ProxtopServerAction::query()
                   ->where('vm_id', $vmId)
                   ->where('action', $action)
                   ->where('scheduled_at', '<=', now())
                   ->delete() > 0;
    }

    /**
     * Used by the web request (cancel button). True = the action was removed in time.
     * False = too late (it is due and the job claims it right now) or nothing was scheduled.
     */
    public static function cancelBeforeDue(int $vmId, string $action): bool
    {
        return ProxtopServerAction::query()
                   ->where('vm_id', $vmId)
                   ->where('action', $action)
                   ->where('scheduled_at', '>', now())
                   ->delete() > 0;
    }
}