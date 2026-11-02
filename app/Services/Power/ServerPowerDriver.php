<?php
declare(strict_types = 1);

namespace Modules\CentralUi\app\Services\Power;

/**
 * How the server is shut down (config 'central-ui.power.driver', see ServerPowerService).
 *
 * - ProxmoxNodeShutdown ('proxmox', default): Proxmox API, the nodes shut down at once (no grace period)
 * - HttpShutdown ('http'): asks a power service of the host (e.g. pve-power with grace period and notifications)
 *
 * Own drivers: implement this interface and add them to config 'central-ui.power.drivers'.
 */
interface ServerPowerDriver
{
    /**
     * False hides the button (e.g. no url, no node known yet).
     *
     * @return bool
     */
    public function isAvailable(): bool;

    /**
     * @param  string  $requestedBy  name of the user
     * @param  bool    $force        the user confirmed although a backup runs or starts soon
     *
     * @return array{ok: bool, shutdown_at: ?int, message: string}
     */
    public function requestShutdown(string $requestedBy, bool $force = false): array;

    /**
     * Path of the confirmation in config 'message-boxes' (object.category.task), with or without backup warning.
     *
     * @param  bool  $backupRisk  a backup runs or starts soon
     *
     * @return string
     */
    public function messageBox(bool $backupRisk): string;
}
