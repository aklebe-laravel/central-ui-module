<?php

declare(strict_types = 1);

namespace Modules\CentralUi\app\Services\Wireguard;

/**
 * Result of set() for one network. Deliberately holds no private key:
 * the client config lives in the archive at $archivePath.
 */
final readonly class MemberConfig
{
    public function __construct(
        public string $member,
        public string $tunnel,
        public string $ip,
        public string $fileName,    // name of the .conf file inside the archive
        public string $archivePath, // path of the zip archive on the configured disk
        public bool $created,       // true = newly created, false = key renewed
    )
    {
    }
}
