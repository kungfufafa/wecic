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
            'urgency' => $this->urgencyFor($stage),
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
            'urgency' => $this->urgencyFor($stage),
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

    /**
     * How loudly the browser reports a stage: an upload the portal has not
     * settled is a warning (check the page before uploading again), not an
     * error and not a routine update.
     */
    private function urgencyFor(PortalOperationStage $stage): string
    {
        return match ($stage) {
            PortalOperationStage::Failed => 'error',
            PortalOperationStage::Unverified => 'warning',
            default => 'info',
        };
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

    /**
     * Where an API client (the mobile app) connects to receive these events:
     * the Reverb server's public address and app key, as the browser gets
     * them from `VITE_REVERB_*` at build time. Null while events are not
     * broadcast over Reverb (`BROADCAST_CONNECTION` not `reverb`, or no key),
     * so the client does not try to connect at all.
     *
     * @return array{key: string, host: string, port: int, scheme: 'http'|'https'}|null
     */
    public function clientConfig(): ?array
    {
        if (config('broadcasting.default') !== 'reverb') {
            return null;
        }

        $reverb = config('broadcasting.connections.reverb', []);
        $key = trim((string) ($reverb['key'] ?? ''));
        $host = trim((string) ($reverb['options']['host'] ?? ''));

        if ($key === '' || $host === '') {
            return null;
        }

        $scheme = ($reverb['options']['scheme'] ?? 'https') === 'http' ? 'http' : 'https';

        return [
            'key' => $key,
            'host' => $host,
            'port' => (int) ($reverb['options']['port'] ?? ($scheme === 'https' ? 443 : 80)),
            'scheme' => $scheme,
        ];
    }
}
