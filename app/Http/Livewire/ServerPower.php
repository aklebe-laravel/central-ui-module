<?php
declare(strict_types = 1);

namespace Modules\CentralUi\app\Http\Livewire;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Modules\Acl\app\Models\AclResource;
use Modules\CentralUi\app\Http\Livewire\DataTable\VmDesktopItems;
use Modules\CentralUi\app\Models\ProxtopServer;
use Modules\CentralUi\app\Events\ServerPowerUpdated;
use Modules\CentralUi\app\Services\Backup\ProxmoxBackupService;
use Modules\CentralUi\app\Services\SafeBroadcast;
use Modules\CentralUi\app\Services\ServerPowerService;
use Modules\SystemBase\app\Http\Livewire\BaseComponent;

/**
 * Button "shut down the server" in the desktop toolbar.
 *
 * - admins only, hidden without usable driver (config 'central-ui.power.driver', see ServerPowerService)
 * - always confirmed by the message box of the driver (config/message-boxes.php 'central-ui-server.desktop.*'),
 *   with a backup warning while a backup runs or a job starts within 'central-ui.power.backup_warning_seconds'
 * - the shutdown itself is done by the driver: Proxmox API (default) or a power service of the host
 *
 * Countdown (only drivers with grace period, Power\ServerPowerSchedule): while a shutdown is planned, every user
 * sees the countdown instead of the button. Everyone can extend it ('central-ui.power.extend_minutes'),
 * only admins can stop it. The planned time comes from the cache (ServerPowerService::cachedSchedule()).
 */
class ServerPower extends BaseComponent
{
    /**
     * @return void
     */
    protected function initBooted(): void
    {
        parent::initBooted();

        // buttons of the message boxes for messageBox.js
        app('system_base')->provideMessageBoxButtons(objectKey: 'central-ui-server');
    }

    /**
     * Re-render with the backup state (the confirmation depends on it).
     *
     * @return void
     */
    #[On('echo:proxmox-backup-updates,.Modules\CentralUi\app\Events\ProxmoxApiBackupDataUpdated')]
    #[On('echo:server-power-updates,.Modules\CentralUi\app\Events\ServerPowerUpdated')]
    #[On('vm-desktop:poll')]
    public function refreshState(): void
    {
    }

    /**
     * Extends the planned shutdown. Every logged in user (like the Telegram buttons).
     *
     * @param  int  $minutes  one of 'central-ui.power.extend_minutes'
     *
     * @return void
     */
    public function extendShutdown(int $minutes): void
    {
        $user = app('website_base_settings')->currentUser();
        $driver = app(ServerPowerService::class)->scheduleDriver();
        if (!$user || !$driver || !in_array($minutes, (array) config('central-ui.power.extend_minutes', [30, 120]), true)) {
            $this->notify('error', __('Permission denied'));

            return;
        }

        $result = $driver->extendShutdown($minutes, self::userName($user));
        $this->afterScheduleAction($result, fn() => __('The server shuts down at :time.', [
            'time' => app('website_base_settings')
                ->toDisplayTime((int) $result['shutdown_at'])
                ->format('H:i'),
        ]));
    }

    /**
     * Stops the planned shutdown. Admins only.
     *
     * @return void
     */
    public function cancelShutdown(): void
    {
        $user = app('website_base_settings')->currentUser();
        $driver = app(ServerPowerService::class)->scheduleDriver();
        if (!$user || !$driver || !$user->hasAclResource([AclResource::RES_ADMIN])) {
            $this->notify('error', __('Permission denied'));

            return;
        }

        $result = $driver->cancelShutdown(self::userName($user));
        $this->afterScheduleAction($result, fn() => __('Shutdown of the server stopped.'));
    }

    /**
     * Result of extend/cancel: message, remember the new time at once and tell all desktops.
     *
     * @param  array{ok: bool, shutdown_at: ?int, message: string}  $result
     * @param  callable                                             $successMessage
     *
     * @return void
     */
    protected function afterScheduleAction(array $result, callable $successMessage): void
    {
        if (!$result['ok']) {
            $this->notify('error', $result['message']);

            return;
        }

        app(ServerPowerService::class)->rememberSchedule($result['shutdown_at']);
        SafeBroadcast::send(new ServerPowerUpdated());
        $this->notify('success', $successMessage());
    }

    /**
     * @param  mixed  $user
     *
     * @return string  name shown in the notifications of the host
     */
    protected static function userName(mixed $user): string
    {
        return (string) ($user->name ?? $user->email ?? 'unknown');
    }

    /**
     * Action of the message box button (central-ui::shutdown-server). A public endpoint: checks everything again.
     *
     * @param  mixed  $livewireId
     * @param  bool   $force  confirmed with the backup warning
     *
     * @return void
     */
    #[On('shutdown-server')]
    public function shutdownServer(mixed $livewireId = null, bool $force = false): void
    {
        if (!$this->checkLivewireId($livewireId)) {
            return;
        }

        if (!$this->canUse()) {
            $this->notify('error', __('Permission denied'));

            return;
        }

        // the backup state may have changed since the confirmation was opened
        $risk = $this->shutdownRisk();
        if (!$force && ($risk['running'] || $risk['next_run'] !== null)) {
            $this->notify('warning', __('A backup runs or starts soon. Please confirm the shutdown again.'));

            return;
        }

        $user = app('website_base_settings')->currentUser();
        $result = app(ServerPowerService::class)->requestShutdown(self::userName($user), $force);

        if (!$result['ok']) {
            $this->notify('error', $result['message']);

            return;
        }

        // countdown at once (drivers with grace period), not only with the next status request
        if ($result['shutdown_at'] && app(ServerPowerService::class)->supportsSchedule()) {
            app(ServerPowerService::class)->rememberSchedule($result['shutdown_at']);
            SafeBroadcast::send(new ServerPowerUpdated());
        }

        $this->notify('success',
            $result['shutdown_at'] ? __('The server shuts down at :time.',
                [
                    'time' => app('website_base_settings')
                        ->toDisplayTime($result['shutdown_at'])
                        ->format('H:i'),
                ]) : __('Shutdown of the server requested.'));
        if ($result['message']) {
            $this->notify('info', $result['message']);
        }
    }

    /**
     * Shows a message in the messages of the desktop (data table VmDesktopItems, above the toolbar),
     * not in this small button.
     *
     * @param  string  $type  'success', 'error', 'info' or 'warning'
     * @param  string  $message
     *
     * @return void
     */
    protected function notify(string $type, string $message): void
    {
        $this->dispatch(VmDesktopItems::EventMessage, type: $type, message: $message)
            ->to(VmDesktopItems::class);
    }

    /**
     * @return Application|Factory|View
     */
    public function render(): Factory|View|Application
    {
        $canUse = $this->canUse();
        $risk = $canUse ? $this->shutdownRisk() : ['running' => false, 'next_run' => null];
        $backupRisk = $risk['running'] || $risk['next_run'] !== null;

        $power = app(ServerPowerService::class);
        $user = app('website_base_settings')->currentUser();
        // planned shutdown (countdown): for every logged in user, until shortly after the time (the server goes down)
        $shutdownAt = ($user && $power->supportsSchedule()) ? $power->cachedSchedule()['shutdown_at'] : null;
        if ($shutdownAt !== null && $shutdownAt < time() - 120) {
            $shutdownAt = null;
        }

        return view('central-ui::livewire.server-power', [
            'canUse'        => $canUse,
            'canCancel'     => $user && $user->hasAclResource([AclResource::RES_ADMIN]),
            'risk'          => $risk,
            'backupRisk'    => $backupRisk,
            'messageBox'    => $canUse ? $power->messageBox($backupRisk) : '',
            'shutdownAt'    => $shutdownAt,
            'shutdownTime'  => $shutdownAt ? app('website_base_settings')
                ->toDisplayTime($shutdownAt)
                ->format('H:i') : '',
            'extendMinutes' => (array) config('central-ui.power.extend_minutes', [30, 120]),
        ]);
    }

    /**
     * Admins only (not developers: this switches everything off) and only with a usable driver.
     *
     * @return bool
     */
    protected function canUse(): bool
    {
        $user = app('website_base_settings')->currentUser();

        return $user
               && $user->hasAclResource([AclResource::RES_ADMIN])
               && app(ServerPowerService::class)->isConfigured();
    }

    /**
     * @return array{running: bool, next_run: ?int}
     */
    protected function shutdownRisk(): array
    {
        $backups = app(ProxmoxBackupService::class);
        $serversData = [];
        foreach (
            ProxtopServer::query()
                ->where('api_allowed', true)
                ->pluck('id') as $serverId
        ) {
            $serversData[$serverId] = $backups->getServerBackups((int) $serverId);
        }

        return ProxmoxBackupService::shutdownRisk($serversData, time(), (int) config('central-ui.power.backup_warning_seconds', 900));
    }
}
