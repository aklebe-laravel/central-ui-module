<?php

declare(strict_types = 1);

namespace Modules\CentralUi\app\Services\Wireguard;

final readonly class DoctorResult
{
    public const OK = 'OK';
    public const WARN = 'WARN';
    public const FAIL = 'FAIL';

    public function __construct(
        public string $level,
        public string $check,
        public string $message,
    ) {
    }

    public static function ok(string $check, string $message): self
    {
        return new self(self::OK, $check, $message);
    }

    public static function warn(string $check, string $message): self
    {
        return new self(self::WARN, $check, $message);
    }

    public static function fail(string $check, string $message): self
    {
        return new self(self::FAIL, $check, $message);
    }
}
