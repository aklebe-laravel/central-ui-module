<?php
declare(strict_types = 1);

namespace Modules\CentralUi\app\Services;

use Illuminate\Support\Facades\Cache;
use Modules\CentralUi\app\Services\Power\ServerPowerDriver;
use Modules\CentralUi\app\Services\Power\ServerPowerSchedule;

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
 *
 * Countdown: a driver that also implements Power\ServerPowerSchedule (http) tells when the server shuts down.
 * The queue job (RefreshProxmoxDataJob, part 'power') asks it every 'central-ui.power.status_interval' seconds
 * (faster while a shutdown is planned), the result is cached (central-ui.power.schedule) and broadcast on changes.
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

    /**
     * Cache key of the planned shutdown (see refreshSchedule()).
     */
    public const string CacheKeySchedule = 'central-ui.power.schedule';

    /**
     * The driver, if it has a countdown (grace period), otherwise null.
     *
     * @return ServerPowerSchedule|null
     */
    public function scheduleDriver(): ?ServerPowerSchedule
    {
        $driver = $this->driver();

        return ($driver instanceof ServerPowerSchedule && $driver->isAvailable()) ? $driver : null;
    }

    /**
     * Cheap check for the scheduler tick (no request).
     *
     * @return bool
     */
    public function supportsSchedule(): bool
    {
        return $this->scheduleDriver() !== null;
    }

    /**
     * The planned shutdown as cached by refreshSchedule(). Never asks the host.
     *
     * @return array{shutdown_at: ?int, fetched_at: int}
     */
    public function cachedSchedule(): array
    {
        return (Cache::get(self::CacheKeySchedule) ?? []) + ['shutdown_at' => null, 'fetched_at' => 0];
    }

    /**
     * True while a shutdown is planned (the scheduler asks more often then).
     *
     * @return bool
     */
    public function isShutdownPlanned(): bool
    {
        return $this->cachedSchedule()['shutdown_at'] !== null;
    }

    /**
     * Asks the driver for the planned shutdown and caches it. An unreadable status keeps the last known one.
     *
     * @return bool  true if the planned shutdown changed (the caller broadcasts then)
     */
    public function refreshSchedule(): bool
    {
        $before = $this->cachedSchedule()['shutdown_at'];
        if (!($driver = $this->scheduleDriver())) {
            $now = null;
        } else {
            $status = $driver->scheduledShutdown();
            if (!$status['ok']) {
                return false;
            }
            $now = $status['shutdown_at'];
        }

        Cache::forever(self::CacheKeySchedule, ['shutdown_at' => $now, 'fetched_at' => time()]);

        return $before !== $now;
    }

    /**
     * Stores a planned shutdown that an action just returned (no need to wait for the next status).
     *
     * @param  int|null  $shutdownAt
     *
     * @return void
     */
    public function rememberSchedule(?int $shutdownAt): void
    {
        Cache::forever(self::CacheKeySchedule, ['shutdown_at' => $shutdownAt, 'fetched_at' => time()]);
    }
}
