<?php

declare(strict_types=1);

namespace App\Exceptions;

class PortalAuthenticationException extends PortalException
{
    public static function invalidCredentials(): self
    {
        return new self('Invalid campus credentials provided', 401);
    }

    public static function sessionExpired(): self
    {
        return new self('Campus session has expired', 401);
    }
}
