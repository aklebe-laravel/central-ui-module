<?php
declare(strict_types = 1);

namespace Modules\CentralUi\app\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The planned shutdown of the server changed (started, extended, stopped), see ServerPowerService::refreshSchedule().
 * The server power button of every open desktop re-renders (countdown).
 */
class ServerPowerUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @return Channel[]
     */
    public function broadcastOn(): array
    {
        return [new Channel('server-power-updates')];
    }
}
