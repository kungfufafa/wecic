<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Middleware\StartPortalSession;
use App\Http\Requests\PortalAccountDestroyRequest;
use App\Services\PortalAccountEraser;
use App\Services\PortalBroadcastService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

#[Group('Akun', 'Hapus akun WeCIC beserta semua data yang disimpan server untuk mahasiswa ini.', 18)]
class PortalAccountController extends CampusApiController
{
    public function __construct(
        private readonly PortalAccountEraser $eraser,
        private readonly PortalBroadcastService $broadcast
    ) {}

    /**
     * Hapus akun WeCIC dan seluruh datanya, lalu keluar dari semua perangkat.
     *
     * Yang dihapus: arsip dan berkas materi, notebook beserta unggahan dan kartu Ulasan hariannya,
     * persetujuan AI Notebook,
     * akses offline (hash password dan password terenkripsi), notifikasi,
     * token push, antrean offline, catatan unggahan ujian, cache data portal,
     * serta semua sesi web dan login aplikasi. Akun portal kampus sendiri
     * tidak tersentuh. Tidak bisa dibatalkan.
     */
    #[Response(description: 'Akun dan datanya dihapus; semua sesi berakhir.', type: "array{status: 'success', message: string, data: array{versions: int, files: int, notebooks: int, ai_consents: int, notifications: int, outbox: int, push_tokens: int, exam_records: int, offline_accounts: int, sessions: int, mobile_sessions: int}, meta: array{timestamp: string, campus_session_valid: false}}")]
    #[Response(422, description: 'Konfirmasi belum dikirim.', type: CampusApiController::SCRAMBLE_VALIDATION_ERROR_RESPONSE)]
    #[Response(401, description: 'Endpoint ini membutuhkan sesi kampus yang aktif.', type: CampusApiController::SCRAMBLE_SESSION_ERROR_RESPONSE)]
    public function destroy(PortalAccountDestroyRequest $request): JsonResponse
    {
        return $this->withCampusSession('Account delete failed', 'Gagal menghapus akun', function (string $sessionToken): JsonResponse {
            $sessionId = (string) session()->getId();
            $deleted = $this->eraser->erase($sessionToken);

            StartPortalSession::bindMobileSession(null);
            session()->invalidate();
            session()->regenerateToken();

            $this->broadcast->sessionNotice($sessionId, $sessionToken, 'logged_out', 'Akun WeCIC kamu sudah dihapus.', 'warning');

            Log::info('Student account erased', ['rows' => array_sum($deleted)]);

            return $this->statusSuccess('Akun WeCIC dan semua datamu sudah dihapus.', ['data' => $deleted], campusSessionValid: false);
        });
    }
}
