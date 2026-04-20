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
            Log::info('campus_session.refresh_job_started', [
                'domain' => 'campus_session',
                'event' => 'refresh_job_started',
                'component' => self::class,
                'session_token_fingerprint' => $this->fingerprint($this->sessionToken),
                'attempt' => $this->attempts(),
                'metric' => [
                    'name' => 'campus_session_events_total',
                    'type' => 'counter',
                    'value' => 1,
                    'tags' => [
                        'event' => 'refresh_job_started',
                        'component' => class_basename(static::class),
                    ],
                ],
            ]);

            // Validate session exists and is active
            if (! $sessionManager->validateSession($this->sessionToken)) {
                Log::warning('campus_session.refresh_job_skipped_invalid', [
                    'domain' => 'campus_session',
                    'event' => 'refresh_job_skipped_invalid',
                    'component' => self::class,
                    'session_token_fingerprint' => $this->fingerprint($this->sessionToken),
                    'attempt' => $this->attempts(),
                    'metric' => [
                        'name' => 'campus_session_events_total',
                        'type' => 'counter',
                        'value' => 1,
                        'tags' => [
                            'event' => 'refresh_job_skipped_invalid',
                            'component' => class_basename(static::class),
                        ],
                    ],
                ]);

                return;
            }

            // Refresh the session
            $result = $sessionManager->refreshSession($this->sessionToken);

            Log::info('campus_session.refresh_job_succeeded', [
                'domain' => 'campus_session',
                'event' => 'refresh_job_succeeded',
                'component' => self::class,
                'session_token_fingerprint' => $this->fingerprint($this->sessionToken),
                'attempt' => $this->attempts(),
                'new_expires_at' => $result['expires_at'] ?? null,
                'metric' => [
                    'name' => 'campus_session_events_total',
                    'type' => 'counter',
                    'value' => 1,
                    'tags' => [
                        'event' => 'refresh_job_succeeded',
                        'component' => class_basename(static::class),
                    ],
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('campus_session.refresh_job_failed', [
                'domain' => 'campus_session',
                'event' => 'refresh_job_failed',
                'component' => self::class,
                'session_token_fingerprint' => $this->fingerprint($this->sessionToken),
                'attempt' => $this->attempts(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'metric' => [
                    'name' => 'campus_session_events_total',
                    'type' => 'counter',
                    'value' => 1,
                    'tags' => [
                        'event' => 'refresh_job_failed',
                        'component' => class_basename(static::class),
                    ],
                ],
            ]);

            // If this is the last attempt, we should let the session expire naturally
            if ($this->attempts() >= $this->tries) {
                Log::warning('campus_session.refresh_job_exhausted', [
                    'domain' => 'campus_session',
                    'event' => 'refresh_job_exhausted',
                    'component' => self::class,
                    'session_token_fingerprint' => $this->fingerprint($this->sessionToken),
                    'attempt' => $this->attempts(),
                    'metric' => [
                        'name' => 'campus_session_events_total',
                        'type' => 'counter',
                        'value' => 1,
                        'tags' => [
                            'event' => 'refresh_job_exhausted',
                            'component' => class_basename(static::class),
                        ],
                    ],
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
        Log::error('campus_session.refresh_job_failed_permanently', [
            'domain' => 'campus_session',
            'event' => 'refresh_job_failed_permanently',
            'component' => self::class,
            'session_token_fingerprint' => $this->fingerprint($this->sessionToken),
            'error' => $exception->getMessage(),
            'metric' => [
                'name' => 'campus_session_events_total',
                'type' => 'counter',
                'value' => 1,
                'tags' => [
                    'event' => 'refresh_job_failed_permanently',
                    'component' => class_basename(static::class),
                ],
            ],
        ]);
    }

    private function fingerprint(string $value): string
    {
        return substr(hash_hmac('sha256', $value, (string) (config('app.key') ?: 'campus-session-observability')), 0, 16);
    }
}
