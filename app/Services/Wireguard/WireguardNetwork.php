<?php

declare(strict_types = 1);

namespace Modules\CentralUi\app\Services\Wireguard;

/**
 * A WireGuard network. Its name is the pfSense tunnel name (e.g. "tun_wg1").
 */
final readonly class WireguardNetwork
{
    public function __construct(
        public string $tunnel,
        public string $prefix,      // e.g. "10.0.99."
        public int $firstHost,
        public int $mask,
        public string $endpoint,
        public string $allowedIps,
        public string $dns,
        public int $mtu,
    ) {
    }

    /** Host number (last octet) of an IP address, if the address belongs to this network. */
    public function hostOf(string $ip): ?int
    {
        return str_starts_with($ip, $this->prefix) ? (int) substr($ip, strlen($this->prefix)) : null;
    }
}
