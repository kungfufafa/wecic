<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PortalOperationStage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Last known state of a background portal operation (e.g. an upload), so the
 * browser can poll for it when realtime events do not arrive.
 */
class PortalOperationTracker
{
    /**
     * Where an upload keeps its file until its job has sent it. Each file is
     * named after its operation (see uploadFilePath), so a file left behind
     * by a process that died mid-upload leads `portal:recover-uploads` to
     * the operation and the lock it has to settle.
     */
    public const UPLOAD_DIRECTORY = 'portal-uploads';

    /**
     * @param  array{file: string, lock_key: string|null, lock_owner: string|null}|null  $upload  the stored file and the target lock of an upload, kept for portal:recover-uploads and never shown to the browser
     */
    public function start(string $operationId, string $type, ?string $sessionId = null, string $message = 'Operasi disiapkan.', ?array $upload = null): void
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
            'upload' => $upload,
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

    /**
     * The storage path an upload's file is written to, named after its operation.
     */
    public function uploadFilePath(string $operationId, ?string $extension = null): string
    {
        $extension = Str::lower((string) $extension);

        return self::UPLOAD_DIRECTORY.'/'.$operationId.(preg_match('/^[a-z0-9]{1,10}$/', $extension) === 1 ? '.'.$extension : '');
    }

    /**
     * The operation an upload file belongs to; null for a file that is not
     * named after one (e.g. stored before files carried their operation id).
     */
    public function operationIdForUploadFile(string $path): ?string
    {
        $operationId = Str::before(basename($path), '.');

        return Str::isUuid($operationId) ? $operationId : null;
    }

    private function key(string $operationId): string
    {
        return 'portal_operation_'.$operationId;
    }
}
