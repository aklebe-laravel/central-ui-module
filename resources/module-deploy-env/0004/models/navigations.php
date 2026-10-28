<?php

use Modules\Acl\app\Models\AclResource;
use Modules\WebsiteBase\app\Models\Navigation as NavigationModel;

return [
    'version'    => '2.0',
    // class of eloquent model
    'model'      => NavigationModel::class,
    // create data if not exists (default true)
    'can_create' => true,
    // can update data if exists and data differ (default false)
    'can_update' => true,
    // columns to check if data already exists (AND WHERE)
    'uniques'    => ['code'],
    // relations to update/create
    'relations'  => [
        'res' => [
            // relation method which have to exists
            'method'      => 'parent',
            // column(s) to find specific #sync_relations items below
            'columns'     => 'code',
            // delete items if not listed here (default: false)
            'auto_delete' => false,
        ],
    ],
    'data'       => [
        // -------------------------------------------
        // Navigations for Admin > Proxmox
        // -------------------------------------------
        [
            'label'           => 'WireGuard Networks',
            'code'            => 'Admin-Proxmox-wireguard-networks-Menu-L3',
            'route'           => 'manage-data-all',
            'route_params'    => ['ProxtopWireguardNetwork'],
            'acl_resources'   => [AclResource::RES_ADMIN],
            'icon_class'      => 'network',
            '#sync_relations' => [
                'res' => [
                    'Admin-Proxmox-Menu-L2',
                ],
            ],
        ],
    ],
];
