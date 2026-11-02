<?php

// Neutral module defaults. Installation specific values (networks, groups) are not part of the module.
// Access: config('central-ui.wireguard.config_dir') etc.
return [
    'name' => 'CentralUi',

    'pfsense' => [
        'url'        => env('PFSENSE_URL'),
        'api_key'    => env('PFSENSE_API_KEY'),
        'verify_tls' => (bool) env('PFSENSE_VERIFY_TLS', true),
    ],

    'proxmox'         => [
        // API token secret of the Proxmox server, used by the business import of the servers
        'token_secret' => env('PROXMOX_TOKEN_SECRET'),
    ],

    // Toolbar of the desktop (header of VmDesktopItems), after the search, ordered by 'position'.
    // A view gets $_config and $collectionName. Other modules add or change entries in their file
    // config/combined-module-central-ui.php: ['desktop_toolbar' => ['my_item' => ['view' => 'my-module::...', 'position' => 6000]]]
    // ('view' => null removes an entry), see VmDesktopItems::toolbarElements()
    'desktop_toolbar' => [
        'backups'      => [
            'view'      => 'central-ui::data-tables.vm-desktop.toolbar.backups',
            'position'  => 5000,
            // pushes this and all following elements to the right
            'css_group' => 'ml-auto',
        ],
        'server_power' => [
            'view'      => 'central-ui::data-tables.vm-desktop.toolbar.server-power',
            'position'  => 9000,
            'css_group' => '',
        ],
    ],

    // Shutdown of the whole server (button in the desktop toolbar, admins only, see ServerPowerService).
    //   proxmox (default): the Proxmox API shuts down the nodes at once (token needs Sys.PowerMgmt)
    //   http:              a power service of the host decides, e.g. pve-power with grace period (url + token)
    //   none:              no button
    // Without SERVER_POWER_DRIVER: 'http' if SERVER_POWER_URL is set, otherwise 'proxmox'.
    'power'           => [
        'driver'                 => env('SERVER_POWER_DRIVER') ?: (env('SERVER_POWER_URL') ? 'http' : 'proxmox'),
        'drivers'                => [
            'proxmox' => \Modules\CentralUi\app\Services\Power\ProxmoxNodeShutdown::class,
            'http'    => \Modules\CentralUi\app\Services\Power\HttpShutdown::class,
        ],
        // driver proxmox: only these node names (empty = all online nodes of the servers with API access)
        'nodes'                  => array_values(array_filter(array_map('trim', explode(',', (string) env('SERVER_POWER_NODES', ''))))),
        // driver http
        'url'                    => env('SERVER_POWER_URL'),
        'token'                  => env('SERVER_POWER_TOKEN'),
        'verify_tls'             => (bool) env('SERVER_POWER_VERIFY_TLS', true),
        'timeout'                => 10,
        // the confirmation warns if a backup runs or starts within this many seconds
        'backup_warning_seconds' => 900,
    ],

    'wireguard' => [
        // Client configs are stored as one zip archive per member: <config_dir>/<member>.zip
        'config_disk'            => 'local',
        'config_dir'             => 'wg_configs',

        // Prefix of the config file names inside the archive, e.g. 'family_' gives family_tun_wg2.conf.
        // The file name without ".conf" is the tunnel name shown by the WireGuard clients.
        'config_prefix'          => 'andi_',

        // Maximum length of that tunnel name. WireGuard clients (interface names) accept 15 characters.
        'config_name_max_length' => 15,

        // Seconds to wait for a concurrent change before giving up.
        'lock_wait_seconds'      => 10,

        // Seconds the peer list is cached for the frontend overview (0 = no cache). Changes clear the cache.
        'list_cache_seconds'     => 30,

        // The networks (pfSense tunnels) and their host groups are stored in the database:
        // table proxtop_wireguard_networks, Admin > Proxmox > WireGuard Networks (or a business import).
    ],
];
