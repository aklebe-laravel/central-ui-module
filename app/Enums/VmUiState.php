<?php
declare(strict_types = 1);

namespace Modules\CentralUi\app\Enums;

/**
 * The one and only UI state of a VM card.
 *
 * It is DERIVED on every render from:
 *   - the Proxmox status in the payload cache (running/stopped/...)
 *   - the scheduled actions in the database (shutdown grace period, start, pending locks)
 *
 * Nothing in the UI writes this state anywhere. That is what keeps the
 * button, the card image and the countdown consistent with each other.
 */
enum VmUiState: string
{
    /** VM is off and can be started. */
    case Stopped = 'stopped';

    /** VM is running and can be shut down. */
    case Running = 'running';

    /** Shutdown is scheduled, grace period still running: a click cancels it. */
    case Grace = 'grace';

    /** Start/stop is being processed, or the grace period is over: no user action possible. */
    case Busy = 'busy';

    /** No usable status (no payload yet, unknown Proxmox status, ...). */
    case Unknown = 'unknown';
}