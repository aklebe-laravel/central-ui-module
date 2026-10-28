<?php


use Modules\CentralUi\app\Models\AclGroup;
use Modules\Acl\app\Models\AclGroup as BaseAclGroup;
use Modules\CentralUi\app\Models\AclResource;

return [
    'version'    => '2.0',
    // class of eloquent model
    'model'      => AclGroup::class,
    // create data if not exists (default true)
    'can_create' => true,
    // can update data if exists and data differ (default false)
    'can_update' => true,
    // columns to check if data already exists (AND WHERE)
    'uniques'    => ['name'],
    // relations to update/create
    'relations'  => [
        'res'   => [
            // relation method which have to exists
            'method'      => 'aclResources',
            // column(s) to find specific #sync_relations items below
            'columns'     => 'code',
            // delete items if not listed here (default: false)
            'auto_delete' => false,
        ],
        'users' => [
            // relation method which have to exists
            'method'      => 'users',
            // column(s) to find specific #sync_relations items below
            'columns'     => 'email',
            // delete items if not listed here (default: false)
            'auto_delete' => false,
        ],
    ],
    // data rows itself
    'data'       => [
        [
            'name'            => AclGroup::GROUP_PROXMOX_OBSERVERS,
            'description'     => 'Can Observe Proxmox VMs',
            '#sync_relations' => [
                'res'   => [
                    AclResource::RES_PROXMOX_OBSERVER,
                ],
                'users' => [
                    'admin@local.test',
                ],
            ],
        ],
        // Also allow to observe for Members
        [
            'name'            => BaseAclGroup::GROUP_MEMBERS,
            '#sync_relations' => [
                'res' => [
                    AclResource::RES_PROXMOX_OBSERVER,
                ],
            ],
        ],
    ],
];
