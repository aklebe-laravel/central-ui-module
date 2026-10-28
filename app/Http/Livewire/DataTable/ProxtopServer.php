<?php

namespace Modules\CentralUi\app\Http\Livewire\DataTable;

use Modules\WebsiteBase\app\Http\Livewire\DataTable\Base\DefaultDataTable;

class ProxtopServer extends DefaultDataTable
{
    /**
     * @var string
     */
    public string $eloquentModelName = \Modules\CentralUi\app\Models\ProxtopServer::class;

    /**
     * Overwrite to init your sort orders before session exists
     *
     * @return void
     */
    protected function initSort(): void
    {
        //$this->setSortAllCollections('vm_type', 'asc');
        $this->setSortAllCollections('host', 'asc');
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
                'css_all'    => 'w-max show-sm text-muted font-monospace justify-end text-end',
                'icon'       => 'hash',
            ],
            [
                'name'       => 'api_allowed',
                'label'      => __('API Allowed'),
                'view'       => 'data-table::livewire.js-dt.tables.columns.bool-red-green',
                'css_all'    => 'w-max show-lg text-center',
                'sortable'   => true,
                'icon'       => 'check-circle',
            ],
            [
                'name'       => 'datacenter_id',
                'label'      => __('Datacenter'),
                'css_all'    => 'w-max justify-center text-center show-lg',
                'sortable'   => true,
                //'view'     => 'central-ui::livewire.js-dt.tables.columns.server',
                'icon'       => 'server',
            ],
            [
                'name'       => 'host',
                'label'      => __('Host'),
                'searchable' => true,
                'sortable'   => true,
                'css_all'    => 'w-full text-left',
                'options'    => [
                    'has_open_link' => $this->canEdit(),
                    // 'str_limit'     => 30,
                ],
                //'view'       => 'central-ui::livewire.js-dt.tables.columns.vm.name',
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
        ];
    }

}
