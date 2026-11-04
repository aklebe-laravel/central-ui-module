<?php
declare(strict_types = 1);

namespace Modules\CentralUi\app\Services\Power;

/**
 * Optional capability of a power driver: a shutdown with grace period that can be seen, extended and stopped
 * (e.g. HttpShutdown with pve-power). Drivers without grace period (ProxmoxNodeShutdown) do not implement it.
 *
 * Read by ServerPowerService::refreshSchedule() (queue job, cached), shown by ServerPower as countdown for everyone.
 */
interface ServerPowerSchedule
{
    /**
     * The planned shutdown, from whatever source (power button, Telegram, Proxtop, "shutdown +15" via SSH).
     *
     * @return array{ok: bool, shutdown_at: ?int}  ok false = status not readable (keep the last known one)
     */
    public function scheduledShutdown(): array;

    /**
     * @param  int     $minutes      new grace period from now
     * @param  string  $requestedBy  name of the user
     *
     * @return array{ok: bool, shutdown_at: ?int, message: string}
     */
    public function extendShutdown(int $minutes, string $requestedBy): array;

    /**
     * @param  string  $requestedBy  name of the user
     *
     * @return array{ok: bool, shutdown_at: ?int, message: string}
     */
    public function cancelShutdown(string $requestedBy): array;
}
