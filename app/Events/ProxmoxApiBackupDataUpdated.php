<?php
declare(strict_types = 1);

namespace Modules\CentralUi\app\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Backup jobs or running backups changed (ProxmoxBackupService::receiveAndCache()).
 * Only sent when the data changed, so the backup bar of the desktop re-renders rarely.
 */
class ProxmoxApiBackupDataUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @return Channel[]
     */
    public function broadcastOn(): array
    {
        return [new Channel('proxmox-backup-updates')];
    }
}
