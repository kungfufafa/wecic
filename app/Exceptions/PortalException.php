<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

class PortalException extends Exception
{
    protected ?array $context = null;

    public function __construct(string $message, int $code = 0, ?Exception $previous = null, ?array $context = null)
    {
        parent::__construct($message, $code, $previous);
        $this->context = $context;
    }

    public function getContext(): ?array
    {
        return $this->context;
    }
}
