<?php

declare(strict_types=1);

namespace App\Services;

class PortalLogContext
{
    public static function fingerprint(string $value): string
    {
        return substr(hash_hmac('sha256', $value, self::fingerprintKey()), 0, 16);
    }

    private static function fingerprintKey(): string
    {
        return (string) (config('app.key') ?: 'campus-observability');
    }
}
