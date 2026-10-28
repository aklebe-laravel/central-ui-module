<?php

namespace Modules\CentralUi\app\Http\Livewire\DataTable;

use Modules\WebsiteBase\app\Http\Livewire\DataTable\Base\DefaultDataTable;

class Saying extends DefaultDataTable
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
    public array $objectRelations = ['tags2'];

    /**
     * @var string
     */
    public string $eloquentModelName = \Modules\CentralUi\app\Models\Saying::class;

    /**
     * Should be overridden if more filters needed or existing filters not wanted!
     *
     * @return void
     */
    protected function initFilters(): void
    {
        parent::initFilters();
        $this->setDefaultFilter('rows_per_page.default', 100);
    }

    /**
     * Overwrite to init your sort orders before session exists
     *
     * @return void
     */
    protected function initSort(): void
    {
        //$this->setSortAllCollections('user_id', 'asc');
        $this->setSortAllCollections('author', 'asc');
        //$this->setSortAllCollections('name', 'asc');
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
                'name'       => 'author',
                'label'      => __('Author'),
                'searchable' => true,
                'sortable'   => true,
                'css_all'    => 'w-fit text-left show-md',
                'options'    => [
                    'has_open_link' => $this->canEdit(),
                    // 'str_limit'     => 30,
                ],
                'icon'       => 'user',
            ],
            [
                'name'       => 'content',
                'label'      => __('Saying'),
                'searchable' => true,
                'sortable'   => true,
                'css_all'    => 'w-full text-left',
                'options'    => [
                    'has_open_link' => $this->canEdit(),
                    // 'str_limit'     => 30,
                ],
                'icon'       => 'message-square-quote',
            ],
            [
                'name'       => 'tags2',
                'label'      => __('Tags'),
                'searchable' => true,
                'sortable'   => true,
                'css_all'    => 'w-fit text-left show-xl',
                'view'       => 'central-ui::livewire.js-dt.tables.columns.saying.tags',
                'icon'       => 'file-text',
            ],
        ];
    }

}
