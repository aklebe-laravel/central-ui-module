<?php

/*
|--------------------------------------------------------------------------
| Template: business import of the WireGuard networks (module CentralUi).
|
| Copy this folder content to /business_imports/module-deploy-env/ of your project:
|   business_imports/module-deploy-env/central-ui-wireguard.php
|   business_imports/module-deploy-env/0003/models/proxtop_wireguard_networks.php
|
| The ident ('0003') must be unused by your other business imports.
| Run it: php artisan deploy-env:terraform-modules --module_name=local:business-imports
| See module DeployEnv README.md
|--------------------------------------------------------------------------
*/

return [
    'deployments' => [
        '0003' => [
            [
                'cmd'     => 'models',
                'sources' => [
                    'proxtop_wireguard_networks.php',
                ],
            ],
        ],
    ],
];
