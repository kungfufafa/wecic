<?php

declare(strict_types=1);

namespace App\Exceptions;

class PortalConnectionException extends PortalException
{
    public static function networkError(string $url, string $error): self
    {
        return new self("Network error while accessing {$url}: {$error}", 500);
    }
}
