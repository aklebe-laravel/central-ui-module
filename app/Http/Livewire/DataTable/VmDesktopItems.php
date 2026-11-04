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
 * (user settings): compact and icons use the cards template (all items), the list layout the standard rows
 * with rows per page and pagination: name (opens the form), status (VmStatus: stats and switch) and the actions.
 *
 * The header is the toolbar of the desktop (HEADER_VIEW_TOOLBAR) in every layout: search, then the elements of
 * config 'central-ui.desktop_toolbar' (backups, server power, ... modules can add their own).
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
     * The toolbar in every layout, see applyLayout()
     *
     * @var string
     */
    public string $headerView = self::HEADER_VIEW_TOOLBAR;

    public string $footerView = '';

    /**
     * Set by applyLayout(): only the list layout has pages
     *
     * @var bool
     */
    public bool $headerPagination = false;

    /**
     * Livewire event: a message for the messages of the desktop (shown above the toolbar), e.g. from a toolbar
     * element like ServerPower. Params: type ('success', 'error', 'info', 'warning'), message.
     */
    public const string EventMessage = 'vm-desktop:message';

    /**
     * Header elements of the cards layouts besides the toolbar elements (other filters only in the list layout).
     */
    protected const array CARDS_HEADER_ELEMENTS = ['search'];

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
     * See EventMessage.
     *
     * @param  string  $type
     * @param  string  $message
     *
     * @return void
     */
    #[On(self::EventMessage)]
    public function showDesktopMessage(string $type = 'info', string $message = ''): void
    {
        if ($message !== '') {
            $this->addMessage($message, in_array($type, ['success', 'error', 'info', 'warning'], true) ? $type : 'info');
        }
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
     * Toolbar elements: config 'central-ui.desktop_toolbar' of this module, merged with
     * 'combined-module-central-ui.desktop_toolbar' of other modules (their file config/combined-module-central-ui.php).
     * An entry with 'view' => null removes an element.
     *
     * @return array<string, array{view?: ?string, position?: int, css_group?: string}>
     */
    public static function toolbarElements(): array
    {
        $elements = array_replace_recursive((array) config('central-ui.desktop_toolbar', []),
            (array) config('combined-module-central-ui.desktop_toolbar', []));

        return array_filter($elements, fn($element) => is_array($element) && !empty($element['view']));
    }

    /**
     * Filters of the parent plus the toolbar elements (see toolbarElements()):
     * name => ['view' => ..., 'position' => ..., 'css_group' => ...]
     *
     * @return void
     */
    protected function initFilters(): void
    {
        parent::initFilters();

        foreach (self::toolbarElements() as $name => $element) {
            $this->addHeaderElement((string) $name, (string) $element['view'], (int) ($element['position'] ?? 5000), (string) ($element['css_group'] ?? ''));
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
     * Template and grid of the current layout (former vm-desktop.blade.php) and the toolbar elements for it.
     *
     * @return void
     */
    protected function applyLayout(): void
    {
        $isList = $this->isListLayout();

        $this->headerView = self::HEADER_VIEW_TOOLBAR;
        $this->headerPagination = $isList;
        $this->footerView = $isList ? 'data-table::livewire.js-dt.tables.footers.default' : '';

        // search grows, its settings menu (new entry, export, ...) only in the list layout
        $this->setDefaultFilter('search.view',
            $isList ? 'data-table::livewire.js-dt.filters.default-elements.search-and-settings' : 'data-table::livewire.js-dt.filters.default-elements.text');
        // min-w-0 on mobile: the search shrinks, so backups and server power stay in the same row
        $this->setDefaultFilter('search.css_group', 'flex-1 min-w-0 sm:min-w-48 max-w-md');

        // the other filters (rows per page, ...) only in the list layout, the toolbar elements always
        $toolbarElements = array_keys(self::toolbarElements());
        foreach (array_keys($this->filterElementConfig) as $name) {
            if (in_array($name, self::CARDS_HEADER_ELEMENTS, true) || in_array($name, $toolbarElements, true)) {
                continue;
            }
            // rows per page etc.: list layout only, not on mobile (room for the toolbar elements)
            $this->setDefaultFilter($name.'.css_group', $isList ? 'hidden sm:block w-28' : 'hidden');
        }

        switch ($this->layout) {
            case VmDesktop::LAYOUT_LIST:
                // standard rows, one row per VM (see getColumns())
                $this->tableView = self::TABLE_VIEW_ROWS;

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
