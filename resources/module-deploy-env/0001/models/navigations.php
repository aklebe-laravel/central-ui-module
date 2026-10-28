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
    // -------------------------------------------
    // Navigations for any User
    // -------------------------------------------
    'data'       => [
        [
            'label'         => 'Proxmox', // Proxtop for all useres
            'code'          => 'Proxmox-Menu-L1',
            'acl_resources' => [\Modules\CentralUi\app\Models\AclResource::RES_PROXMOX_OBSERVER],
            'icon_class'    => 'server',
            //'position'      => 920,
        ],
        [
            'label'           => 'Desktop',
            'code'            => 'Proxmox-Desktop-Menu-L3',
            'route'           => 'central-ui.desktop',
            'acl_resources'   => [\Modules\CentralUi\app\Models\AclResource::RES_PROXMOX_OBSERVER],
            'icon_class'      => 'monitor-dot',
            'position'        => 1000,
            '#sync_relations' => [
                'res' => [
                    'Proxmox-Menu-L1',
                ],
            ],
        ],
        [
            'label'           => 'Observable Items',
            'code'            => 'Proxmox-Observable-Items-Menu-L3',
            'route'           => 'manage-data',
            'route_params'    => ['ProxtopObserveServerItem'],
            'acl_resources'   => [\Modules\CentralUi\app\Models\AclResource::RES_PROXMOX_OBSERVER],
            'icon_class'      => 'binoculars',
            'position'        => 2000,
            '#sync_relations' => [
                'res' => [
                    'Proxmox-Menu-L1',
                ],
            ],
        ],
        [
            'label'      => '${{user}}',
            'code'       => 'User-Menu-L1',
            'icon_class' => 'user',
            'position'   => 9000,
        ],
        [
            'label'           => 'Profile',
            'code'            => 'User-Menu-Profile',
            'route'           => 'user-profile',
            'icon_class'      => 'user',
            'position'        => 1000,
            '#sync_relations' => [
                'res' => [
                    'User-Menu-L1',
                ],
            ],
        ],
        [
            'label'           => '',
            'code'            => 'User-Menu-Sep1',
            'position'        => 1200,
            '#sync_relations' => [
                'res' => [
                    'User-Menu-L1',
                ],
            ],
        ],
        [
            'label'           => 'Light/Dark',
            'code'            => 'User-Menu-DarkMode',
            'icon_class'      => 'moon',
            'position'        => 2000,
            'tags'            => ['CONTROL_DARK_MODE'],
            '#sync_relations' => [
                'res' => [
                    'User-Menu-L1',
                ],
            ],
        ],
        [
            'label'           => 'Desktop VM Details',
            'code'            => 'User-Menu-DesktopVmDetails',
            'icon_class'      => 'list-chevrons-down-up',
            'position'        => 2100,
            'tags'            => ['CONTROL_CHANGE_DESKTOP_VM_DETAILS'],
            '#sync_relations' => [
                'res' => [
                    'User-Menu-L1',
                ],
            ],
        ],
        [
            'label'           => 'Media Items',
            'code'            => 'User-Menu-Media-Items-Menu-L2',
            'route'           => 'manage-data',
            'route_params'    => ['MediaItem'],
            'acl_resources'   => [AclResource::RES_MEMBER],
            'icon_class'      => 'book-image',
            'position'        => 3000,
            '#sync_relations' => [
                'res' => [
                    'User-Menu-L1',
                ],
            ],
        ],
        [
            'label'           => '',
            'code'            => 'User-Menu-Sep2',
            'position'        => 8900,
            '#sync_relations' => [
                'res' => [
                    'User-Menu-L1',
                ],
            ],
        ],
        [
            'label'           => 'Logout',
            'code'            => 'User-Menu-Logout',
            'route'           => 'logout',
            'acl_resources'   => [AclResource::RES_MEMBER],
            'icon_class'      => 'log-out',
            'position'        => 9000,
            '#sync_relations' => [
                'res' => [
                    'User-Menu-L1',
                ],
            ],
        ],
        // -------------------------------------------
        // Navigations for Admin
        // -------------------------------------------
        [
            'label'           => 'Media Items',
            'code'            => 'Admin-Menu-Media-Items-L2',
            'route'           => 'manage-data',
            'route_params'    => ['MediaItem'],
            'icon_class'      => 'book-image',
            '#sync_relations' => [
                'res' => [
                    'Admin-Menu-L1',
                ],
            ],
        ],
        // -------------------------------------------
        // Navigations for Admin > Proxmox
        // -------------------------------------------
        [
            'label'           => 'Proxmox', // Proxtop for admins
            'code'            => 'Admin-Proxmox-Menu-L2',
            'acl_resources'   => [AclResource::RES_ADMIN],
            'icon_class'      => 'server',
            //'position'        => 1000,
            '#sync_relations' => [
                'res' => [
                    'Admin-Menu-L1',
                ],
            ],
        ],
        [
            'label'           => 'Servers',
            'code'            => 'Admin-Proxmox-Servers-Menu-L3',
            'route'           => 'manage-data-all',
            'route_params'    => ['ProxtopServer'],
            'icon_class'      => 'server',
            //'position'        => 1000,
            '#sync_relations' => [
                'res' => [
                    'Admin-Proxmox-Menu-L2',
                ],
            ],
        ],
        [
            'label'           => 'VMs',
            'code'            => 'Admin-Proxmox-vms-Menu-L3',
            'route'           => 'manage-data-all',
            'route_params'    => ['ProxtopServerVm'],
            'icon_class'      => 'monitor',
            //'position'        => 1000,
            '#sync_relations' => [
                'res' => [
                    'Admin-Proxmox-Menu-L2',
                ],
            ],
        ],
        [
            'label'           => 'Observable Items',
            'code'            => 'Admin-Proxmox-observable-items-Menu-L3',
            'route'           => 'manage-data-all',
            'route_params'    => ['ProxtopObserveServerItem'],
            'icon_class'      => 'binoculars',
            //'position'        => 1000,
            '#sync_relations' => [
                'res' => [
                    'Admin-Proxmox-Menu-L2',
                ],
            ],
        ],
        [
            'label'           => 'Property Templates',
            'code'            => 'Admin-Proxmox-property-templates-Menu-L3',
            'route'           => 'manage-data-all',
            'route_params'    => ['ProxtopPropertyTemplate'],
            'icon_class'      => 'file-clock',
            //'position'        => 1000,
            '#sync_relations' => [
                'res' => [
                    'Admin-Proxmox-Menu-L2',
                ],
            ],
        ],
        [
            'label'           => 'Property Template Schedules',
            'code'            => 'Admin-Proxmox-property-template-schedules-Menu-L3',
            'route'           => 'manage-data-all',
            'route_params'    => ['ProxtopPropertyTemplateSchedule'],
            'icon_class'      => 'clock-3',
            //'position'        => 1000,
            '#sync_relations' => [
                'res' => [
                    'Admin-Proxmox-Menu-L2',
                ],
            ],
        ],
        [
            'label'           => 'Sayings',
            'code'            => 'Admin-Proxmox-property-template-saying-Menu-L3',
            'route'           => 'manage-data-all',
            'route_params'    => ['Saying'],
            'icon_class'      => 'message-square-quote',
            //'position'        => 1000,
            '#sync_relations' => [
                'res' => [
                    'Admin-Proxmox-Menu-L2',
                ],
            ],
        ],
    ],
];
