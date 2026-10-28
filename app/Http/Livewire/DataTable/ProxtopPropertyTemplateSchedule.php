<?php

namespace Modules\CentralUi\app\Http\Livewire\DataTable;

use Modules\WebsiteBase\app\Http\Livewire\DataTable\Base\DefaultDataTable;

class ProxtopPropertyTemplateSchedule extends DefaultDataTable
{
    /**
     * @var string
     */
    public string $eloquentModelName = \Modules\CentralUi\app\Models\ProxtopPropertyTemplateSchedule::class;

    /**
     * Relations loaded with getBaseBuilder()
     * This is also important to lo load pivots automatically.
     *
     * @var array
     */
    public array $objectRelations = ['template'];

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
                'css_all'    => 'show-sm text-muted font-monospace justify-end text-end',
                'css_header' => 'w-20 whitespace-nowrap text-nowrap',
                'icon'       => 'hash',
            ],
            [
                'name'       => 'is_enabled',
                'label'      => __('Enabled'),
                'view'       => 'data-table::livewire.js-dt.tables.columns.bool-gray-green',
                'css_all'    => 'show-lg text-center',
                'css_header' => 'w-20 whitespace-nowrap text-nowrap',
                'sortable'   => true,
                'icon'       => 'check-circle',
            ],
            [
                'name'       => 'template.name',
                'label'      => __('Name'),
                'searchable' => true,
                'sortable'   => true,
                'css_all'    => 'text-left',
                'css_header' => 'w-full/30',
                'options'    => [
                    'has_open_link' => $this->canEdit(),
                    // 'str_limit'     => 30,
                ],
                'view'       => 'central-ui::livewire.js-dt.tables.columns.vm.name',
                'icon'       => 'monitor',
            ],
            [
                'name'       => 'command_code',
                'label'      => __('Command Code'),
                'searchable' => true,
                'sortable'   => true,
                'css_all'    => 'text-left show-md',
                'css_header' => 'w-full/70',
                'icon'       => 'file-text',
            ],
            [
                'name'       => 'cron_expression',
                'label'      => __('Cron expression'),
                'searchable' => true,
                'sortable'   => true,
                'css_all'    => 'text-left show-md',
                'css_header' => 'w-full/70',
                'icon'       => 'file-text',
            ],
        ];
    }

}
