<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Request/job scoped switches that decide how scrapers talk to the portal.
 *
 * Freshness used to be read from the ambient HTTP query string deep inside the
 * scraper base class, which silently disabled it inside queue jobs. Entry
 * points (dataset repository, jobs, controllers) now state it explicitly.
 */
class PortalFetchPolicy
{
    private bool $forceFresh = false;

    private ?int $requestDelayMs = null;

    public function forcesFresh(): bool
    {
        return $this->forceFresh;
    }

    /**
     * Run the callback while every resource cache read is bypassed.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $callback
     * @return TResult
     */
    public function runFresh(callable $callback, bool $forceFresh = true): mixed
    {
        $previous = $this->forceFresh;
        $this->forceFresh = $previous || $forceFresh;

        try {
            return $callback();
        } finally {
            $this->forceFresh = $previous;
        }
    }

    /**
     * Politeness delay between upstream requests issued by queue workers.
     */
    public function requestDelayMs(): int
    {
        return $this->requestDelayMs ?? (int) config('portal.request_delay', 1000);
    }

    /**
     * Run a bulk crawl (many small pages) with a shorter politeness delay.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $callback
     * @return TResult
     */
    public function withRequestDelay(int $delayMs, callable $callback): mixed
    {
        $previous = $this->requestDelayMs;
        $this->requestDelayMs = min($delayMs, $this->requestDelayMs());

        try {
            return $callback();
        } finally {
            $this->requestDelayMs = $previous;
        }
    }
}
