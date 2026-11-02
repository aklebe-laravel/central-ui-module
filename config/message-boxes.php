<?php

/**
 * Message boxes of this module (merged over all modules into config('message-boxes')), see ServerPower.
 * 'content' is a translation key (lang/*.json).
 */
return [
    'central-ui-server' => [
        'desktop' => [
            // driver proxmox: the nodes shut down at once
            'shutdown-proxmox'        => [
                'title'   => 'Shut down the server',
                'content' => 'ask_shutdown_server_proxmox',
                'actions' => ['system-base::cancel', 'central-ui::shutdown-server'],
            ],
            'shutdown-proxmox-backup' => [
                'title'   => 'Shut down the server',
                'content' => 'ask_shutdown_server_proxmox_backup',
                'actions' => ['system-base::cancel', 'central-ui::shutdown-server'],
            ],
            // driver http: the power service of the host decides (e.g. grace period)
            'shutdown-http'           => [
                'title'   => 'Shut down the server',
                'content' => 'ask_shutdown_server',
                'actions' => ['system-base::cancel', 'central-ui::shutdown-server'],
            ],
            'shutdown-http-backup'    => [
                'title'   => 'Shut down the server',
                'content' => 'ask_shutdown_server_backup',
                'actions' => ['system-base::cancel', 'central-ui::shutdown-server'],
            ],
        ],
    ],
];
