<?php

declare(strict_types=1);

namespace App\Exceptions;

class PortalConnectionException extends PortalException
{
    public static function connectionTimeout(string $url): self
    {
        return new self("Connection timeout while accessing: {$url}", 500);
    }

    public static function networkError(string $url, string $error): self
    {
        return new self("Network error while accessing {$url}: {$error}", 500);
    }

    public static function invalidResponse(string $url, string $reason): self
    {
        return new self("Invalid response from {$url}: {$reason}", 500);
    }
}
