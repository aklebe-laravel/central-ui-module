<?php

use Modules\WebsiteBase\app\Models\Base\ExtraAttributeModel;
use Modules\WebsiteBase\app\Models\ModelAttribute;

return [
    'version'    => '2.0',
    // class of eloquent model
    'model'      => ModelAttribute::class,
    // create data if not exists (default true)
    'can_create' => true,
    // can update data if exists and data differ (default false)
    'can_update' => true,
    // columns to check if data already exists (AND WHERE)
    'uniques'    => ['code'], // Not ['module','code'] in this version!
    // data rows itself
    'data'       => [
        [
            'module'      => 'central-ui',
            'code'        => 'dark_mode',
            'description' => 'Dark/Light Mode',
        ],
        [
            'module'      => 'central-ui',
            'code'        => 'desktop_vm_details',
            'description' => 'Show more or less information on VMs on your desktop',
        ],

    ],
];

