<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\PortalSessionManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RefreshPortalSessionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $retryAfter = 60;

    /**
     * Create a new job instance.
     */
    public function __construct(
        private string $sessionToken
    ) {
        // Set the queue connection
        $this->onQueue('campus-sessions');
    }

    /**
     * Execute the job.
     */
    public function handle(PortalSessionManager $sessionManager): void
    {
        try {
            Log::info('Processing campus session refresh', [
                'session_token' => $this->sessionToken,
                'attempt' => $this->attempts(),
            ]);

            // Validate session exists and is active
            if (! $sessionManager->validateSession($this->sessionToken)) {
                Log::info('Session no longer valid, skipping refresh', [
                    'session_token' => $this->sessionToken,
                ]);

                return;
            }

            // Refresh the session
            $result = $sessionManager->refreshSession($this->sessionToken);

            Log::info('Campus session refreshed successfully', [
                'session_token' => $this->sessionToken,
                'new_expires_at' => $result['expires_at'] ?? null,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to refresh campus session', [
                'session_token' => $this->sessionToken,
                'attempt' => $this->attempts(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // If this is the last attempt, we should let the session expire naturally
            if ($this->attempts() >= $this->tries) {
                Log::warning('Max retry attempts reached for session refresh', [
                    'session_token' => $this->sessionToken,
                ]);

                return;
            }

            // Release the job to be tried again later
            $this->release($this->retryAfter);
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Campus session refresh job failed permanently', [
            'session_token' => $this->sessionToken,
            'error' => $exception->getMessage(),
        ]);
    }
}
