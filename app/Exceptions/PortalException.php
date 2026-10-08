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

    /**
     * Whether the failure means the campus session is gone and the student
     * has to log in again (as opposed to a transient or parsing failure).
     */
    public static function indicatesExpiredSession(\Throwable $throwable): bool
    {
        return $throwable instanceof PortalAuthenticationException
            || ($throwable instanceof PortalScrapingException && $throwable->getCode() === 403);
    }
}
