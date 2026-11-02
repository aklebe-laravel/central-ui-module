<?php

use Modules\WebsiteBase\app\Models\CoreConfig;

// Backup bar of the desktop (jobs and running backups), see ProxmoxBackupService
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
            'path'        => 'scheduler.api.backups.interval',
            'value'       => 60,
            'position'    => 1300,
            'label'       => 'Backup Interval',
            'form_input'  => 'number',
            'description' => 'Interval in seconds to refresh the backup jobs and running backups. 0 = never',
        ],
        [
            'store_id'    => null,
            'module'      => 'central-ui',
            'path'        => 'scheduler.api.backups.interval_hot',
            'value'       => 15,
            'position'    => 1350,
            'label'       => 'Backup Interval (running)',
            'form_input'  => 'number',
            'description' => 'Interval in seconds while a backup runs or a job starts within 2 minutes',
        ],
    ],
];
