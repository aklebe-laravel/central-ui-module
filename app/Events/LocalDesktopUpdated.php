<?php
declare(strict_types=1);

namespace Modules\CentralUi\app\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * ShouldBroadcast: No queue, blocking task
 */
class LocalDesktopUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     *
     */
    public function __construct()
    {
    }

    /**
     * @return Channel[]
     */
    public function broadcastOn(): array
    {
        return [new Channel('vm-updates')];
    }
}