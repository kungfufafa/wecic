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

    private const BACKGROUND_SYNC_STATUS = 'array{available: bool, enabled: bool, active: bool, pending_login: bool, rejected: bool, interval_minutes: int, credential_days: int, last_synced_at: string|null, last_status: string|null, last_error: string|null, courses_enabled: bool, courses_interval_minutes: int, last_course_sync_at: string|null}';

    private const SUMMARY_RESPONSE = "array{status: 'success', message: string, data: array{datasets: list<array{dataset: string, label: string, versions: int, groups: int, open_suspects: int, last_seen_at: string|null}>, files: array{count: int, bytes: int}, background_sync: ".self::BACKGROUND_SYNC_STATUS.'}, meta: array{timestamp: string, campus_session_valid: true}}';

    private const VERSIONS_RESPONSE = "array{status: 'success', message: string, data: array{dataset: string, label: string, groups: list<array{params_hash: string, subject: string|null, params: array<string, mixed>, versions: int, open_suspects: int, last_seen_at: string|null}>, selected_params_hash: string|null, versions: list<array<string, mixed>>}, meta: array{timestamp: string, campus_session_valid: true}}";

    private const VERSION_RESPONSE = "array{status: 'success', message: string, data: array{version: ".self::VERSION_ITEM.', label: string, is_current: bool, payload: array<string, mixed>, baseline: '.self::VERSION_ITEM."|null, diff: list<array{path: string, kind: 'added'|'removed'|'changed', old: string|null, new: string|null}>, diff_total: int, diff_truncated: bool}, meta: array{timestamp: string, campus_session_valid: true}}";

    /**
     * @var list<string>
     */
    private const INLINE_FILE_TYPES = [
        'application/pdf',
        'image/png',
        'image/jpeg',
        'image/gif',
        'image/webp',
        'text/plain',
    ];

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
     * dan status sinkronisasi di background (termasuk kapan materi semua kelas
     * terakhir diarsipkan).
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
     * Mematikannya langsung menghapus password yang tersimpan, dan pilihan itu
     * tetap berlaku meski MyCIC belum menyimpan apa pun untuk mahasiswa ini
     * (misalnya setelah arsipnya dihapus). Menyalakannya berlaku sejak login
     * online berikutnya, karena password baru disimpan saat itu.
     */
    #[Response(description: 'Status sinkronisasi background setelah diubah.', type: "array{status: 'success', message: string, data: ".self::BACKGROUND_SYNC_STATUS.', meta: array{timestamp: string, campus_session_valid: true}}')]
    #[Response(409, description: 'Pilihan tidak bisa diterapkan (mis. sinkronisasi background tidak tersedia); `data` berisi status yang berlaku.', type: "array{status: 'error', message: string, data: ".self::BACKGROUND_SYNC_STATUS.', meta: array{timestamp: string, campus_session_valid: true}}')]
    #[Response(422, description: 'Nilai `enabled` tidak valid.', type: CampusApiController::SCRAMBLE_VALIDATION_ERROR_RESPONSE)]
    #[Response(401, description: 'Endpoint ini membutuhkan sesi kampus yang aktif.', type: CampusApiController::SCRAMBLE_SESSION_ERROR_RESPONSE)]
    public function backgroundSync(PortalBackgroundSyncRequest $request): JsonResponse
    {
        return $this->withCampusSession('Background sync toggle failed', 'Gagal mengubah sinkronisasi background', function (string $sessionToken) use ($request): JsonResponse {
            $ownerHash = $this->owner->key($sessionToken);
            $enabled = $request->boolean('enabled');

            if (! $enabled && $this->offlineAccess->findForOwner($ownerHash) === null) {
                $this->offlineAccess->rememberBackgroundSyncOptOut($ownerHash);
            }

            $this->backgroundSync->setEnabled($ownerHash, $enabled);
            $status = $this->backgroundSync->status($ownerHash);

            if ($status['enabled'] !== $enabled) {
                return $this->statusError(
                    $status['available']
                        ? 'Pengaturan sinkronisasi background belum tersimpan. Coba lagi beberapa saat.'
                        : 'Sinkronisasi background tidak tersedia di WeCIC ini.',
                    409,
                    ['data' => $status],
                    campusSessionValid: true
                );
            }

            return $this->statusSuccess(
                $enabled
                    ? 'Sinkronisasi background dinyalakan. Berlaku sejak kamu masuk lagi saat portal online.'
                    : 'Sinkronisasi background dimatikan dan password tersimpan sudah dihapus.',
                ['data' => $status],
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
            // Archived pages still hold the portal's write-effect links (K13);
            // they are dropped before comparing, so no diff value carries one.
            $payload = $this->withoutWriteEffectLinks($archived->payload ?? []);
            $comparison = $baseline !== null
                ? $this->archive->compare($this->withoutWriteEffectLinks($baseline->payload ?? []), $payload, 200, $archived->dataset)
                : ['changes' => [], 'total' => 0, 'truncated' => false];

            return $this->statusSuccess('Archive version retrieved', [
                'data' => [
                    'version' => $archived->toApiArray(),
                    'label' => $this->archive->label($archived->dataset),
                    'is_current' => $this->archive->isCurrent($archived),
                    'payload' => $payload,
                    'baseline' => $baseline?->toApiArray(),
                    'diff' => $comparison['changes'],
                    'diff_total' => $comparison['total'],
                    'diff_truncated' => $comparison['truncated'],
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

            return $this->statusSuccess('Data portal ditolak. WeCIC tetap menampilkan data tersimpan.', [
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
                'source' => 'WeCIC (arsip lokal dari portal kampus)',
                'label' => $this->archive->label($archived->dataset),
                'version' => $archived->toApiArray(),
                'payload' => $this->withoutWriteEffectLinks($archived->payload ?? []),
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
     *
     * Hanya PDF, gambar biasa, dan teks polos yang dibuka langsung di browser.
     * Jenis lain (SVG, HTML, XML, dokumen Office, dll.) selalu diunduh sebagai
     * `application/octet-stream` dengan CSP `sandbox`, supaya isi berkas dari
     * portal tidak bisa menjalankan skrip di MyCIC.
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

        $type = $this->fileType($archived->mime);
        $safeType = in_array($type, self::INLINE_FILE_TYPES, true);
        $headers = [
            'Content-Type' => $safeType ? $archived->mime : 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
        ];

        // Browser PDF viewers refuse to render inside a sandboxed document.
        if ($type !== 'application/pdf') {
            $headers['Content-Security-Policy'] = 'sandbox';
        }

        $response = response()->file($path, $headers);
        $fallbackName = (string) preg_replace('/[^A-Za-z0-9._-]+/', '-', Str::ascii($archived->filename));

        return $response->setContentDisposition(
            $safeType && ! $request->boolean('download') ? 'inline' : 'attachment',
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

            return $this->statusSuccess('Arsip WeCIC kamu sudah dihapus.', ['data' => $deleted], campusSessionValid: true);
        });
    }

    /**
     * The media type of an archived file without its parameters. Archived
     * files carry whatever type the portal sent; only INLINE_FILE_TYPES are
     * ones a browser renders without running script on the MyCIC origin.
     */
    private function fileType(?string $mime): string
    {
        return strtolower(trim(explode(';', (string) $mime)[0]));
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
