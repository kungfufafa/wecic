<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Middleware\StartPortalSession;
use App\Http\Requests\PortalPushTokenRequest;
use App\Services\PortalDataOwner;
use App\Services\PortalPushDevices;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;

#[Group('Perangkat & Realtime', 'Untuk aplikasi mobile: token push Expo perangkat dan otorisasi channel realtime (Reverb) dengan token bearer.', 11)]
class PortalDeviceController extends CampusApiController
{
    private const REGISTER_RESPONSE = "array{status: 'success', message: string, data: array{platform: 'ios'|'android'|'web'|null, registered_at: string|null}, meta: array{timestamp: string, campus_session_valid: true}}";

    private const FORGET_RESPONSE = "array{status: 'success', message: string, data: array{removed: int}, meta: array{timestamp: string, campus_session_valid: true}}";

    private const CHANNEL_AUTH_RESPONSE = 'array{auth: string}';

    public function __construct(
        private readonly PortalPushDevices $devices,
        private readonly PortalDataOwner $owner
    ) {}

    /**
     * Daftarkan token push Expo perangkat ini.
     *
     * Setiap notifikasi baru mahasiswa (tenggat, presensi dibuka, perubahan nilai/pengumuman) dikirim
     * sebagai push ke perangkat yang terdaftar, juga saat aplikasi tertutup. `data.url` pada push adalah
     * tautan MyCIC notifikasi itu. Daftarkan ulang setiap kali login dan saat aplikasi dibuka: token yang
     * sudah lama tidak didaftarkan ulang dihapus (`portal.mobile.push_token_retention_days`), begitu juga
     * token yang ditolak Expo karena aplikasinya sudah dihapus. Satu token hanya milik satu mahasiswa:
     * mendaftarkannya memindahkannya dari mahasiswa sebelumnya.
     */
    #[Response(description: 'Perangkat terdaftar untuk notifikasi push.', type: self::REGISTER_RESPONSE)]
    #[Response(401, description: 'Endpoint ini membutuhkan sesi kampus yang aktif.', type: CampusApiController::SCRAMBLE_SESSION_ERROR_RESPONSE)]
    #[Response(422, description: 'Token push tidak valid.', type: CampusApiController::SCRAMBLE_VALIDATION_ERROR_RESPONSE)]
    public function registerPushToken(PortalPushTokenRequest $request): JsonResponse
    {
        return $this->withCampusSession('Push token registration failed', 'Gagal mendaftarkan perangkat', function (string $sessionToken) use ($request): JsonResponse {
            $pushToken = $this->devices->register(
                $this->owner->key($sessionToken),
                $request->pushToken(),
                $request->platform(),
                StartPortalSession::mobileSession($request)?->id
            );

            return $this->statusSuccess('Perangkat terdaftar untuk notifikasi push.', [
                'data' => [
                    'platform' => $pushToken->platform,
                    'registered_at' => $pushToken->updated_at?->toISOString(),
                ],
            ], campusSessionValid: true);
        });
    }

    /**
     * Hapus token push perangkat ini.
     *
     * Perangkat berhenti menerima push untuk mahasiswa ini. Logout dengan `push_token` melakukan hal yang
     * sama.
     */
    #[Response(description: 'Token push dihapus.', type: self::FORGET_RESPONSE)]
    #[Response(401, description: 'Endpoint ini membutuhkan sesi kampus yang aktif.', type: CampusApiController::SCRAMBLE_SESSION_ERROR_RESPONSE)]
    #[Response(422, description: 'Token push tidak valid.', type: CampusApiController::SCRAMBLE_VALIDATION_ERROR_RESPONSE)]
    public function forgetPushToken(PortalPushTokenRequest $request): JsonResponse
    {
        return $this->withCampusSession('Push token removal failed', 'Gagal menghapus perangkat', function (string $sessionToken) use ($request): JsonResponse {
            return $this->statusSuccess('Token push dihapus.', [
                'data' => ['removed' => $this->devices->forget($this->owner->key($sessionToken), $request->pushToken())],
            ], campusSessionValid: true);
        });
    }

    /**
     * Otorisasi channel realtime (Reverb/Pusher).
     *
     * Sama dengan `POST /broadcasting/auth` aplikasi web, tetapi menerima token bearer: pakai sebagai
     * `authEndpoint` Laravel Echo / pusher-js dengan header `Authorization: Bearer <token>`. Hanya channel
     * `private-portal.session.{id}` milik sesi token itu (`data.channel` saat login) yang diizinkan;
     * channel lain dan sesi yang sudah berakhir dijawab 403.
     */
    #[Response(description: 'Tanda tangan channel untuk socket ini.', type: self::CHANNEL_AUTH_RESPONSE)]
    #[Response(403, description: 'Channel bukan milik sesi ini, atau sesi kampus sudah berakhir.')]
    public function authorizeChannel(Request $request): mixed
    {
        return Broadcast::auth($request);
    }
}
