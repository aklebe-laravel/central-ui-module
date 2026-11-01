<?php

use Modules\WebsiteBase\app\Models\CoreConfig;

// RRD statistics: 'hour' every minute (phase 1 and 2), 'day' rarely (phase 3), see ProxmoxService::RrdTimeframes
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
            'path'        => 'scheduler.api.rrd.interval',
            'value'       => 60,
            'position'    => 1100,
            'label'       => 'RRD Interval (hour)',
            'form_input'  => 'number',
            'description' => 'Interval in seconds to refresh the statistics of the last hour (phase 1 and 2). 0 = never',
        ],
        [
            'store_id'    => null,
            'module'      => 'central-ui',
            'path'        => 'scheduler.api.rrd_day.interval',
            'value'       => 1800,
            'position'    => 1150,
            'label'       => 'RRD Interval (day)',
            'form_input'  => 'number',
            'description' => 'Interval in seconds to refresh the statistics of the last day (phase 3, large API response). 0 = never',
        ],
    ],
];
