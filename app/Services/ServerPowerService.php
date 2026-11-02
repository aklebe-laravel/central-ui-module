<?php
declare(strict_types = 1);

namespace Modules\CentralUi\app\Services;

use Modules\CentralUi\app\Services\Power\ServerPowerDriver;

/**
 * Shutdown of the whole server (button in the desktop toolbar, ServerPower) by the configured driver:
 *
 *   config 'central-ui.power.driver'   (env SERVER_POWER_DRIVER)
 *     proxmox  default: the Proxmox API shuts down the nodes at once (Power\ProxmoxNodeShutdown)
 *     http     a power service of the host decides, e.g. with grace period (Power\HttpShutdown)
 *     none     no button
 *   Without SERVER_POWER_DRIVER: 'http' if SERVER_POWER_URL is set, otherwise 'proxmox'.
 *
 * Own drivers: config 'central-ui.power.drivers' => [name => class implementing Power\ServerPowerDriver].
 */
class ServerPowerService
{
    /**
     * The configured driver, null = none (button hidden).
     *
     * @return ServerPowerDriver|null
     */
    public function driver(): ?ServerPowerDriver
    {
        $name = (string) config('central-ui.power.driver', 'proxmox');
        $class = config('central-ui.power.drivers.'.$name);
        if (!$class || !is_subclass_of($class, ServerPowerDriver::class)) {
            return null;
        }

        return app($class);
    }

    /**
     * @return bool  false = no driver or the driver is not usable (button hidden)
     */
    public function isConfigured(): bool
    {
        return (bool) $this->driver()
            ?->isAvailable();
    }

    /**
     * @param  string  $requestedBy
     * @param  bool    $force
     *
     * @return array{ok: bool, shutdown_at: ?int, message: string}
     */
    public function requestShutdown(string $requestedBy, bool $force = false): array
    {
        if (!($driver = $this->driver()) || !$driver->isAvailable()) {
            return ['ok' => false, 'shutdown_at' => null, 'message' => __('The server power service is not configured.')];
        }

        return $driver->requestShutdown($requestedBy, $force);
    }

    /**
     * @param  bool  $backupRisk
     *
     * @return string  path of the confirmation in config 'message-boxes'
     */
    public function messageBox(bool $backupRisk): string
    {
        return (string) $this->driver()
            ?->messageBox($backupRisk);
    }
}
