<?php

declare(strict_types=1);

namespace App\Jobs\Middleware;

use App\Services\Ai\NotebookAiOwnerSettings;
use Closure;

/**
 * Runs a Notebook job with the AI settings of the student it works for,
 * before its handle() is resolved (the AI client is chosen then).
 */
class UseNotebookOwnerAiSettings
{
    /**
     * @param  Closure(): ?string  $ownerHash
     */
    public function __construct(private readonly Closure $ownerHash) {}

    public function handle(object $job, Closure $next): mixed
    {
        $settings = app(NotebookAiOwnerSettings::class);
        $settings->actAs(($this->ownerHash)());

        try {
            return $next($job);
        } finally {
            $settings->actAs(null);
        }
    }
}
