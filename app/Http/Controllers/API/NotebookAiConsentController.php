<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Requests\NotebookAiConsentGrantRequest;
use App\Services\NotebookAiConsents;
use App\Services\PortalDataOwner;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

#[Group('Notebook', weight: 18)]
class NotebookAiConsentController extends CampusApiController
{
    private const CONSENT_RESPONSE = "array{status: 'success', message: string, data: array{consent: array{granted: bool, granted_at: string|null, withdrawn_at: string|null, provider: string}}, meta: array{timestamp: string, campus_session_valid: true}}";

    public function __construct(
        private readonly NotebookAiConsents $consents,
        private readonly PortalDataOwner $owner
    ) {}

    /**
     * Status persetujuan mengirim isi Notebook ke Google Gemini.
     *
     * Tanpa persetujuan (`granted: false`), tanya jawab dan studio ditolak dengan 403 `reason: ai_consent_required`,
     * dan berkas yang perlu dibaca AI (PDF, gambar) menunggu. Daftar, isi, dan penghapusan notebook tetap jalan.
     */
    #[Response(description: 'Status persetujuan.', type: self::CONSENT_RESPONSE)]
    #[Response(401, description: 'Endpoint ini membutuhkan sesi kampus yang aktif.', type: CampusApiController::SCRAMBLE_SESSION_ERROR_RESPONSE)]
    public function show(): JsonResponse
    {
        return $this->withCampusSession('AI consent read failed', 'Gagal memuat persetujuan AI', fn (string $sessionToken): JsonResponse => $this->statusSuccess('AI consent retrieved', [
            'data' => ['consent' => $this->consents->state($this->owner->key($sessionToken))],
        ], campusSessionValid: true));
    }

    /**
     * Setujui pengiriman isi Notebook (pertanyaan, teks sumber, PDF, gambar) ke Google Gemini.
     *
     * Gemini dipakai lewat tingkat gratis, sehingga Google dapat memakai data yang dikirim untuk meningkatkan produknya.
     * Berkas yang menunggu persetujuan mulai dibaca saat notebook dibuka lagi.
     */
    #[Response(description: 'Persetujuan disimpan.', type: self::CONSENT_RESPONSE)]
    #[Response(422, description: 'Persetujuan belum dicentang.', type: CampusApiController::SCRAMBLE_VALIDATION_ERROR_RESPONSE)]
    #[Response(401, description: 'Endpoint ini membutuhkan sesi kampus yang aktif.', type: CampusApiController::SCRAMBLE_SESSION_ERROR_RESPONSE)]
    public function store(NotebookAiConsentGrantRequest $request): JsonResponse
    {
        return $this->withCampusSession('AI consent grant failed', 'Gagal menyimpan persetujuan AI', function (string $sessionToken): JsonResponse {
            $ownerHash = $this->owner->key($sessionToken);
            $this->consents->grant($ownerHash);

            return $this->statusSuccess('AI Notebook aktif. Isi yang kamu kirim ke AI diproses oleh Google Gemini.', [
                'data' => ['consent' => $this->consents->state($ownerHash)],
            ], campusSessionValid: true);
        });
    }

    /**
     * Tarik persetujuan: sejak itu tidak ada lagi isi Notebook yang dikirim ke Google Gemini.
     *
     * Sumber, percakapan, dan hasil studio yang sudah ada tetap tersimpan dan bisa dibuka atau dihapus.
     */
    #[Response(description: 'Persetujuan ditarik.', type: self::CONSENT_RESPONSE)]
    #[Response(401, description: 'Endpoint ini membutuhkan sesi kampus yang aktif.', type: CampusApiController::SCRAMBLE_SESSION_ERROR_RESPONSE)]
    public function destroy(): JsonResponse
    {
        return $this->withCampusSession('AI consent withdraw failed', 'Gagal menarik persetujuan AI', function (string $sessionToken): JsonResponse {
            $ownerHash = $this->owner->key($sessionToken);
            $this->consents->withdraw($ownerHash);

            return $this->statusSuccess('Persetujuan AI ditarik. Tidak ada lagi isi Notebook yang dikirim ke Google Gemini.', [
                'data' => ['consent' => $this->consents->state($ownerHash)],
            ], campusSessionValid: true);
        });
    }
}
