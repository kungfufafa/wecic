<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PortalOperationStage;
use Illuminate\Support\Facades\Cache;

/**
 * Last known state of a background portal operation (e.g. an upload), so the
 * browser can poll for it when realtime events do not arrive.
 */
class PortalOperationTracker
{
    public function start(string $operationId, string $type, ?string $sessionId = null, string $message = 'Operasi disiapkan.'): void
    {
        Cache::put($this->key($operationId), [
            'operation_id' => $operationId,
            'type' => $type,
            'session_id' => $sessionId,
            'stage' => PortalOperationStage::Connecting->value,
            'percent' => 0,
            'message' => $message,
            'detail' => null,
            'next_step' => null,
            'cache_keys' => [],
            'sync_keys' => [],
            'updated_at' => now()->toISOString(),
        ], now()->addHour());
    }

    /**
     * @param  list<string>  $cacheKeys
     * @param  list<string>  $syncKeys
     */
    public function progress(
        string $operationId,
        PortalOperationStage $stage,
        string $message,
        int $percent,
        ?string $detail = null,
        ?string $nextStep = null,
        array $cacheKeys = [],
        array $syncKeys = []
    ): void {
        $current = $this->get($operationId) ?? [
            'operation_id' => $operationId,
            'type' => 'unknown',
            'session_id' => null,
        ];

        Cache::put($this->key($operationId), array_merge($current, [
            'stage' => $stage->value,
            'percent' => max(0, min(100, $percent)),
            'message' => $message,
            'detail' => $detail,
            'next_step' => $nextStep,
            'cache_keys' => array_values($cacheKeys),
            'sync_keys' => array_values($syncKeys),
            'updated_at' => now()->toISOString(),
        ]), now()->addHour());
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $operationId): ?array
    {
        $snapshot = Cache::get($this->key($operationId));

        return is_array($snapshot) ? $snapshot : null;
    }

    private function key(string $operationId): string
    {
        return 'portal_operation_'.$operationId;
    }
}
