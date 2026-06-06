<?php

declare(strict_types=1);

namespace App\Services;

class PortalLogContext
{
    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public static function sanitize(array $context): array
    {
        if (isset($context['session_token']) && is_scalar($context['session_token'])) {
            $context['session_token_fingerprint'] = self::fingerprint((string) $context['session_token']);
            unset($context['session_token']);
        }

        if (isset($context['username']) && is_scalar($context['username'])) {
            $context['username_fingerprint'] = self::fingerprint((string) $context['username']);
            unset($context['username']);
        }

        return $context;
    }

    public static function fingerprint(string $value): string
    {
        return substr(hash_hmac('sha256', $value, self::fingerprintKey()), 0, 16);
    }

    private static function fingerprintKey(): string
    {
        return (string) (config('app.key') ?: 'campus-observability');
    }
}
