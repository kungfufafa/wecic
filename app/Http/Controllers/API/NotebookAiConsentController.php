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
    private const CONSENT_STATE = 'array{granted: bool, granted_at: string|null, withdrawn_at: string|null, provider: string, free_tier: bool, consent_key: string, processors: list<array{label: string, free_tier: bool}>, past_processors: list<string>}';

    private const CONSENT_RESPONSE = "array{status: 'success', message: string, data: array{consent: ".self::CONSENT_STATE.'}, meta: array{timestamp: string, campus_session_valid: true}}';

    private const STALE_CONSENT_RESPONSE = "array{status: 'error', message: string, data: array{consent: ".self::CONSENT_STATE.'}, meta: array{timestamp: string, campus_session_valid: true}}';

    public function __construct(
        private readonly NotebookAiConsents $consents,
        private readonly PortalDataOwner $owner
    ) {}

    /**
     * Status persetujuan mengirim isi Notebook ke penyedia AI yang dipakai server.
     *
     * `provider` menyebut penyedia itu, misalnya `Google Gemini` atau `Anthropic Claude`. Persetujuan yang diberikan untuk
     * penyedia lain, atau sebelum penyedia memakai tingkat gratis, tidak berlaku lagi (`granted: false`). `free_tier` true
     * berarti ada penyedia yang dipakai lewat tingkat gratis, sehingga dapat memakai data yang dikirim untuk meningkatkan
     * produknya; `processors` merinci tiap penyedia. `consent_key` dikirim balik saat menyetujui. `past_processors`
     * menyebut penyedia yang dulu menerima isi Notebook dan kini tidak lagi.
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
     * Setujui pengiriman isi Notebook (pertanyaan, teks sumber, PDF, gambar) ke penyedia AI yang dipakai server.
     *
     * Persetujuan dicatat untuk penyedia saat ini; bila server berganti penyedia, mahasiswa diminta setuju lagi.
     * Kirim `consent_key` yang ditampilkan: bila penyedia sudah berubah sejak itu, persetujuan ditolak (409) beserta
     * status terbaru untuk ditampilkan ulang. Berkas yang menunggu persetujuan mulai dibaca saat notebook dibuka lagi.
     */
    #[Response(description: 'Persetujuan disimpan.', type: self::CONSENT_RESPONSE)]
    #[Response(409, description: 'Penyedia AI berubah sejak teks persetujuan ditampilkan.', type: self::STALE_CONSENT_RESPONSE)]
    #[Response(422, description: 'Persetujuan belum dicentang.', type: CampusApiController::SCRAMBLE_VALIDATION_ERROR_RESPONSE)]
    #[Response(401, description: 'Endpoint ini membutuhkan sesi kampus yang aktif.', type: CampusApiController::SCRAMBLE_SESSION_ERROR_RESPONSE)]
    public function store(NotebookAiConsentGrantRequest $request): JsonResponse
    {
        return $this->withCampusSession('AI consent grant failed', 'Gagal menyimpan persetujuan AI', function (string $sessionToken) use ($request): JsonResponse {
            $ownerHash = $this->owner->key($sessionToken);
            $shownKey = $request->shownConsentKey();

            if ($shownKey !== null && $shownKey !== $this->consents->currentKey()) {
                $consent = $this->consents->state($ownerHash);

                return $this->statusError("Penyedia AI Notebook baru saja berubah. Isi Notebook kini dikirim ke {$consent['provider']}; baca lagi persetujuannya, lalu setujui bila kamu bersedia.", 409, [
                    'data' => ['consent' => $consent],
                ], campusSessionValid: true);
            }

            $this->consents->grant($ownerHash);
            $consent = $this->consents->state($ownerHash);

            return $this->statusSuccess("AI Notebook aktif. Isi yang kamu kirim ke AI diproses oleh {$consent['provider']}.", [
                'data' => ['consent' => $consent],
            ], campusSessionValid: true);
        });
    }

    /**
     * Tarik persetujuan: sejak itu tidak ada lagi isi Notebook yang dikirim ke penyedia AI.
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
            $consent = $this->consents->state($ownerHash);

            return $this->statusSuccess("Persetujuan AI ditarik. Tidak ada lagi isi Notebook yang dikirim ke {$consent['provider']}.", [
                'data' => ['consent' => $consent],
            ], campusSessionValid: true);
        });
    }
}
