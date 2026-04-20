<?php

declare(strict_types=1);

namespace App\Exceptions;

class PortalScrapingException extends PortalException
{
    public static function elementNotFound(string $selector, string $page): self
    {
        return new self("Required element not found: {$selector} on page {$page}", 422);
    }

    public static function invalidHtmlStructure(string $page): self
    {
        return new self("Invalid HTML structure on page {$page}", 422);
    }

    public static function dataExtractionFailed(string $data, string $reason): self
    {
        return new self("Failed to extract {$data}: {$reason}", 422);
    }

    public static function invalidResponse(string $url, string $reason): self
    {
        return new self("Invalid response from {$url}: {$reason}", 422);
    }

    public static function pageNotAccessible(string $url): self
    {
        return new self("Page not accessible: {$url}", 403);
    }
}
