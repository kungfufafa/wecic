<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PortalOperationStage;
use Illuminate\Support\Facades\Cache;

class PortalOperationTracker
{
    public function start(string $operationId, string $type): void
    {
        Cache::put($this->key($operationId), [
            'operation_id' => $operationId,
            'type' => $type,
            'stage' => PortalOperationStage::Connecting->value,
            'percent' => 0,
            'message' => 'Operasi disiapkan.',
            'detail' => null,
            'next_step' => null,
            'updated_at' => now()->toISOString(),
        ], now()->addHour());
    }

    public function progress(string $operationId, PortalOperationStage $stage, string $message, int $percent, ?string $detail = null, ?string $nextStep = null): void
    {
        $current = $this->get($operationId) ?? [
            'operation_id' => $operationId,
            'type' => 'unknown',
        ];

        Cache::put($this->key($operationId), array_merge($current, [
            'stage' => $stage->value,
            'percent' => max(0, min(100, $percent)),
            'message' => $message,
            'detail' => $detail,
            'next_step' => $nextStep,
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
