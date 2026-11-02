<?php

namespace Modules\CentralUi\app\Http\Livewire\DataTable;

use Livewire\Attributes\On;
use Modules\CentralUi\app\Events\LocalDesktopUpdated;
use Modules\CentralUi\app\Services\SafeBroadcast;
use Modules\WebsiteBase\app\Http\Livewire\DataTable\Base\DefaultDataTable;

class ProxtopObserveServerItem extends DefaultDataTable
{
    /**
     * Minimum restrictions to allow this component.
     */
    public const array aclResources = [];

    /**
     * Relations loaded with getBaseBuilder()
     * This is also important to lo load pivots automatically.
     *
     * @var array
     */
    public array $objectRelations = [
        'user',
        'vm',
        'mediaItem',
    ];


    /**
     * @var string
     */
    public string $eloquentModelName = \Modules\CentralUi\app\Models\ProxtopObserveServerItem::class;

    /**
     * Overwrite this to be able to filter by users.
     *
     * @var string
     */
    public string $columnUserId = 'user_id';

    /**
     * Overwrite this to be able to order rows by this value by default.
     *
     * @var string
     */
    public string $columnPosition = 'position';

    /**
     * Overwrite to init your sort orders before session exists
     *
     * @return void
     */
    protected function initSort(): void
    {
        $this->setSortAllCollections($this->columnPosition, 'asc');
    }

    /**
     * @return array[]
     */
    public function getColumns(): array
    {
        return [
            [
                'name'       => 'vm.extern_vm_id',
                'label'      => __('VM'),
                'css_all'    => 'w-max show-lg justify-end text-end',
                'searchable' => true,
                'sortable'   => true,
                'icon'       => 'monitor',
            ],
            [
                'name'     => 'is_enabled',
                'label'    => __('Enabled'),
                'view'     => 'central-ui::livewire.js-dt.tables.columns.vm.enabled',
                'css_all'  => 'w-max show-lg text-center',
                'sortable' => true,
                'icon'     => 'check-circle',
            ],
            [
                'name'     => 'user_id',
                'label'    => __('User'),
                'css_all'  => 'w-max justify-center text-center show-lg',
                'sortable' => true,
                'view'     => 'data-table::livewire.js-dt.tables.columns.user',
                'icon'     => 'user',
            ],
            [
                'name'       => 'media_item_id',
                'label'      => __('Image'),
                'view'       => 'website-base::livewire.js-dt.tables.columns.media-item',
                'css_all'    => 'show-lg',
                'css_header' => 'w-40 truncate',
                'icon'       => 'book-image',
                'options'    => [
                    'has_open_link' => $this->canEdit(),
                ],
            ],
            [
                'name'       => 'name_intern',
                'label'      => __('Name'),
                'searchable' => true,
                'sortable'   => true,
                'css_all'    => 'w-fit text-left',
                'options'    => [
                    'has_open_link' => $this->canEdit(),
                    // 'str_limit'     => 30,
                ],
                'view'       => 'central-ui::livewire.js-dt.tables.columns.vm.name',
                'icon'       => 'monitor',
            ],
            [
                'name'       => 'description',
                'label'      => __('Description'),
                'searchable' => true,
                'sortable'   => true,
                'css_all'    => 'w-fit text-left show-md',
                'icon'       => 'file-text',
            ],
            [
                'name'       => $this->columnPosition,
                'label'      => __('Position'),
                'view'       => 'data-table::livewire.js-dt.tables.columns.number',
                'visible'    => true,
                'searchable' => true,
                'sortable'   => true,
                'css_all'    => 'w-fit justify-end text-end',
                'icon'       => 'list-ordered',
            ],
        ];
    }

    /**
     * @param  mixed  $livewireId
     * @param  mixed  $itemId
     *
     * @return bool
     * @throws \Exception
     */
    #[On('delete-item')]
    public function deleteItem(mixed $livewireId, mixed $itemId): bool
    {
        if ($result = parent::deleteItem($livewireId, $itemId)) {

            // update desktops
            SafeBroadcast::send(new LocalDesktopUpdated());
        }

        return $result;
    }

    /**
     * @param            $collectionName
     * @param            $movedId
     * @param  int|null  $prevId
     * @param  int|null  $nextId
     *
     * @return void
     * @throws \Exception
     */
    public function updateOrder($collectionName, $movedId, ?int $prevId = null, ?int $nextId = null): void
    {
        parent::updateOrder($collectionName, $movedId, $prevId, $nextId);

        // update desktops
        SafeBroadcast::send(new LocalDesktopUpdated());
    }

}
