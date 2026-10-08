<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PortalOperationStage;
use App\Events\PortalDataInvalidated;
use App\Events\PortalNotificationSent;
use App\Events\PortalSessionNoticeSent;
use App\Events\PortalSyncStatusUpdated;
use App\Events\PortalUploadProgressed;
use Illuminate\Support\Facades\Log;

/**
 * Realtime updates for the student's browser. Events are broadcast right
 * away instead of waiting in a queue, and a broadcast that fails (e.g. Reverb
 * is down) is only logged: the browser can still poll for the outcome, so it
 * must never fail the portal action that triggered it.
 */
class PortalBroadcastService
{
    /**
     * @param  list<string>  $cacheKeys
     * @param  list<string>  $syncKeys
     */
    public function dataInvalidated(string $sessionId, string $campusSessionToken, array $cacheKeys, array $syncKeys, string $message): void
    {
        $this->send(fn () => PortalDataInvalidated::dispatch($sessionId, $this->payload($campusSessionToken, [
            'cache_keys' => array_values($cacheKeys),
            'sync_keys' => array_values($syncKeys),
            'message' => $message,
            'urgency' => 'info',
        ])));
    }

    /**
     * @param  list<string>  $cacheKeys
     * @param  list<string>  $syncKeys
     */
    public function syncStatus(string $sessionId, string $campusSessionToken, PortalOperationStage $stage, string $message, array $cacheKeys = [], array $syncKeys = []): void
    {
        $this->send(fn () => PortalSyncStatusUpdated::dispatch($sessionId, $this->payload($campusSessionToken, [
            'status' => $stage->value,
            'message' => $message,
            'cache_keys' => array_values($cacheKeys),
            'sync_keys' => array_values($syncKeys),
            'urgency' => $stage === PortalOperationStage::Failed ? 'error' : 'info',
        ])));
    }

    /**
     * @param  list<string>  $cacheKeys
     * @param  list<string>  $syncKeys
     */
    public function uploadProgress(
        string $sessionId,
        string $campusSessionToken,
        string $operationId,
        PortalOperationStage $stage,
        string $message,
        ?string $detail = null,
        ?string $nextStep = null,
        ?int $percent = null,
        array $cacheKeys = [],
        array $syncKeys = []
    ): void {
        $this->send(fn () => PortalUploadProgressed::dispatch($sessionId, $this->payload($campusSessionToken, [
            'operation_id' => $operationId,
            'status' => $stage->value,
            'message' => $message,
            'detail' => $detail,
            'next_step' => $nextStep,
            'percent' => $percent,
            'cache_keys' => array_values($cacheKeys),
            'sync_keys' => array_values($syncKeys),
            'urgency' => $stage === PortalOperationStage::Failed ? 'error' : 'info',
        ])));
    }

    public function sessionNotice(string $sessionId, string $campusSessionToken, string $status, string $message, string $urgency = 'info'): void
    {
        $this->send(fn () => PortalSessionNoticeSent::dispatch($sessionId, $this->payload($campusSessionToken, [
            'status' => $status,
            'message' => $message,
            'urgency' => $urgency,
        ])));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function notification(string $sessionId, string $campusSessionToken, array $data): void
    {
        $this->send(fn () => PortalNotificationSent::dispatch($sessionId, $this->payload($campusSessionToken, $data)));
    }

    private function send(callable $dispatch): void
    {
        try {
            $dispatch();
        } catch (\Throwable $throwable) {
            Log::warning('Portal realtime broadcast failed', [
                'error' => $throwable->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function payload(string $campusSessionToken, array $data): array
    {
        return array_merge([
            'occurred_at' => now()->toISOString(),
            'session_fingerprint' => PortalLogContext::fingerprint($campusSessionToken),
        ], $data);
    }
}
