<?php

namespace Modules\CentralUi\app\Http\Livewire\DataTable;

use Modules\WebsiteBase\app\Http\Livewire\DataTable\Base\DefaultDataTable;

class ProxtopPropertyTemplate extends DefaultDataTable
{
    /**
     * @var string
     */
    public string $eloquentModelName = \Modules\CentralUi\app\Models\ProxtopPropertyTemplate::class;

    /**
     * Relations loaded with getBaseBuilder()
     * This is also important to lo load pivots automatically.
     *
     * @var array
     */
    public array $objectRelations = ['schedules', 'vms', 'tags2'];

    /**
     * Overwrite to init your sort orders before session exists
     *
     * @return void
     */
    protected function initSort(): void
    {
        $this->setSortAllCollections('name', 'asc');
    }

    /**
     * @return array[]
     */
    public function getColumns(): array
    {
        return [
            [
                'name'       => 'id',
                'label'      => 'ID',
                'format'     => 'number',
                'searchable' => true,
                'sortable'   => true,
                'css_all'    => 'show-lg w-max text-muted font-monospace justify-end text-end',
                'icon'       => 'hash',
            ],
            [
                'name'       => 'is_enabled',
                'label'      => __('Enabled'),
                'view'       => 'data-table::livewire.js-dt.tables.columns.bool-gray-green',
                'css_all'    => 'w-max show-lg text-center',
                'sortable'   => true,
                'icon'       => 'check-circle',
            ],
            [
                'name'       => 'name',
                'label'      => __('Template Name'),
                'searchable' => true,
                'sortable'   => true,
                'css_all'    => 'w-fit text-left',
                'options'    => [
                    'has_open_link' => $this->canEdit(),
                    // 'str_limit'     => 30,
                ],
                'view'       => 'central-ui::livewire.js-dt.tables.columns.property_template.name',
                'icon'       => 'monitor',
            ],
            [
                'name'       => 'description',
                'label'      => __('Description'),
                'searchable' => true,
                'sortable'   => true,
                'css_all'    => 'w-fit text-left show-md',
                'view'       => 'central-ui::livewire.js-dt.tables.columns.property_template.description',
                'icon'       => 'file-text',
            ],
        ];
    }

}
