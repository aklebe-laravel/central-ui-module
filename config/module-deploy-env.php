<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Config of deployments. Can be updated by adding new identifiers.
    | See module DeployEnv README.md
    |--------------------------------------------------------------------------
    */

    'deployments' => [
        // Identifier to remember this deployment was already done.
        '0001' => [
            [
                'cmd'     => 'models',
                'sources' => [
                    'model-attributes.php',
                    'model-attribute-assignments.php',
                    'servers.php',
                    'navigations.php',
                    'acl-resources.php',
                    'acl-groups.php',
                ],
            ],
        ],
        '0002' => [
            [
                'cmd'     => 'models',
                'sources' => [
                    'core-config.php',
                ],
            ],
        ],
        '0003' => [
            [
                'cmd'     => 'models',
                'sources' => [
                    'navigations.php',
                ],
            ],
        ],
        '0004' => [
            [
                'cmd'     => 'models',
                'sources' => [
                    'navigations.php',
                ],
            ],
        ],
        '0005' => [
            [
                'cmd'     => 'models',
                'sources' => [
                    // user setting 'desktop_layout' (compact, icons, list)
                    'model-attributes.php',
                    'model-attribute-assignments.php',
                ],
            ],
        ],
        '0006' => [
            [
                'cmd'     => 'models',
                'sources' => [
                    // user menu entry 'Desktop Layout' (control)
                    'navigations.php',
                ],
            ],
        ],
    ],
];
