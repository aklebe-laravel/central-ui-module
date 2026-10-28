<?php

namespace Modules\CentralUi\app\Http\Livewire\DataTable;

use Modules\Acl\app\Models\AclResource;
use Modules\WebsiteBase\app\Http\Livewire\DataTable\Base\DefaultDataTable;

/**
 * WireGuard networks (pfSense tunnels) used by the WireGuard members.
 *
 * URL: /manage-data-all/ProxtopWireguardNetwork
 */
class ProxtopWireguardNetwork extends DefaultDataTable
{
    /**
     * Minimum restrictions to allow this component.
     */
    public const array aclResources = [AclResource::RES_DEVELOPER, AclResource::RES_ADMIN];

    /**
     * @var string
     */
    public string $eloquentModelName = \Modules\CentralUi\app\Models\ProxtopWireguardNetwork::class;

    /**
     * Overwrite to init your sort orders before session exists
     *
     * @return void
     */
    protected function initSort(): void
    {
        $this->setSortAllCollections('position', 'asc');
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
                'css_all'    => 'w-px show-lg text-muted font-monospace justify-end text-end',
                'icon'       => 'hash',
            ],
            [
                'name'     => 'position',
                'label'    => __('Position'),
                'format'   => 'number',
                'sortable' => true,
                'css_all'  => 'w-30 show-xl text-muted font-monospace justify-end text-end',
                'icon'     => 'arrow-down-up',
            ],
            [
                'name'       => 'tunnel',
                'label'      => __('Tunnel'),
                'searchable' => true,
                'sortable'   => true,
                'css_all'    => 'w-50 text-left font-monospace',
                'options'    => [
                    'has_open_link' => $this->canEdit(),
                ],
                'icon'       => 'network',
            ],
            [
                'name'       => 'host_group',
                'label'      => __('Host group'),
                'searchable' => true,
                'sortable'   => true,
                'css_all'    => 'w-50 text-left show-sm',
                'icon'       => 'link',
            ],
            [
                'name'       => 'prefix',
                'label'      => __('Prefix'),
                'searchable' => true,
                'sortable'   => true,
                'css_all'    => 'w-30 text-left font-monospace show-md',
                'icon'       => 'binary',
            ],
            [
                'name'       => 'endpoint',
                'label'      => __('Endpoint'),
                'searchable' => true,
                'sortable'   => true,
                'css_all'    => 'w-100 text-left show-lg',
                'icon'       => 'globe',
            ],
            [
                'name'       => 'description',
                'label'      => __('Description'),
                'searchable' => true,
                'sortable'   => true,
                'css_all'    => 'text-left show-xl',
                'icon'       => 'file-text',
            ],
        ];
    }

}
