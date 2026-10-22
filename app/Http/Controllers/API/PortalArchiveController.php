<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Requests\PortalArchiveClassRequest;
use App\Http\Requests\PortalArchiveDestroyRequest;
use App\Http\Requests\PortalArchiveVersionIndexRequest;
use App\Http\Requests\PortalBackgroundSyncRequest;
use App\Jobs\ArchiveCourseMaterialsJob;
use App\Models\PortalArchiveVersion;
use App\Services\PortalArchivedFileStore;
use App\Services\PortalBackgroundSync;
use App\Services\PortalClassCatalog;
use App\Services\PortalDataArchive;
use App\Services\PortalDataOwner;
use App\Services\PortalOfflineAccess;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

#[Group('Arsip', 'Cadangan lokal data portal per mahasiswa: riwayat versi, deteksi data janggal, dan berkas materi kuliah.', 17)]
class PortalArchiveController extends CampusApiController
{
    private const VERSION_ITEM = "array{id: int, dataset: string, params: array<string, mixed>, subject: string|null, status: 'trusted'|'suspect'|'rejected'|'dismissed', findings: list<array{rule: string, label: string, message: string}>, first_seen_at: string|null, last_seen_at: string|null, seen_count: int, trusted_at: string|null, resolved_at: string|null}";

    private const BACKGROUND_SYNC_STATUS = 'array{available: bool, enabled: bool, active: bool, pending_login: bool, rejected: bool, interval_minutes: int, credential_days: int, last_synced_at: string|null, last_status: string|null, last_error: string|null}';

    private const SUMMARY_RESPONSE = "array{status: 'success', message: string, data: array{datasets: list<array{dataset: string, label: string, versions: int, groups: int, open_suspects: int, last_seen_at: string|null}>, files: array{count: int, bytes: int}, background_sync: ".self::BACKGROUND_SYNC_STATUS.'}, meta: array{timestamp: string, campus_session_valid: true}}';

    private const VERSIONS_RESPONSE = "array{status: 'success', message: string, data: array{dataset: string, label: string, groups: list<array{params_hash: string, subject: string|null, params: array<string, mixed>, versions: int, open_suspects: int, last_seen_at: string|null}>, versions: list<array<string, mixed>>}, meta: array{timestamp: string, campus_session_valid: true}}";

    private const VERSION_RESPONSE = "array{status: 'success', message: string, data: array{version: ".self::VERSION_ITEM.', label: string, is_current: bool, payload: array<string, mixed>, baseline: '.self::VERSION_ITEM."|null, diff: list<array{path: string, kind: 'added'|'removed'|'changed', old: string|null, new: string|null}>}, meta: array{timestamp: string, campus_session_valid: true}}";

    public function __construct(
        private readonly PortalDataArchive $archive,
        private readonly PortalArchivedFileStore $files,
        private readonly PortalOfflineAccess $offlineAccess,
        private readonly PortalDataOwner $owner,
        private readonly PortalClassCatalog $classCatalog,
        private readonly PortalBackgroundSync $backgroundSync
    ) {}

    /**
     * Ringkasan arsip: jumlah versi per data, data janggal yang menunggu dicek,
     * dan status sinkronisasi di background.
     *
     * Dibaca dari database MyCIC, tidak pernah menunggu portal.
     */
    #[Response(description: 'Ringkasan arsip.', type: self::SUMMARY_RESPONSE)]
    #[Response(401, description: 'Endpoint ini membutuhkan sesi kampus yang aktif.', type: CampusApiController::SCRAMBLE_SESSION_ERROR_RESPONSE)]
    public function index(): JsonResponse
    {
        return $this->withCampusSession('Archive summary failed', 'Gagal memuat arsip', function (string $sessionToken): JsonResponse {
            $ownerHash = $this->owner->key($sessionToken);

            return $this->statusSuccess('Archive summary retrieved', [
                'data' => [
                    'datasets' => $this->archive->summary($ownerHash),
                    'files' => $this->files->totals($ownerHash),
                    'background_sync' => $this->backgroundSync->status($ownerHash),
                ],
            ], campusSessionValid: true);
        });
    }

    /**
     * Nyalakan atau matikan sinkronisasi arsip di background.
     *
     * Mematikannya langsung menghapus password yang tersimpan. Menyalakannya
     * berlaku sejak login online berikutnya, karena password baru disimpan saat itu.
     */
    #[Response(description: 'Status sinkronisasi background setelah diubah.', type: "array{status: 'success', message: string, data: ".self::BACKGROUND_SYNC_STATUS.', meta: array{timestamp: string, campus_session_valid: true}}')]
    #[Response(422, description: 'Nilai `enabled` tidak valid.', type: CampusApiController::SCRAMBLE_VALIDATION_ERROR_RESPONSE)]
    #[Response(401, description: 'Endpoint ini membutuhkan sesi kampus yang aktif.', type: CampusApiController::SCRAMBLE_SESSION_ERROR_RESPONSE)]
    public function backgroundSync(PortalBackgroundSyncRequest $request): JsonResponse
    {
        return $this->withCampusSession('Background sync toggle failed', 'Gagal mengubah sinkronisasi background', function (string $sessionToken) use ($request): JsonResponse {
            $ownerHash = $this->owner->key($sessionToken);
            $enabled = $request->boolean('enabled');

            $this->backgroundSync->setEnabled($ownerHash, $enabled);

            return $this->statusSuccess(
                $enabled
                    ? 'Sinkronisasi background dinyalakan. Berlaku sejak kamu masuk lagi saat portal online.'
                    : 'Sinkronisasi background dimatikan dan password tersimpan sudah dihapus.',
                ['data' => $this->backgroundSync->status($ownerHash)],
                campusSessionValid: true
            );
        });
    }

    /**
     * Riwayat versi satu data (terbaru dulu).
     *
     * Data berparameter (detail kelas, pertemuan, KRS) dikelompokkan per parameter di `groups`;
     * kirim `params_hash` untuk memilih kelompok.
     */
    #[PathParameter('dataset', 'Nama data yang diarsipkan, mis. `grades`, `payments`, `class_detail`.', example: 'grades')]
    #[Response(description: 'Riwayat versi.', type: self::VERSIONS_RESPONSE)]
    #[Response(404, description: 'Data ini tidak diarsipkan.', type: CampusApiController::SCRAMBLE_NOT_FOUND_RESPONSE)]
    #[Response(401, description: 'Endpoint ini membutuhkan sesi kampus yang aktif.', type: CampusApiController::SCRAMBLE_SESSION_ERROR_RESPONSE)]
    public function versions(PortalArchiveVersionIndexRequest $request, string $dataset): JsonResponse
    {
        return $this->withCampusSession('Archive versions failed', 'Gagal memuat riwayat arsip', function (string $sessionToken) use ($request, $dataset): JsonResponse {
            if (! $this->archive->archives($dataset)) {
                return $this->statusError('Data ini tidak diarsipkan.', 404, campusSessionValid: true);
            }

            $versions = $this->archive->versions($this->owner->key($sessionToken), $dataset, $request->paramsHash());

            return $this->statusSuccess('Archive versions retrieved', [
                'data' => [
                    'dataset' => $dataset,
                    'label' => $this->archive->label($dataset),
                    ...$versions,
                ],
            ], campusSessionValid: true);
        });
    }

    /**
     * Isi satu versi beserta perbandingannya dengan versi terpercaya sebelumnya.
     */
    #[PathParameter('version', 'Id versi arsip.', type: 'integer', example: 12)]
    #[Response(description: 'Detail versi.', type: self::VERSION_RESPONSE)]
    #[Response(404, description: 'Versi tidak ditemukan.', type: CampusApiController::SCRAMBLE_NOT_FOUND_RESPONSE)]
    #[Response(401, description: 'Endpoint ini membutuhkan sesi kampus yang aktif.', type: CampusApiController::SCRAMBLE_SESSION_ERROR_RESPONSE)]
    public function show(int $version): JsonResponse
    {
        return $this->withVersion($version, 'Archive version failed', 'Gagal memuat versi arsip', function (string $sessionToken, PortalArchiveVersion $archived): JsonResponse {
            $baseline = $this->archive->baseline($archived);

            return $this->statusSuccess('Archive version retrieved', [
                'data' => [
                    'version' => $archived->toApiArray(),
                    'label' => $this->archive->label($archived->dataset),
                    'is_current' => $this->archive->isCurrent($archived),
                    'payload' => $archived->payload,
                    'baseline' => $baseline?->toApiArray(),
                    'diff' => $baseline !== null ? $this->archive->diff($baseline->payload, $archived->payload) : [],
                ],
            ], campusSessionValid: true);
        });
    }

    /**
     * Terima versi dari portal: versi ini menjadi data yang ditampilkan MyCIC.
     */
    #[PathParameter('version', 'Id versi arsip yang ditahan (janggal atau ditolak).', type: 'integer', example: 12)]
    #[Response(description: 'Versi diterima.', type: "array{status: 'success', message: string, data: array{version: ".self::VERSION_ITEM.'}, meta: array{timestamp: string, campus_session_valid: true}}')]
    #[Response(409, description: 'Versi ini tidak sedang ditahan.', type: CampusApiController::SCRAMBLE_STATUS_ERROR_RESPONSE)]
    #[Response(404, description: 'Versi tidak ditemukan.', type: CampusApiController::SCRAMBLE_NOT_FOUND_RESPONSE)]
    public function accept(int $version): JsonResponse
    {
        return $this->withVersion($version, 'Archive accept failed', 'Gagal menerima versi', function (string $sessionToken, PortalArchiveVersion $archived): JsonResponse {
            if (! in_array($archived->status, [PortalArchiveVersion::STATUS_SUSPECT, PortalArchiveVersion::STATUS_REJECTED], true)) {
                return $this->statusError('Versi ini tidak sedang ditahan.', 409, campusSessionValid: true);
            }

            $accepted = $this->archive->accept($sessionToken, $archived);

            return $this->statusSuccess('Data portal diterima dan sekarang ditampilkan.', [
                'data' => ['version' => $accepted->toApiArray()],
            ], campusSessionValid: true);
        });
    }

    /**
     * Tolak versi dari portal: MyCIC tetap menampilkan data tersimpan.
     */
    #[PathParameter('version', 'Id versi arsip yang janggal.', type: 'integer', example: 12)]
    #[Response(description: 'Versi ditolak.', type: "array{status: 'success', message: string, data: array{version: ".self::VERSION_ITEM.'}, meta: array{timestamp: string, campus_session_valid: true}}')]
    #[Response(409, description: 'Versi ini tidak sedang menunggu pemeriksaan.', type: CampusApiController::SCRAMBLE_STATUS_ERROR_RESPONSE)]
    #[Response(404, description: 'Versi tidak ditemukan.', type: CampusApiController::SCRAMBLE_NOT_FOUND_RESPONSE)]
    public function reject(int $version): JsonResponse
    {
        return $this->withVersion($version, 'Archive reject failed', 'Gagal menolak versi', function (string $sessionToken, PortalArchiveVersion $archived): JsonResponse {
            if ($archived->status !== PortalArchiveVersion::STATUS_SUSPECT) {
                return $this->statusError('Versi ini tidak sedang menunggu pemeriksaan.', 409, campusSessionValid: true);
            }

            $rejected = $this->archive->reject($sessionToken, $archived);

            return $this->statusSuccess('Data portal ditolak. MyCIC tetap menampilkan data tersimpan.', [
                'data' => ['version' => $rejected->toApiArray()],
            ], campusSessionValid: true);
        });
    }

    /**
     * Unduh satu versi sebagai berkas JSON (bukti untuk BAAK atau keuangan).
     */
    #[PathParameter('version', 'Id versi arsip.', type: 'integer', example: 12)]
    #[Response(description: 'Berkas JSON versi arsip.', mediaType: 'application/json', type: 'array<string, mixed>')]
    #[Response(404, description: 'Versi tidak ditemukan.', type: CampusApiController::SCRAMBLE_NOT_FOUND_RESPONSE)]
    public function export(int $version): JsonResponse
    {
        return $this->withVersion($version, 'Archive export failed', 'Gagal mengekspor versi', function (string $sessionToken, PortalArchiveVersion $archived): JsonResponse {
            $filename = sprintf('mycic-arsip-%s-%s.json', $archived->dataset, ($archived->last_seen_at ?? now())->format('Ymd-His'));

            return response()->json([
                'exported_at' => now()->toISOString(),
                'source' => 'MyCIC (arsip lokal dari portal kampus)',
                'label' => $this->archive->label($archived->dataset),
                'version' => $archived->toApiArray(),
                'payload' => $archived->payload,
            ], 200, [
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        });
    }

    /**
     * Berkas materi kuliah yang tersimpan, dikelompokkan per mata kuliah.
     *
     * Berkas ikut tersimpan otomatis setiap kali pertemuan dibuka; tetap bisa dibuka saat portal mati.
     */
    #[Response(description: 'Daftar berkas arsip.', type: "array{status: 'success', message: string, data: array{courses: list<array{course_key: string|null, course_name: string|null, files: list<array{id: int, course_key: string|null, course_name: string|null, meeting_id: string|null, meeting_number: int|null, kind: string, kind_label: string, title: string|null, filename: string, mime: string, size: int, archived_at: string|null, download_url: string}>}>, totals: array{count: int, bytes: int}}, meta: array{timestamp: string, campus_session_valid: true}}")]
    #[Response(401, description: 'Endpoint ini membutuhkan sesi kampus yang aktif.', type: CampusApiController::SCRAMBLE_SESSION_ERROR_RESPONSE)]
    public function files(): JsonResponse
    {
        return $this->withCampusSession('Archived files failed', 'Gagal memuat berkas arsip', function (string $sessionToken): JsonResponse {
            $ownerHash = $this->owner->key($sessionToken);

            return $this->statusSuccess('Archived files retrieved', [
                'data' => [
                    'courses' => $this->files->grouped($ownerHash),
                    'totals' => $this->files->totals($ownerHash),
                ],
            ], campusSessionValid: true);
        });
    }

    /**
     * Buka atau unduh satu berkas arsip (`download=1` untuk mengunduh).
     */
    #[PathParameter('file', 'Id berkas arsip.', type: 'integer', example: 7)]
    #[Response(description: 'Isi berkas.', mediaType: 'application/octet-stream', type: 'string')]
    #[Response(404, description: 'Berkas tidak ditemukan.', type: CampusApiController::SCRAMBLE_NOT_FOUND_RESPONSE)]
    public function file(Request $request, int $file): HttpResponse
    {
        $sessionToken = $this->sessionToken();

        if (! $sessionToken) {
            return $this->missingSessionStatusResponse();
        }

        $archived = $this->files->findForOwner($this->owner->key($sessionToken), $file);
        $path = $archived !== null ? $this->files->absolutePath($archived) : null;

        if ($archived === null || $path === null) {
            return $this->statusError('Berkas arsip tidak ditemukan.', 404, campusSessionValid: true);
        }

        $response = response()->file($path, [
            'Content-Type' => $archived->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
        ]);
        $fallbackName = (string) preg_replace('/[^A-Za-z0-9._-]+/', '-', Str::ascii($archived->filename));

        return $response->setContentDisposition(
            $request->boolean('download') ? 'attachment' : 'inline',
            $archived->filename,
            $fallbackName !== '' ? $fallbackName : 'berkas'
        );
    }

    /**
     * Arsipkan semua materi satu kelas (RPS dan berkas setiap pertemuan) di background.
     *
     * Kelas dicari di daftar kelas mahasiswa sendiri. Hasilnya diumumkan lewat event realtime.
     */
    #[Response(202, description: 'Pengarsipan berjalan di background.', type: "array{status: 'success', message: string, data: array{status: 'queued', class_id: string}, meta: array{timestamp: string, campus_session_valid: true}}")]
    #[Response(404, description: 'Kelas tidak ada di daftar kelas mahasiswa.', type: CampusApiController::SCRAMBLE_NOT_FOUND_RESPONSE)]
    #[Response(422, description: 'Parameter tidak valid.', type: CampusApiController::SCRAMBLE_VALIDATION_ERROR_RESPONSE)]
    public function archiveClass(PortalArchiveClassRequest $request): JsonResponse
    {
        return $this->withCampusSession('Class material archive failed', 'Gagal mengarsipkan materi kelas', function (string $sessionToken) use ($request): JsonResponse {
            $classParams = $this->classCatalog->findById($sessionToken, $request->classId());

            if ($classParams === null) {
                return $this->statusError('Kelas tidak ditemukan di daftar kelas kamu.', 404, campusSessionValid: true);
            }

            ArchiveCourseMaterialsJob::dispatch((string) session()->getId(), $sessionToken, $classParams);

            return $this->statusSuccess('Materi kelas sedang diarsipkan. Kamu akan diberi tahu saat selesai.', [
                'data' => ['status' => 'queued', 'class_id' => $request->classId()],
            ], campusSessionValid: true, statusCode: 202);
        });
    }

    /**
     * Hapus seluruh arsip milik mahasiswa ini (data, berkas materi, dan akses offline).
     *
     * Tidak bisa dibatalkan. Notebook tidak ikut terhapus, tetapi sumber dari berkas arsip tetap memakai teks yang sudah dibaca.
     */
    #[Response(description: 'Arsip dihapus.', type: "array{status: 'success', message: string, data: array{versions: int, files: int}, meta: array{timestamp: string, campus_session_valid: true}}")]
    #[Response(422, description: 'Konfirmasi belum dikirim.', type: CampusApiController::SCRAMBLE_VALIDATION_ERROR_RESPONSE)]
    public function destroy(PortalArchiveDestroyRequest $request): JsonResponse
    {
        return $this->withCampusSession('Archive delete failed', 'Gagal menghapus arsip', function (string $sessionToken): JsonResponse {
            $ownerHash = $this->owner->key($sessionToken);

            $deleted = [
                'versions' => $this->archive->deleteOwner($ownerHash),
                'files' => $this->files->deleteOwner($ownerHash),
            ];
            $this->offlineAccess->forget($ownerHash);

            return $this->statusSuccess('Arsip MyCIC kamu sudah dihapus.', ['data' => $deleted], campusSessionValid: true);
        });
    }

    /**
     * @param  callable(string, PortalArchiveVersion): JsonResponse  $callback
     */
    private function withVersion(int $versionId, string $failureLogMessage, string $clientErrorPrefix, callable $callback): JsonResponse
    {
        return $this->withCampusSession($failureLogMessage, $clientErrorPrefix, function (string $sessionToken) use ($versionId, $callback): JsonResponse {
            $version = $this->archive->findForOwner($this->owner->key($sessionToken), $versionId);

            if ($version === null) {
                return $this->statusError('Versi arsip tidak ditemukan.', 404, campusSessionValid: true);
            }

            return $callback($sessionToken, $version);
        }, ['version_id' => $versionId]);
    }
}
