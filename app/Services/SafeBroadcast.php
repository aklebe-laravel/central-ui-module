<?php
declare(strict_types = 1);

namespace Modules\CentralUi\app\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Broadcasts an event without letting a broadcast error break the caller.
 *
 * The events of this module are only notifications for the frontends (ShouldBroadcastNow).
 * If the websocket server (Reverb) is down, broadcast() throws. Without this wrapper a click on
 * the VM switch ended in an error page, and the start/shutdown jobs stopped BEFORE the Proxmox call.
 * The frontends fall back to polling while disconnected (see liveUpdateFallback in centralUi.js).
 */
final class SafeBroadcast
{
    /**
     * Seconds between two warnings in the log (the scheduler broadcasts every few seconds).
     */
    private const int WARNING_INTERVAL_SECONDS = 60;

    /**
     * @param  object  $event  a broadcast event like LocalDesktopUpdated
     *
     * @return bool  false if the broadcast failed (already logged)
     */
    public static function send(object $event): bool
    {
        try {
            // event() instead of broadcast(): broadcast() returns a PendingBroadcast that sends in its
            // destructor, event() sends right here (ShouldBroadcastNow), so an error is caught below
            event($event);

            return true;
        } catch (Throwable $ex) {
            // Cache::add() only succeeds if the key does not exist: at most one warning per interval
            if (Cache::add('central-ui.safe-broadcast.warned', true, self::WARNING_INTERVAL_SECONDS)) {
                Log::warning(sprintf("Broadcast of %s failed (websocket server down?): %s", $event::class, $ex->getMessage()));
            }

            return false;
        }
    }
}
