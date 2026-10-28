<?php

namespace Modules\CentralUi\app\Http\Livewire\DataTable;

use Modules\WebsiteBase\app\Http\Livewire\DataTable\Base\DefaultDataTable;

class ProxtopServerVm extends DefaultDataTable
{
    /**
     * @var string
     */
    public string $eloquentModelName = \Modules\CentralUi\app\Models\ProxtopServerVm::class;

    /**
     * Relations loaded with getBaseBuilder()
     * This is also important to lo load pivots automatically.
     *
     * @var array
     */
    public array $objectRelations = ['mediaItem'];

    /**
     * Overwrite to init your sort orders before session exists
     *
     * @return void
     */
    protected function initSort(): void
    {
        //$this->setSortAllCollections('vm_type', 'asc');
        $this->setSortAllCollections('extern_vm_id', 'asc');
    }

    /**
     * @return array[]
     */
    public function getColumns(): array
    {
        return [
            [
                'name'       => 'server_id',
                'label'      => __('Server'),
                'css_all'    => 'show-lg',
                'css_header' => 'w-px whitespace-nowrap',
                'sortable'   => true,
                'view'       => 'central-ui::livewire.js-dt.tables.columns.server',
                'icon'       => 'server',
            ],
            [
                'name'       => 'extern_vm_id',
                'label'      => __('External VM id'),
                'css_all'    => 'show-sm text-end',
                'css_header' => 'w-20 truncate',
                'searchable' => true,
                'sortable'   => true,
                //'view'     => 'data-table::livewire.js-dt.tables.columns.vm',
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
                'name'       => 'vm_type',
                'label'      => __('VM Type'),
                'css_all'    => 'show-lg text-left',
                'css_header' => 'w-30 truncate whitespace-nowrap',
                'searchable' => true,
                'sortable'   => true,
                //'view'     => 'data-table::livewire.js-dt.tables.columns.vm',
                'icon'       => 'tag',
            ],
            [
                'name'       => 'name',
                'label'      => __('Name'),
                'searchable' => true,
                'sortable'   => true,
                'css_all'    => 'text-left',
                'css_header' => 'w-[40%] truncate',
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
                'css_all'    => 'text-left show-xl',
                'css_header' => 'w-[60%] truncate',
                'icon'       => 'file-text',
            ],
        ];
    }

}
