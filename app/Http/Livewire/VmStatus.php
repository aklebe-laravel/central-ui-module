<?php
declare(strict_types = 1);

namespace Modules\CentralUi\app\Http\Livewire;

use Carbon\CarbonInterval;
use Exception;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Reactive;
use Modules\CentralUi\app\Enums\VmUiState;
use Modules\CentralUi\app\Events\LocalDesktopUpdated;
use Modules\CentralUi\app\Models\ProxtopObserveServerItem;
use Modules\CentralUi\app\Models\ProxtopServerAction;
use Modules\CentralUi\app\Models\ProxtopServerVm;
use Modules\CentralUi\app\Services\ProxmoxService;
use Modules\CentralUi\app\Services\VmActionGuard;
use Modules\SystemBase\app\Http\Livewire\BaseComponent;
use Modules\WebsiteBase\app\Services\Setting;

class VmStatus extends BaseComponent
{
    /**
     * Length of the shutdown grace period in seconds.
     * During this time a click on the switch cancels the shutdown.
     */
    private const int GRACE_SECONDS = 10;

    /**
     * synced with 'globalDesktopVmDetails' from parent
     *
     * @var ?string
     */
    #[Reactive]
    public ?string $density;

    /**
     * synced with 'globalDesktopLayout' from parent (compact, icons, list)
     *
     * @var string
     */
    #[Reactive]
    public string $layout = VmDesktop::LAYOUT_COMPACT;

    /**
     * @var int
     */
    #[Reactive]
    public int $observeItemId = 0;

    /**
     * Last update for api rrd data
     *
     * @var int
     */
    public int $lastRrdUpdate = 0;

    /**
     * Last update for api resources
     *
     * @var int
     */
    public int $lastServerUpdate = 0;

    /**
     * Last update for desktop trigger
     *
     * @var int
     */
    public int $lastDesktopUpdate = 0;

    /**
     * @var int
     */
    public int $refreshEnterCount = 0;

    /**
     * @var int
     */
    public int $refreshProcessedCount = 0;

    /**
     * @var array|array[]
     */
    protected array $styles = [
        'phase1' => [
            'text' => 'text-info-800',
            'bg'   => 'bg-info-800 shadow-[0_0_10px_var(--color-info-700)]',
        ],
        'phase2' => [
            'text' => 'text-info-500',
            'bg'   => 'bg-info-500 shadow-[0_0_10px_var(--color-info-400)]',
        ],
        'phase3' => [
            'text' => 'text-info-300',
            'bg'   => 'bg-info-300 shadow-[0_0_10px_var(--color-info-200)]',
        ],
        'idle'   => [
            'text' => 'text-secondary-400',
            'bg'   => 'bg-secondary-400 shadow-[0_0_10px_var(--color-secondary-300)]',
        ],
        'limit'  => [
            'text' => 'text-error-600',
            'bg'   => 'bg-error-600 shadow-[0_0_10px_var(--color-error-500)]',
        ],
    ];

    /**
     * @var ProxmoxService
     */
    protected ProxmoxService $proxmox;

    /**
     * @var Setting
     */
    protected Setting $system;

    /**
     * Column template of the desktop list layout.
     * Used by the list header (vm-desktop.blade.php) and every row (vm-status.blade.php).
     *
     * With details: image, name, status, cpu, ram, disk, online (lg+), switch.
     * Without details (user setting 'desktop_vm_details' = ''): image, name, status, switch.
     * Mobile shows image, name, status and switch.
     *
     * Keep the class strings complete (no concatenated fragments), Tailwind scans them as they are.
     *
     * @param  bool  $withDetails
     *
     * @return string
     */
    public static function listGridCss(bool $withDetails = true): string
    {
        $base = 'grid items-center gap-x-2 md:gap-x-3 px-3 py-2 grid-cols-[2.5rem_minmax(0,1fr)_auto_5rem] ';

        if (!$withDetails) {
            return $base.'md:grid-cols-[2.5rem_minmax(0,1fr)_6rem_7rem]';
        }

        return $base.'md:grid-cols-[2.5rem_minmax(0,1fr)_6rem_5rem_5rem_5rem_7rem] '.'lg:grid-cols-[2.5rem_minmax(0,1fr)_6rem_5rem_5rem_5rem_7rem_7rem]';
    }

    /**
     * @return Application|Factory|View
     * @throws Exception
     */
    public function render(): Factory|View|Application
    {
        return view('central-ui::livewire.vm-status', [
            'styles' => $this->styles,
        ]);
    }

    /**
     * Overwrite this if you need the same code for boot() and hydrate()
     *
     * @return void
     */
    protected function init(): void
    {
        parent::init();

        $this->proxmox = app(ProxmoxService::class);
        $this->system = app(Setting::class);

        // fill data ...
        $this->observeItemData;
    }

    /**
     * Null if the item does not exist, is disabled, or its vm is not visible for the user (acl_res_view).
     * Null makes observeItemData empty and every action abort with 403 (see guardSwitch()).
     *
     * @return ProxtopObserveServerItem|null
     */
    #[Computed]
    public function observeItem(): ?ProxtopObserveServerItem
    {
        $user = app('website_base_settings')->currentUser();

        // withStandardRelations() already eager loads vm.enabledPropertyTemplates for the acl check
        $item = $user->observeItems()
            ->whereIsEnabledAndHasActiveServer()
            ->withStandardRelations()
            ->whereId($this->observeItemId)
            ->first();

        if (!$item?->vm?->isVisibleForUser($user)) {
            return null;
        }

        return $item;
    }

    // ------------------------------------------------------------------
    // Live updates (Echo)
    // ------------------------------------------------------------------

    /**
     * Echo-Event syntax: "echo:{channel},{event}"
     *
     * @return void
     */
    #[On('echo:proxmox-rrd-updates,.Modules\CentralUi\app\Events\ProxmoxApiRrdDataUpdated')]
    public function receiveApiRrdUpdates(): void
    {
        $this->lastRrdUpdate = time();
    }

    /**
     * Echo-Event syntax: "echo:{channel},{event}"
     *
     * @return void
     */
    #[On('echo:proxmox-resource-updates,.Modules\CentralUi\app\Events\ProxmoxApiResourceDataUpdated')]
    public function receiveApiResourcesUpdates(): void
    {
        $this->lastServerUpdate = time();
    }

    /**
     * Any event here re-renders the card, so a state change made by another
     * user, a queue job or the scheduler shows up without a page reload.
     *
     * @return void
     */
    #[On('echo:vm-updates,.Modules\CentralUi\app\Events\LocalDesktopUpdated')]
    public function receiveDesktopUpdates(): void
    {
        $this->lastDesktopUpdate = time();
    }

    // ------------------------------------------------------------------
    // Actions (called by wire:click from the switch)
    //
    // Rules:
    //  - The server decides. The client only displays and sends the click.
    //  - Every action re-checks state and permission itself (a Livewire method
    //    is a public endpoint, the disabled button is not a protection).
    //  - If the UI was stale (state changed in the meantime) the click is
    //    ignored silently and the re-render shows the real state.
    // ------------------------------------------------------------------

    /**
     * @return void
     */
    public function requestStart(): void
    {
        if (!$this->guardSwitch(VmUiState::Stopped)) {
            return;
        }

        // Due immediately: the scheduler dispatches StartVmJob on its next tick.
        ProxtopServerAction::createActionIfNotExists($this->observeItem->vm_id,
            ProxtopServerAction::ActionStart,
            1,
            now());

        $this->afterAction();
    }

    /**
     * Starts the grace period: the shutdown action is scheduled in the future.
     * The queue job executes it when it is due, unless it is cancelled before.
     *
     * @return void
     */
    public function requestShutdown(): void
    {
        if (!$this->guardSwitch(VmUiState::Running)) {
            return;
        }

        ProxtopServerAction::createActionIfNotExists($this->observeItem->vm_id,
            ProxtopServerAction::ActionShutDown,
            1,
            now()->addSeconds(self::GRACE_SECONDS));

        $this->afterAction();
    }

    /**
     * Cancels the shutdown during the grace period.
     *
     * The check above only avoids needless work. The real decision is atomic
     * in the database (VmActionGuard): if the job claimed the action in the
     * meantime, nothing is deleted and the re-render shows "busy".
     *
     * @return void
     */
    public function cancelShutdown(): void
    {
        if (!$this->guardSwitch(VmUiState::Grace)) {
            return;
        }

        VmActionGuard::cancelBeforeDue($this->observeItem->vm_id, ProxtopServerAction::ActionShutDown);

        $this->afterAction();
    }

    /**
     * Pre-check for the actions above.
     *
     * Returns false when the UI was stale (state changed meanwhile): the caller
     * just returns and the re-render shows the real state.
     * Aborts with 403 when the user may not switch (ACL or time window).
     * can_switch is the same value the button uses, so UI and server cannot disagree.
     *
     * @param  VmUiState  $expected  state the clicked button was rendered for
     *
     * @return bool
     */
    private function guardSwitch(VmUiState $expected): bool
    {
        $data = $this->observeItemData;

        abort_unless($this->observeItem && $data, 403);

        if (($data['ui_state'] ?? null) !== $expected) {
            return false;
        }

        abort_unless($data['can_switch'] ?? false, 403);

        return true;
    }

    /**
     * Common tail of every action.
     *
     * @return void
     */
    private function afterAction(): void
    {
        // Drop the computed cache, otherwise this response would still render the old state.
        unset($this->observeItemData);

        // Other browsers/users see the new state (e.g. the grace countdown) immediately,
        // not only with the next periodic resource event.
        broadcast(new LocalDesktopUpdated());
    }

    // ------------------------------------------------------------------
    // State
    // ------------------------------------------------------------------

    /**
     * Remaining seconds of the grace period.
     *
     * @return float|null null = no shutdown scheduled; 0.0 = due (the job is about to run);
     *                    > 0 = grace period still running
     */
    private function graceRemaining(): ?float
    {
        $action = ProxtopServerAction::getScheduledAction($this->observeItem->vm_id,
            ProxtopServerAction::ActionShutDown);

        if (!$action) {
            return null;
        }

        // Signed difference: positive while scheduled_at is in the future
        return max(0.0, now()->floatDiffInSeconds($action->scheduled_at, false));
    }

    /**
     * Derives the UI state. PURE: it only reads, it never writes cache or database.
     * (The old calculateStatus() wrote "locked" into the shared Proxmox cache while rendering.)
     *
     * @param  string|null  $proxmoxStatus  status from the payload cache
     * @param  float|null   $graceRemaining
     *
     * @return VmUiState
     */
    private function resolveUiState(?string $proxmoxStatus, ?float $graceRemaining): VmUiState
    {
        // A shutdown action exists: still in the future = grace, otherwise the job is about to run
        if ($graceRemaining !== null) {
            return ($graceRemaining > 0) ? VmUiState::Grace : VmUiState::Busy;
        }

        // Start due/running or pending locks exist: the VM is being switched
        if (ProxtopServerAction::getAnyScheduledAction($this->observeItem->vm_id, [
            ProxtopServerAction::ActionStart,
            ProxtopServerAction::ActionPendingLock,
            ProxtopServerAction::ActionPendingToStart,
            ProxtopServerAction::ActionPendingToShutdown,
        ])
            ->count()
        ) {
            return VmUiState::Busy;
        }

        return match (true) {
            $proxmoxStatus == ProxtopServerVm::StatusRunning => VmUiState::Running,
            $proxmoxStatus == ProxtopServerVm::StatusStopped => VmUiState::Stopped,
            $proxmoxStatus == ProxtopServerVm::StatusPending, $proxmoxStatus == ProxtopServerVm::StatusLocked => VmUiState::Busy,
            default => VmUiState::Unknown,
        };
    }

    /**
     * @param  array   $payload
     * @param  string  $payLoadPath
     *
     * @return array
     */
    public function prepareScheduleVars(array $payload, string $payLoadPath): array
    {
        $v = data_get($payload, $payLoadPath);
        $active = $v !== null && $v <= 0;
        $text = $active
            ? __('Now')
            : (($v !== null) ? CarbonInterval::seconds($v)
                ->spec() : '-');

        return [
            'active' => $active,
            'value'  => $v,
            'text'   => $text,
        ];
    }

    /**
     * All data the card needs. Read-only: no cache writes, no database writes.
     *
     * @throws Exception
     */
    #[Computed]
    public function observeItemData(): array
    {
        // If vm/observeItem was recently disabled, it will not be collected
        // and is null here in next run.
        if (!($this->observeItem->vm ?? null)) {
            return [];
        }

        // get cached vm data received by api
        $this->refreshEnterCount++;
        $d = $this->proxmox->getCachedVmProperty($this->observeItem->vm, null, []);
        if (!$d) {
            return [];
        }
        $this->refreshProcessedCount++;

        // the one state everything else is derived from
        $graceRemaining = $this->graceRemaining();
        $state = $this->resolveUiState($d['status'] ?? null, $graceRemaining);

        // extend this vm data with observable data ...
        // @todo: idk cache here again for observeItem is worth it
        $d['observe_id'] = $this->observeItem->getKey();
        if ($this->observeItem->name_intern) {
            $d['name'] = $this->observeItem->name_intern;
        }
        $d['url'] = $this->observeItem->url ?? $this->observeItem->vm->url;
        // The old condition mixed '??' and '&&' without parentheses and effectively only checked
        // the observe item's media. If it has no final_url, the image from the payload stays.
        if ($this->observeItem->mediaItem->final_url ?? null) {
            $d['image'] = $this->observeItem->mediaItem->final_url;
        }

        // extend it with user specific permissions (acl) ...
        // (The old code wrote into an undefined variable $data, so these reasons were never applied.)
        $aclResources = $this->observeItem->vm->getTemplatesAclResources();
        if ($aclResources['acl_res_start']
            && !$this->system->currentUser()
                ->hasAclResource($aclResources['acl_res_start'])
        ) {
            $d['disallow']['start_reasons'][] = __('Permission denied');
        }
        if ($aclResources['acl_res_shutdown']
            && !$this->system->currentUser()
                ->hasAclResource($aclResources['acl_res_shutdown'])
        ) {
            $d['disallow']['stop_reasons'][] = __('Permission denied');
        }

        $d['ui_state'] = $state;
        // ceil: any running grace period shows at least 1 second
        $d['grace_remaining'] = (int) ceil($graceRemaining ?? 0);

        // Legacy flags, kept so the rest of the blade works unchanged. All derived from the state.
        $d['status_is_running'] = in_array($state, [VmUiState::Running, VmUiState::Grace], true);
        $d['status_has_stopped'] = $state === VmUiState::Stopped;
        $d['status_is_pending'] = $state === VmUiState::Grace;
        $d['status_is_locked'] = $state === VmUiState::Busy;
        $d['status_is_idle'] = $d['is_idle'] ?? 0;

        // livewire don't like objects in payloads ... strange errors!
        // ->spec() would help, but we need the object for frontend, so we have to move this to blade ...
        //$d['uptime_interval'] = CarbonInterval::seconds($d['uptime'] ?: 0)->cascade();

        $d['str_phase1_to_human'] = CarbonInterval::minutes($d['avg_phase1'] ?? 0)
            ->cascade()
            ->forHumans(['short' => true]);
        $d['str_phase2_to_human'] = CarbonInterval::minutes($d['avg_phase2'] ?? 0)
            ->cascade()
            ->forHumans(['short' => true]);
        $d['str_phase3_to_human'] = CarbonInterval::minutes($d['avg_phase3'] ?? 0)
            ->cascade()
            ->forHumans(['short' => true]);

        // prepare handy schedule data
        $d['schedule_can_online_start'] = $this->prepareScheduleVars($d, 'schedules.can_online.starts_in');
        $d['schedule_can_online_end'] = $this->prepareScheduleVars($d, 'schedules.can_online.ends_in');
        $d['schedule_should_online_start'] = $this->prepareScheduleVars($d, 'schedules.should_online.starts_in');
        $d['schedule_should_online_end'] = $this->prepareScheduleVars($d, 'schedules.should_online.ends_in');
        $d['schedule_can_offline_start'] = $this->prepareScheduleVars($d, 'schedules.can_offline.starts_in');
        $d['schedule_can_offline_end'] = $this->prepareScheduleVars($d, 'schedules.can_offline.ends_in');
        $d['schedule_should_offline_start'] = $this->prepareScheduleVars($d, 'schedules.should_offline.starts_in');
        $d['schedule_should_offline_end'] = $this->prepareScheduleVars($d, 'schedules.should_offline.ends_in');

        // Time window rules apply only in the base states. During the grace period they must not
        // apply, otherwise the user could not cancel exactly when the window closes.
        if ($state === VmUiState::Stopped && !($d['schedule_can_online_start']['active'] ?? false)) {
            $d['disallow']['start_reasons'][] = __('Start is currently not allowed');
        }
        if ($state === VmUiState::Running && !($d['schedule_can_offline_start']['active'] ?? false)) {
            $d['disallow']['stop_reasons'][] = __('Stop is currently not allowed');
        }

        // ONE value for "may the user click the switch now?". Used by the button AND by the actions.
        $d['can_switch'] = match ($state) {
            VmUiState::Stopped => empty($d['disallow']['start_reasons']),
            VmUiState::Running, VmUiState::Grace => empty($d['disallow']['stop_reasons']),
            default => false,
        };

        // Popup: only the reasons for the direction the switch would go.
        // (array_merge instead of '+', which silently dropped entries with equal numeric keys)
        $d['switch_popup_content'] = match ($state) {
            VmUiState::Stopped => array_values($d['disallow']['start_reasons'] ?? []),
            VmUiState::Running, VmUiState::Grace => array_values($d['disallow']['stop_reasons'] ?? []),
            default => [],
        };

        $imageUrl = theme_asset('images/no_image_available.jpg'); // No image given, set default
        $imageUrl = ($d['image'] ?? null) ?: $imageUrl;

        if ($d['status_is_pending'] || $d['status_is_locked']) {
            $imageUrl = theme_asset('images/frank-krank.gif');
        }
        $d['image_asset_path'] = $imageUrl;

        return $d;
    }

    // ------------------------------------------------------------------
    // Stable keys (unchanged)
    // ------------------------------------------------------------------

    /**
     * @param  string  $suffix
     *
     * @return string
     */
    public function getStableKey(string $suffix = ''): string
    {
        return parent::getStableKey($suffix).'-'.$this->observeItem?->getKey().'-'.$this->lastDesktopUpdate;
    }

    /**
     * @param  string  $suffix
     *
     * @return string
     */
    public function getStableKeyForServerUpdateRrd(string $suffix = ''): string
    {
        return parent::getStableKey($suffix).'-'.$this->observeItem?->getKey().'-'.$this->lastRrdUpdate;
    }

    /**
     * @param  string  $suffix
     *
     * @return string
     */
    public function getStableKeyForServerUpdate(string $suffix = ''): string
    {
        return parent::getStableKey($suffix).'-'.$this->observeItem?->getKey().'-'.$this->lastServerUpdate;
    }
}