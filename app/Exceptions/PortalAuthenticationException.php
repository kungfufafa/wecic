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

    public static function loginRequired(): self
    {
        return new self('Login required to access campus resources', 401);
    }

    public static function csrfTokenMissing(): self
    {
        return new self('CSRF token is missing or invalid', 419);
    }
}
