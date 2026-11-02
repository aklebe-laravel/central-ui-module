<?php

declare(strict_types = 1);

namespace Modules\CentralUi\app\Http\Livewire\DataTable;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection as SupportCollection;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Modules\CentralUi\app\Http\Livewire\VmDesktop;
use Modules\CentralUi\app\Models\ProxtopObserveServerItem as ObserveItemModel;
use Modules\CentralUi\app\Models\ProxtopServerVm;

/**
 * The VM cards of the desktop (inside VmDesktop): the observe items of the current user that are enabled,
 * on an active server and visible by acl, in their fixed order ('position'), searchable, all on one page.
 *
 * Every item is its own VmStatus component (live updates, switch). Layout and details come from VmDesktop
 * (user settings): compact and icons use the cards template (search only, all items), the list layout
 * the standard rows with the full header (filters, rows per page, pagination): name (opens the form),
 * status (VmStatus: stats and switch) and the actions.
 *
 * Extends the admin table of the observe items: column views, form link, actions, position
 * and the desktop broadcasts of deleteItem() and updateOrder() are inherited.
 */
class VmDesktopItems extends ProxtopObserveServerItem
{
    /**
     * Same as VmDesktop: no extra restriction, the items are filtered per user and acl.
     */
    public const array aclResources = [];

    /**
     * One of VmDesktop::LAYOUTS: user setting 'desktop_layout', read on every request (see initBooted())
     *
     * @var string
     */
    #[Locked]
    public string $layout = VmDesktop::LAYOUT_COMPACT;

    /**
     * User setting 'desktop_vm_details', read on every request (see initBooted())
     *
     * @var string|null
     */
    #[Locked]
    public ?string $density = '';

    /**
     * Set by applyLayout()
     *
     * @var string
     */
    #[Locked]
    public string $tableView = self::TABLE_VIEW_CARDS;

    #[Locked]
    public string $cardView = 'central-ui::data-tables.vm-desktop.card';

    #[Locked]
    public string $cardsGridCss = '';

    #[Locked]
    public string $cardsWrapperCss = '';

    #[Locked]
    public string $cardsBodyCss = '';

    /**
     * Set by applyLayout()
     *
     * @var string
     */
    public string $headerView = self::CARDS_HEADER_VIEW;

    public string $footerView = '';

    /**
     * Header of the cards layouts: search only (no rows, no pagination, no counter)
     */
    protected const string CARDS_HEADER_VIEW = 'central-ui::data-tables.vm-desktop.header';

    /**
     * Name column opens this form (rendered by VmDesktop)
     *
     * @var string
     */
    public string $relatedLivewireForm = 'central-ui::form.proxtop-observe-server-item';

    public bool $editable = true;

    public bool $selectable = false;

    public bool $canAddRow = false;

    /**
     * @var array|true[]
     */
    public array $enabledCollectionNames = [
        self::COLLECTION_NAME_DEFAULT => true,
    ];

    /**
     * Rows come from getFixCollection() (acl is checked per item), search runs in memory.
     *
     * @var bool
     */
    protected bool $processFixCollection = true;

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
        return sprintf('desktop-observe-item-%s-%s-%s', $observeItemId, $this->layout, $this->density ?: 'none');
    }

    /**
     * Sent by UserDesktopSettings after a setting was saved: the next render reads layout and details
     * again (initBooted()), so the desktop switches at once. Also reloads the items.
     *
     * @return void
     */
    #[On('vm-desktop:refresh')]
    public function refreshItems(): void
    {
    }

    /**
     * Runs on every request: the user settings decide layout and details.
     *
     * @return void
     */
    protected function initBooted(): void
    {
        parent::initBooted();

        if ($user = app('website_base_settings')->currentUser()) {
            $this->density = (string) $user->getExtraAttribute('desktop_vm_details', VmDesktop::DETAILS_HIDE);
            $this->layout = VmDesktop::sanitizeLayout($user->getExtraAttribute('desktop_layout', VmDesktop::LAYOUT_COMPACT));
        }
    }

    /**
     * Visible observe items of the current user, ordered by 'position' (no sortable columns, the order is fixed).
     *
     * @param  string  $collectionName
     *
     * @return SupportCollection|null
     */
    public function getFixCollection(string $collectionName): ?SupportCollection
    {
        if (!($user = app('website_base_settings')->currentUser())) {
            return collect();
        }

        // load the user's acl codes once for all items
        $userAclCodes = ProxtopServerVm::getUserAclCodes($user);

        return $user->observeItems()
            ->whereIsEnabledAndHasActiveServer()
            ->with('vm.enabledPropertyTemplates')
            ->orderBy('position')
            ->get()
            // hide items whose vm is no longer visible for this user (acl_res_view)
            ->filter(fn(ObserveItemModel $item) => (bool) $item->vm?->isVisibleForAclCodes($userAclCodes))
            ->values();
    }

    /**
     * List layout: name (opens the form), VM id and status (VmStatus: image, stats and switch), the actions are added
     * by the base class. The other columns are for the search and the fixed order, the cards render VmStatus.
     *
     * @return array[]
     */
    public function getColumns(): array
    {
        return [
            [
                'name'       => 'name_intern',
                'label'      => __('Name'),
                'searchable' => true,
                'css_all'    => 'w-[30%] text-left',
                'options'    => [
                    'has_open_link' => $this->canEdit(),
                ],
                'view'       => 'central-ui::livewire.js-dt.tables.columns.vm.name',
                'icon'       => 'monitor',
            ],
            [
                'name'    => 'vm.extern_vm_id',
                'label'   => __('ID'),
                'format'  => 'number',
                'css_all' => 'show-md w-20 text-end text-muted',
                'icon'    => 'hash',
            ],
            [
                'name'    => 'vm_status',
                'label'   => __('Status'),
                'view'    => 'central-ui::data-tables.vm-desktop.list-row',
                // VmStatus brings its own paddings
                'css_all' => 'p-0! text-left',
                'icon'    => 'activity',
            ],
            [
                'name'       => 'vm.name',
                'label'      => __('VM'),
                'visible'    => false,
                'searchable' => true,
            ],
            [
                'name'       => 'vm.description',
                'label'      => __('Description'),
                'visible'    => false,
                'searchable' => true,
            ],
            [
                'name'       => 'description',
                'label'      => __('Description'),
                'visible'    => false,
                'searchable' => true,
            ],
            [
                // the fixed order (see initSort() of the parent)
                'name'     => $this->columnPosition,
                'label'    => __('Position'),
                'visible'  => false,
                'sortable' => true,
            ],
        ];
    }

    /**
     * @return Application|Factory|View
     */
    public function render(): Factory|View|Application
    {
        $this->applyLayout();

        return parent::render();
    }

    /**
     * The cards layouts show all items on one page, rows per page and pagination are for the list layout only.
     *
     * @param  string  $collectionName
     *
     * @return int
     */
    public function getPaginationRowsPerPage(string $collectionName): int
    {
        return $this->isListLayout() ? parent::getPaginationRowsPerPage($collectionName) : PHP_INT_MAX;
    }

    /**
     * @param  string  $collectionName
     *
     * @return int
     */
    public function getPaginationCurrentPage(string $collectionName): int
    {
        return $this->isListLayout() ? parent::getPaginationCurrentPage($collectionName) : 1;
    }

    /**
     * @return bool
     */
    protected function isListLayout(): bool
    {
        return $this->layout === VmDesktop::LAYOUT_LIST;
    }

    /**
     * Template and grid of the current layout (former vm-desktop.blade.php).
     *
     * @return void
     */
    protected function applyLayout(): void
    {
        $this->headerView = self::CARDS_HEADER_VIEW;
        $this->footerView = '';

        switch ($this->layout) {
            case VmDesktop::LAYOUT_LIST:
                // standard rows with the full header, one row per VM (see getColumns())
                $this->tableView = self::TABLE_VIEW_ROWS;
                $this->headerView = 'data-table::livewire.js-dt.tables.headers.default';
                $this->footerView = 'data-table::livewire.js-dt.tables.footers.default';

                return;

            case VmDesktop::LAYOUT_ICONS:
                // the compact card, smaller and denser (2 on mobile up to 10 on the largest screens)
                $this->tableView = self::TABLE_VIEW_CARDS;
                $this->cardsGridCss = 'grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 xl:grid-cols-8 2xl:grid-cols-10 gap-2';
                break;

            default:
                // compact: 1 card on mobile up to 6 on the largest screens
                $this->tableView = self::TABLE_VIEW_CARDS;
                $this->cardsGridCss = 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 2xl:grid-cols-6 gap-4';
                break;
        }
    }
}
