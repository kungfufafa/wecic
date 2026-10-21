<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Services\ActionCenterBuilder;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

#[Group('Pusat Tindakan', 'Daftar kewajiban mahasiswa yang disusun dari data portal: KRS, tagihan, SKKM, tenggat pengumuman, kuesioner, tugas, ujian, dan nilai.', 15)]
class PortalActionCenterController extends CampusApiController
{
    private const ACTION_ITEMS_RESPONSE = "array{status: 'success', message: string, data: array{items: list<array{id: string, type: 'krs_validation'|'krs_submission'|'bill_outstanding'|'payment_deadline'|'skkm_requirement'|'announcement_deadline'|'questionnaire'|'assignment_due'|'exam_upload'|'grade_alert', title: string, description: string|null, due_at: string|null, days_left: int|null, severity: 'critical'|'warning'|'info', cta_label: string, cta_url: string, cta_external: bool, source: string, contact?: array{name: string|null, nidn: string|null, phone: string|null, whatsapp: string|null, email: string|null}}>, counts: array{total: int, shown: int, critical: int, warning: int, info: int}}, meta: array{timestamp: string, campus_session_valid: true, pending_sources: list<string>, syncing_sources: list<string>, failed_sources: list<string>, sources: array<string, array{status: 'ready'|'syncing'|'failed'|'pending'|'missing', synced_at: string|null}>, is_complete: bool, generated_at: string}}";

    public function __construct(private readonly ActionCenterBuilder $actionCenter) {}

    /**
     * Pusat tindakan: kewajiban yang perlu dikerjakan, urut dari yang paling mendesak.
     *
     * Disusun hanya dari snapshot yang sudah tersimpan, jadi tidak pernah menunggu portal. Sumber yang
     * belum punya snapshot diperbarui di background dan disebut di `meta.pending_sources` (tampilkan
     * "sedang diperbarui"); setelah sinkron selesai, event realtime membawa cache key `/api/action-items`
     * dan sync key `dashboard-action-items`. Sumber yang gagal dibaca dilewati dan disebut di
     * `meta.failed_sources`. Maksimal 20 item, urut tingkat (critical, warning, info) lalu tenggat.
     */
    #[Response(description: 'Pusat tindakan berhasil disusun.', type: self::ACTION_ITEMS_RESPONSE)]
    #[Response(401, description: 'Endpoint ini membutuhkan sesi kampus yang aktif.', type: CampusApiController::SCRAMBLE_SESSION_ERROR_RESPONSE)]
    #[Response(500, description: 'Pusat tindakan gagal disusun.', type: CampusApiController::SCRAMBLE_STATUS_ERROR_RESPONSE)]
    public function index(): JsonResponse
    {
        return $this->withCampusSession('Action center build failed', 'Gagal menyusun pusat tindakan', function (string $sessionToken): JsonResponse {
            $actionCenter = $this->actionCenter->build($sessionToken, (string) session()->getId());

            return $this->statusSuccess('Action items retrieved successfully', [
                'data' => [
                    'items' => $actionCenter['items'],
                    'counts' => $actionCenter['counts'],
                ],
            ], meta: $actionCenter['meta'], campusSessionValid: true);
        });
    }
}
