<?php
declare(strict_types = 1);

namespace Modules\CentralUi\app\Http\Livewire;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\On;
use Modules\CentralUi\app\Models\ProxtopObserveServerItem;
use Modules\CentralUi\app\Models\ProxtopServerVm;
use Modules\CentralUi\app\Services\ProxmoxService;
use Modules\SystemBase\app\Http\Livewire\BaseComponent;
use Modules\WebsiteBase\app\Services\Setting;

class VmDesktop extends BaseComponent
{
    /**
     * Values of the user setting 'desktop_layout'
     */
    public const string LAYOUT_COMPACT = 'compact';
    public const string LAYOUT_ICONS = 'icons';
    public const string LAYOUT_LIST = 'list';

    /**
     * While the websocket (Reverb) is disconnected, the desktop asks all cards to refresh this often.
     * Connected: no polling at all, the cards get pushed events.
     */
    public const int LIVE_UPDATE_FALLBACK_SECONDS = 20;

    public const array LAYOUTS = [
        self::LAYOUT_COMPACT,
        self::LAYOUT_ICONS,
        self::LAYOUT_LIST,
    ];

    /**
     * Values of the user setting 'desktop_vm_details'.
     * The stored values stay as they are ('compact' is still the value for "brief"),
     * only the labels changed to avoid confusion with the layout "compact".
     */
    public const string DETAILS_HIDE = '';
    public const string DETAILS_BRIEF = 'compact';
    public const string DETAILS_FULL = 'comfortable';

    /**
     * value => label (not translated)
     *
     * @return array<string, string>
     */
    public static function layoutOptions(): array
    {
        return [
            self::LAYOUT_COMPACT => 'Compact',
            self::LAYOUT_ICONS   => 'Icons',
            self::LAYOUT_LIST    => 'List',
        ];
    }

    /**
     * value => label (not translated)
     *
     * @return array<string, string>
     */
    public static function detailOptions(): array
    {
        return [
            self::DETAILS_HIDE  => 'Hide Details',
            self::DETAILS_BRIEF => 'Brief',
            self::DETAILS_FULL  => 'Comfortable',
        ];
    }

    /**
     * @var array
     */
    protected array $wholePayload = [];

    /**
     * @var string
     */
    public string $statusMessage = '';

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
     * @var ?Collection|null
     */
    public ?Collection $observeItemIds = null;

    /**
     * @var ?string
     */
    public ?string $globalDesktopVmDetails = '';

    /**
     * One of self::LAYOUTS (user setting 'desktop_layout')
     *
     * @var string
     */
    public string $globalDesktopLayout = self::LAYOUT_COMPACT;

    /**
     * @var ProxmoxService
     */
    protected ProxmoxService $proxmox;

    /**
     * @var Setting
     */
    protected Setting $system;

    /**
     * @param  string  $state
     *
     * @return void
     */
    #[On('toggle-details')]
    public function toggle(string $state): void
    {
        $this->globalDesktopVmDetails = $state;
    }

    /**
     * @param  string  $layout
     *
     * @return void
     */
    #[On('toggle-layout')]
    public function toggleLayout(string $layout): void
    {
        $this->globalDesktopLayout = $this->sanitizeLayout($layout);
    }

    /**
     * wire:key of a desktop card (VmStatus).
     * Contains layout and details, so a change of these settings (user menu or profile)
     * mounts all cards fresh instead of morphing the old markup.
     *
     * @param  int|string  $observeItemId
     *
     * @return string
     */
    public function getCardKey(int|string $observeItemId): string
    {
        return sprintf('desktop-observe-item-%s-%s-%s',
            $observeItemId,
            $this->globalDesktopLayout,
            $this->globalDesktopVmDetails ?: 'none');
    }

    /**
     * Unknown values fall back to the compact layout.
     *
     * @param  mixed  $layout
     *
     * @return string
     */
    public function sanitizeLayout(mixed $layout): string
    {
        return in_array($layout, self::LAYOUTS, true) ? $layout : self::LAYOUT_COMPACT;
    }

    /**
     * @return Application|Factory|View
     */
    public function render(): Factory|View|Application
    {
        return view('central-ui::livewire.vm-desktop');
    }

    /**
     * Echo-Event syntax: "echo:{channel},{event}"
     *
     * @return void
     */
    #[On('echo:proxmox-rrd-updates,.Modules\CentralUi\app\Events\ProxmoxApiRrdDataUpdated')]
    public function receiveApiRrdUpdates(): void
    {
        //$this->lastRrdUpdate = time();
    }

    /**
     * @return void
     */
    #[On('echo:proxmox-resource-updates,.Modules\CentralUi\app\Events\ProxmoxApiResourceDataUpdated')]
    public function receiveApiResourcesUpdates(): void
    {
        //$this->lastServerUpdate = time();
    }

    /**
     * @return void
     */
    #[On('echo:vm-updates,.Modules\CentralUi\app\Events\LocalDesktopUpdated')]
    #[On('vm-desktop:refresh')]
    public function receiveDesktopUpdates(): void
    {
        //$this->lastDesktopUpdate = time();
    }

    /**
     * Runs once, immediately after the component is instantiated, but before render() is called.
     * This is only called once on initial page load and never called again, even on component refreshes
     *
     * @return void
     */
    protected function init(): void
    {
        parent::init();

        $this->proxmox = app(ProxmoxService::class);
        $this->system = app(Setting::class);
        $this->wholePayload = $this->proxmox->getCache('resources', []);
        $this->observeItemIds = $this->getObserveItemIds();
    }

    /**
     * Runs on every request, after the component is mounted or hydrated, but before any update methods are called
     *
     * @return void
     */
    protected function initBooted(): void
    {
        parent::initBooted();

        if ($currentUser = app('website_base_settings')->currentUser()) {
            $this->globalDesktopVmDetails = $currentUser->getExtraAttribute('desktop_vm_details', '');
            $this->globalDesktopLayout = $this->sanitizeLayout($currentUser->getExtraAttribute('desktop_layout',
                self::LAYOUT_COMPACT));
        }
    }

    /**
     * @return Collection
     */
    protected function getObserveItemIds(): Collection
    {
        $user = app('website_base_settings')->currentUser();

        // load the user's acl codes once for all items
        $userAclCodes = ProxtopServerVm::getUserAclCodes($user);

        return $user->observeItems()
            ->whereIsEnabledAndHasActiveServer()
            ->with('vm.enabledPropertyTemplates')
            ->orderBy('position')
            ->get()
            // hide items whose vm is no longer visible for this user (acl_res_view)
            ->filter(fn(ProxtopObserveServerItem $item) => (bool) $item->vm?->isVisibleForAclCodes($userAclCodes))
            ->pluck('id')
            ->values();
    }

}