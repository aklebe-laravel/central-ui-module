<?php

/*
 * WireGuard networks managed by Proxtop (Admin > Proxmox > WireGuard Networks).
 *
 * tunnel:      pfSense tunnel name (unique)
 * host_group:  networks with the same group share their host numbers (last IP octet), null = no group
 * prefix:      client IPs are <prefix><first_host..254>
 * endpoint, allowed_ips, dns, mtu: written to the client configs
 */
return [
    'version'    => '2.0',
    // class of eloquent model
    'model'      => \Modules\CentralUi\app\Models\ProxtopWireguardNetwork::class,
    // create data if not exists (default true)
    'can_create' => true,
    // update data if exists and data differ (default false)
    // true: this file wins over changes made in the admin frontend (on --force or a new ident)
    'can_update' => true,
    // columns to check if data already exists (AND WHERE)
    'uniques'    => ['tunnel'],
    // data rows itself
    'data'       => [
        [
            'position'    => 1000,
            'tunnel'      => 'tun_wg1',
            'host_group'  => 'members',
            'prefix'      => '198.51.100.',
            'first_host'  => 2,                 // .1 is the pfSense itself
            'mask'        => 24,
            'endpoint'    => 'vpn.example.org:51821',
            'allowed_ips' => '192.0.2.0/24, 198.51.100.0/24',
            'dns'         => '192.0.2.4',
            'mtu'         => 1280,
            'description' => 'Members: home network only',
        ],
        [
            'position'    => 2000,
            'tunnel'      => 'tun_wg2',
            'host_group'  => 'members',
            'prefix'      => '203.0.113.',
            'first_host'  => 2,
            'mask'        => 24,
            'endpoint'    => 'vpn.example.org:51822',
            'allowed_ips' => '0.0.0.0/0',
            'dns'         => '192.0.2.4',
            'mtu'         => 1280,
            'description' => 'Members: all traffic',
        ],
    ],
];
