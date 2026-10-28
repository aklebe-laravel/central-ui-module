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

    'proxmox' => [
        // API token secret of the Proxmox server, used by the business import of the servers
        'token_secret' => env('PROXMOX_TOKEN_SECRET'),
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
