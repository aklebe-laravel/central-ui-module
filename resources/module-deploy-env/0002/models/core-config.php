<?php

use Modules\WebsiteBase\app\Models\CoreConfig;

return [
    'version'              => '2.0',
    // class of eloquent model
    'model'                => CoreConfig::class,
    // update data if exists and data differ (default false)
    'can_update'           => true,
    // if update true only: don't update this fields
    'ignore_update_fields' => [
        'value',
    ],
    // columns to check if data already exists (AND WHERE)
    'uniques'              => ['store_id', 'module', 'path'],
    // data rows itself
    'data'                 => [
        [
            'store_id'    => null,
            'module'      => 'central-ui',
            'path'        => 'scheduler.api.resource.interval',
            'value'       => 30,
            'position'    => 1000,
            'label'       => 'Resource Interval',
            'form_input'  => 'number',
            'description' => 'Interval in seconds to refresh resources',
        ],
        [
            'store_id'    => null,
            'module'      => 'central-ui',
            'path'        => 'scheduler.api.rrd.interval',
            'value'       => 60,
            'position'    => 1100,
            'label'       => 'RRD Interval',
            'form_input'  => 'number',
            'description' => 'Interval in seconds to refresh rrd data (statistics)',
        ],
        [
            'store_id'    => null,
            'module'      => 'central-ui',
            'path'        => 'scheduler.api.action.interval',
            'value'       => 5,
            'position'    => 1200,
            'label'       => 'Actions Interval',
            'form_input'  => 'number',
            'description' => 'Interval in seconds to refresh intern actions status like running or stopping',
        ],
        [
            'store_id'    => null,
            'module'      => 'central-ui',
            'path'        => 'scheduler.frontend.desktop.interval',
            'value'       => 0,
            'position'    => 1300,
            'label'       => 'Desktop Interval',
            'form_input'  => 'number',
            'description' => "Interval in seconds to \"extra\" refresh desktop items. 0 = default = never",
        ],
    ],
];

