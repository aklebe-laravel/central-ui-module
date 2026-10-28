<?php

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
        // User menu: quick switch for the desktop layout
        // (rendered as control by the tag, see central-ui::components.menu-control)
        // -------------------------------------------
        [
            'label'           => 'Desktop Layout',
            'code'            => 'User-Menu-DesktopLayout',
            'icon_class'      => 'layout-grid',
            'position'        => 2050,
            'tags'            => ['CONTROL_CHANGE_DESKTOP_LAYOUT'],
            '#sync_relations' => [
                'res' => [
                    'User-Menu-L1',
                ],
            ],
        ],
    ],
];
