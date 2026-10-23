<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Models\PortalOutboxItem;
use App\Services\PortalOfflineAccess;
use App\Services\PortalOutbox;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

#[Group('Antrean offline', 'Absensi dan unggahan yang diminta saat sesi offline: disimpan di MyCIC, belum terkirim ke portal, dikirim otomatis begitu portal bisa diakses.', 17)]
class PortalOutboxController extends CampusApiController
{
    private const OUTBOX_ITEM = "array{id: int, action: 'assignment_upload'|'exam_upload'|'attendance', action_label: string, subject: string|null, url: string|null, file_name: string|null, deadline_at: string|null, deadline_passed: bool, status: 'queued'|'sent'|'failed'|'expired', status_label: string, attempts: int, last_error: string|null, match_keys: list<string>, created_at: string|null, sent_at: string|null, updated_at: string|null}";

    private const INDEX_RESPONSE = 'array{status: \'success\', message: string, data: array{items: list<'.self::OUTBOX_ITEM.'>, queued_count: int, offline: bool, enabled: bool}, meta: array{timestamp: string, campus_session_valid: true}}';

    private const DESTROY_RESPONSE = "array{status: 'success', message: string, data: array{queued_count: int}, meta: array{timestamp: string, campus_session_valid: true}}";

    public function __construct(
        private readonly PortalOutbox $outbox,
        private readonly PortalOfflineAccess $offlineAccess
    ) {}

    /**
     * Antrean offline mahasiswa.
     *
     * Semua item yang masih menunggu (`queued`, belum terkirim ke portal) ditambah
     * yang selesai beberapa hari terakhir (`sent`, `failed`, `expired`). Dibaca dari
     * database MyCIC, tidak pernah menunggu portal. `match_keys` (`meeting:<id>`,
     * `course:<id>`, `exam:<id>`) dipakai halaman untuk menampilkan antrean miliknya.
     */
    #[Response(description: 'Antrean berhasil dibaca.', type: self::INDEX_RESPONSE)]
    #[Response(401, description: 'Endpoint ini membutuhkan sesi kampus yang aktif.', type: CampusApiController::SCRAMBLE_SESSION_ERROR_RESPONSE)]
    public function index(): JsonResponse
    {
        return $this->withCampusSession('Outbox list failed', 'Gagal memuat antrean offline', function (string $sessionToken): JsonResponse {
            $ownerHash = $this->outbox->ownerHash($sessionToken);
            $items = $this->outbox->items($ownerHash);

            return $this->statusSuccess('Outbox retrieved successfully', [
                'data' => [
                    'items' => $items->map(static fn (PortalOutboxItem $item): array => $item->toApiArray())->values()->all(),
                    'queued_count' => $items->filter(static fn (PortalOutboxItem $item): bool => $item->isQueued())->count(),
                    'offline' => $this->offlineAccess->isOfflineSession($sessionToken),
                    'enabled' => $this->outbox->enabled(),
                ],
            ], campusSessionValid: true);
        });
    }

    /**
     * Batalkan item yang masih menunggu.
     *
     * Hanya item berstatus `queued` milik mahasiswa ini; file lampirannya ikut dihapus.
     * Item yang sudah selesai tetap tersimpan sebagai riwayat.
     */
    #[PathParameter('item', 'Id item antrean dari `/outbox`.', type: 'integer', example: 12)]
    #[Response(description: 'Item dibatalkan.', type: self::DESTROY_RESPONSE)]
    #[Response(401, description: 'Endpoint ini membutuhkan sesi kampus yang aktif.', type: CampusApiController::SCRAMBLE_SESSION_ERROR_RESPONSE)]
    #[Response(404, description: 'Item tidak ditemukan atau sudah diproses.', type: CampusApiController::SCRAMBLE_NOT_FOUND_RESPONSE)]
    public function destroy(int $item): JsonResponse
    {
        return $this->withCampusSession('Outbox cancel failed', 'Gagal membatalkan item antrean', function (string $sessionToken) use ($item): JsonResponse {
            $ownerHash = $this->outbox->ownerHash($sessionToken);

            if (! $this->outbox->cancel($ownerHash, $item)) {
                return $this->statusError('Item antrean tidak ditemukan atau sudah diproses.', 404, campusSessionValid: true);
            }

            $this->outbox->announce((string) session()->getId(), $sessionToken, 'Item antrean offline dibatalkan.');

            return $this->statusSuccess('Outbox item cancelled', [
                'data' => [
                    'queued_count' => $this->outbox->queued($ownerHash)->count(),
                ],
            ], campusSessionValid: true);
        });
    }
}
