<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Auth\Authenticatable;

class PortalBroadcastActor implements Authenticatable
{
    public function __construct(
        private readonly string $sessionId
    ) {}

    public function getAuthIdentifierName(): string
    {
        return 'session_id';
    }

    public function getAuthIdentifier(): string
    {
        return $this->sessionId;
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken($value): void {}

    public function getRememberTokenName(): ?string
    {
        return null;
    }
}
