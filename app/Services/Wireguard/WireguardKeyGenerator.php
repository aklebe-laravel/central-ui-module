<?php

declare(strict_types = 1);

namespace Modules\CentralUi\app\Services\Wireguard;

use RuntimeException;

final class WireguardKeyGenerator
{
    /**
     * Generates a key pair in the same format as "wg genkey" / "wg pubkey" (Base64).
     *
     * @return array{private: string, public: string}
     */
    public function generate(): array
    {
        if (!function_exists('sodium_crypto_scalarmult_base')) {
            throw new RuntimeException('The PHP extension "sodium" is missing.');
        }

        $private = random_bytes(32);
        $private[0] = chr(ord($private[0]) & 248);
        $private[31] = chr((ord($private[31]) & 127) | 64);

        return [
            'private' => base64_encode($private),
            'public'  => base64_encode(sodium_crypto_scalarmult_base($private)),
        ];
    }
}
