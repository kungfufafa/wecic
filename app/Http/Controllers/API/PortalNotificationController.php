<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Requests\PortalNotificationIndexRequest;
use App\Http\Requests\PortalNotificationReadRequest;
use App\Models\PortalNotification;
use App\Services\PortalDataOwner;
use App\Services\PortalNotificationCenter;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

#[Group('Notifikasi', 'Perubahan data portal (KRS divalidasi, nilai keluar, tagihan, pengumuman, SKKM, ujian dibuka) dan pengingat tenggat, disimpan per mahasiswa.', 16)]
class PortalNotificationController extends CampusApiController
{
    private const NOTIFICATION_ITEM = "array{id: int, type: string, dataset: string|null, title: string, body: string|null, url: string|null, urgency: 'info'|'warning'|'critical', created_at: string|null, read_at: string|null, is_read: bool}";

    private const INDEX_RESPONSE = 'array{status: \'success\', message: string, data: array{items: list<'.self::NOTIFICATION_ITEM.'>, unread_count: int, has_more: bool, next_before: int|null}, meta: array{timestamp: string, campus_session_valid: true}}';

    private const COUNT_RESPONSE = "array{status: 'success', message: string, data: array{unread_count: int}, meta: array{timestamp: string, campus_session_valid: true}}";

    private const READ_RESPONSE = "array{status: 'success', message: string, data: array{updated: int, unread_count: int}, meta: array{timestamp: string, campus_session_valid: true}}";

    public function __construct(
        private readonly PortalNotificationCenter $notifications,
        private readonly PortalDataOwner $owner
    ) {}

    /**
     * Daftar notifikasi, terbaru dulu.
     *
     * Dibaca dari database MyCIC, tidak pernah menunggu portal. Notifikasi baru juga dikirim lewat event
     * realtime `portal.notification` yang membawa `unread_count` terbaru. Untuk halaman berikutnya kirim
     * `before` = `data.next_before`.
     */
    #[Response(description: 'Notifikasi berhasil dibaca.', type: self::INDEX_RESPONSE)]
    #[Response(401, description: 'Endpoint ini membutuhkan sesi kampus yang aktif.', type: CampusApiController::SCRAMBLE_SESSION_ERROR_RESPONSE)]
    #[Response(422, description: 'Parameter tidak valid.', type: CampusApiController::SCRAMBLE_VALIDATION_ERROR_RESPONSE)]
    public function index(PortalNotificationIndexRequest $request): JsonResponse
    {
        return $this->withCampusSession('Notification list failed', 'Gagal memuat notifikasi', function (string $sessionToken) use ($request): JsonResponse {
            $ownerHash = $this->owner->key($sessionToken);
            $page = $this->notifications->latest($ownerHash, $request->limit(), $request->unreadOnly(), $request->before());
            $items = array_map(static fn (PortalNotification $notification): array => $notification->toApiArray(), $page['items']);

            return $this->statusSuccess('Notifications retrieved successfully', [
                'data' => [
                    'items' => $items,
                    'unread_count' => $this->notifications->unreadCount($ownerHash),
                    'has_more' => $page['has_more'],
                    'next_before' => $page['has_more'] && $items !== [] ? $items[array_key_last($items)]['id'] : null,
                ],
            ], campusSessionValid: true);
        });
    }

    /**
     * Jumlah notifikasi yang belum dibaca, untuk badge lonceng.
     */
    #[Response(description: 'Jumlah notifikasi belum dibaca.', type: self::COUNT_RESPONSE)]
    #[Response(401, description: 'Endpoint ini membutuhkan sesi kampus yang aktif.', type: CampusApiController::SCRAMBLE_SESSION_ERROR_RESPONSE)]
    public function unreadCount(): JsonResponse
    {
        return $this->withCampusSession('Notification count failed', 'Gagal menghitung notifikasi', function (string $sessionToken): JsonResponse {
            return $this->statusSuccess('Unread notifications counted', [
                'data' => ['unread_count' => $this->notifications->unreadCount($this->owner->key($sessionToken))],
            ], campusSessionValid: true);
        });
    }

    /**
     * Tandai notifikasi sudah dibaca.
     *
     * Kirim `ids` untuk notifikasi tertentu, atau `all: true` untuk semuanya. Id milik mahasiswa lain diabaikan.
     */
    #[Response(description: 'Notifikasi ditandai sudah dibaca.', type: self::READ_RESPONSE)]
    #[Response(401, description: 'Endpoint ini membutuhkan sesi kampus yang aktif.', type: CampusApiController::SCRAMBLE_SESSION_ERROR_RESPONSE)]
    #[Response(422, description: 'Parameter tidak valid.', type: CampusApiController::SCRAMBLE_VALIDATION_ERROR_RESPONSE)]
    public function markRead(PortalNotificationReadRequest $request): JsonResponse
    {
        return $this->withCampusSession('Notification mark-read failed', 'Gagal menandai notifikasi', function (string $sessionToken) use ($request): JsonResponse {
            $ownerHash = $this->owner->key($sessionToken);
            $updated = $this->notifications->markRead($ownerHash, $request->ids());

            return $this->statusSuccess('Notifications marked as read', [
                'data' => [
                    'updated' => $updated,
                    'unread_count' => $this->notifications->unreadCount($ownerHash),
                ],
            ], campusSessionValid: true);
        });
    }
}
