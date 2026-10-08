<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Services\PortalOperationTracker;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

#[Group('SIPANDAI - Aksi', 'Endpoint untuk mengirim aksi langsung ke portal SIPANDAI seperti unggah tugas, absensi, dan unggah ujian.', 30)]
class PortalOperationController extends CampusApiController
{
    private const OPERATION_RESPONSE = 'array{success: bool, message: string, data: array{operation_id: string, type: string, status: string, percent: int, message: string, detail: string|null, next_step: string|null, cache_keys: list<string>, sync_keys: list<string>, updated_at: string}}';

    public function __construct(private readonly PortalOperationTracker $tracker) {}

    /**
     * Status operasi latar belakang (misalnya unggah tugas).
     *
     * Cadangan untuk event real-time: klien bisa memanggil endpoint ini secara berkala
     * memakai `operation_id` dari respons unggah sampai status menjadi `completed` atau `failed`.
     */
    #[PathParameter('operation', 'Identifier `operation_id` dari respons unggah tugas atau ujian.', type: 'string')]
    #[Response(description: 'Status operasi terakhir.', type: self::OPERATION_RESPONSE)]
    #[Response(404, description: 'Operasi tidak ditemukan untuk sesi ini.', type: CampusApiController::SCRAMBLE_BOOLEAN_RESPONSE)]
    public function show(string $operation): JsonResponse
    {
        $snapshot = $this->tracker->get($operation);

        // Operations belong to the browser session that started them.
        if ($snapshot === null || ($snapshot['session_id'] ?? null) !== (string) session()->getId()) {
            return $this->booleanResponse(false, 'Operasi tidak ditemukan.', null, 404);
        }

        return $this->booleanResponse(true, 'Operation status retrieved successfully.', [
            'operation_id' => (string) $snapshot['operation_id'],
            'type' => (string) ($snapshot['type'] ?? 'unknown'),
            'status' => (string) ($snapshot['stage'] ?? 'connecting'),
            'percent' => (int) ($snapshot['percent'] ?? 0),
            'message' => (string) ($snapshot['message'] ?? ''),
            'detail' => $snapshot['detail'] ?? null,
            'next_step' => $snapshot['next_step'] ?? null,
            'cache_keys' => array_values($snapshot['cache_keys'] ?? []),
            'sync_keys' => array_values($snapshot['sync_keys'] ?? []),
            'updated_at' => (string) ($snapshot['updated_at'] ?? ''),
        ]);
    }
}
