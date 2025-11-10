<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\SipandaiAssignmentDeleteRequest;
use App\Http\Requests\SipandaiAssignmentUploadRequest;
use App\Http\Requests\SipandaiAttendanceRequest;
use App\Services\SipandaiSubmissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * @group SIPANDAI - Aksi
 *
 * Endpoint untuk mengeksekusi aksi langsung terhadap portal SIPANDAI (upload tugas & absensi).
 */
class PortalActionController extends Controller
{
    public function __construct(private readonly SipandaiSubmissionService $submissionService) {}

    /**
     * Upload tugas SIPANDAI.
     *
     * Endpoint ini mengambil struktur form dari SIPANDAI, menggabungkan field default
     * dengan override yang dikirim klien, lalu mengunggah berkas PDF secara multipart.
     * Wajib dipanggil dalam kondisi sudah login (memiliki `campus_session_token`).
     *
     * - `course_id` → ambil dari query `courses_id` pada URL upload (contoh `...&courses_id=9000`).
     * - `discussion_id` → ambil dari query `id` pada URL upload (contoh `id=30095`).
     * - `meeting_number` → nilai `pertemuan` pada URL upload (contoh `pertemuan=6`).
     * - `attachment` → upload file PDF yang akan dikirim sebagai `data[tugas]`.
     * - `form_values` → optional, untuk override field lain di form upload (misal `keterangan`, `cmd`, dsb.).
     *
     * @bodyParam course_id integer required Ambil dari query `courses_id` pada URL upload SIPANDAI (contoh `...?courses_id=9000`). Example: 9000
     * @bodyParam discussion_id integer required Ambil dari query `id`/`discussion_id` pada URL upload (contoh `id=30095`). Example: 30095
     * @bodyParam meeting_number integer required Ambil dari query `pertemuan` pada URL upload (contoh `pertemuan=6`). Example: 6
     * @bodyParam attachment file required Berkas PDF yang akan dikirim sebagai `data[tugas]` pada form SIPANDAI.
     * @bodyParam form_values object optional Key-value tambahan untuk override field form (misal `keterangan`, `cmd`, dll.). Kosongkan jika tidak ada override.
     */
    public function uploadAssignment(SipandaiAssignmentUploadRequest $request): JsonResponse
    {
        $sessionToken = session('campus_session_token');

        if (! $sessionToken) {
            return response()->json([
                'status' => 'error',
                'message' => 'No active campus session found',
                'data' => null,
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'campus_session_valid' => false,
                ],
            ], 401);
        }

        $validated = $request->validated();
        $overrides = $this->stringifyOverrides($validated['form_values'] ?? []);

        try {
            $attachment = $request->attachment();

            $result = $this->submissionService->submitAssignment(
                $sessionToken,
                (int) $validated['course_id'],
                (int) $validated['discussion_id'],
                (int) $validated['meeting_number'],
                $attachment,
                $overrides
            );

            return $this->buildResponse($result, 'sipandai_assignment_upload');

        } catch (\Throwable $e) {
            Log::error('Failed to upload SIPANDAI assignment', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'course_id' => $validated['course_id'],
                'discussion_id' => $validated['discussion_id'],
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to upload assignment: '.$e->getMessage(),
                'data' => null,
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'campus_session_valid' => false,
                    'source' => 'sipandai_assignment_upload',
                ],
            ], 500);
        }
    }

    /**
     * Hapus tugas yang pernah diunggah di SIPANDAI.
     *
     * - `submission_id` → ambil dari parameter `id` pada URL hapus (contoh: `...?act=jobdel&id=126663&idx=30095&pertemuan=6`).
     * - `discussion_id` → nilai `idx` pada URL hapus; biasanya sama dengan `id` materi/diskusi.
     * - `meeting_number` → nilai `pertemuan` pada URL hapus.
     *
     * @bodyParam submission_id integer required Ambil dari parameter `id` di link hapus SIPANDAI. Example: 126663
     * @bodyParam discussion_id integer required Ambil dari parameter `idx`/`discussion_id` pada link hapus. Example: 30095
     * @bodyParam meeting_number integer required Ambil dari parameter `pertemuan` pada link hapus. Example: 6
     */
    public function deleteAssignment(SipandaiAssignmentDeleteRequest $request): JsonResponse
    {
        $sessionToken = session('campus_session_token');

        if (! $sessionToken) {
            return response()->json([
                'status' => 'error',
                'message' => 'No active campus session found',
                'data' => null,
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'campus_session_valid' => false,
                ],
            ], 401);
        }

        $validated = $request->validated();

        try {
            $result = $this->submissionService->deleteAssignment(
                $sessionToken,
                (int) $validated['submission_id'],
                (int) $validated['discussion_id'],
                (int) $validated['meeting_number']
            );

            return $this->buildResponse($result, 'sipandai_assignment_delete');

        } catch (\Throwable $e) {
            Log::error('Failed to delete SIPANDAI assignment', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'submission_id' => $validated['submission_id'],
                'discussion_id' => $validated['discussion_id'],
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete assignment: '.$e->getMessage(),
                'data' => null,
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'campus_session_valid' => false,
                    'source' => 'sipandai_assignment_delete',
                ],
            ], 500);
        }
    }

    /**
     * Tandai kehadiran pertemuan SIPANDAI.
     *
     * Mengisi form absensi (GET/POST) sesuai struktur halaman SIPANDAI. Endpoint
     * ini otomatis mendeteksi apakah absensi butuh parameter `pertemuan` atau hanya
     * kode QR saja, kemudian mengirim payload yang valid ke server kampus.
     *
     * - `course_id` → nilai `courseid` pada URL absensi QR (contoh `courseid=8810`).
     * - `discussion_id` → nilai `modulesid` / `id` diskusi pada URL absensi (contoh `modulesid=30216`).
     * - `meeting_number` → opsional; isi jika halaman absensi menampilkan `pertemuan=X`.
     * - `form_values` → field tambahan untuk override (misalnya `kode=d6a53600`, `cmd=Simpan`).
     *
     * @bodyParam course_id integer required Ambil dari parameter `courseid` pada URL absensi. Example: 8810
     * @bodyParam discussion_id integer required Ambil dari parameter `modulesid` / `id` pada URL absensi. Example: 30216
     * @bodyParam meeting_number integer optional Ambil dari parameter `pertemuan` jika tersedia. Example: 6
     * @bodyParam form_values object optional Key-value override (misal `kode=d6a53600`, `cmd=Simpan`). Kosongkan bila tidak perlu override.
     */
    public function markAttendance(SipandaiAttendanceRequest $request): JsonResponse
    {
        $sessionToken = session('campus_session_token');

        if (! $sessionToken) {
            return response()->json([
                'status' => 'error',
                'message' => 'No active campus session found',
                'data' => null,
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'campus_session_valid' => false,
                ],
            ], 401);
        }

        $validated = $request->validated();
        $overrides = $this->stringifyOverrides($validated['form_values'] ?? []);

        try {
            $result = $this->submissionService->submitAttendance(
                $sessionToken,
                (int) $validated['course_id'],
                (int) $validated['discussion_id'],
                isset($validated['meeting_number']) ? (int) $validated['meeting_number'] : null,
                $overrides
            );

            return $this->buildResponse($result, 'sipandai_attendance');

        } catch (\Throwable $e) {
            Log::error('Failed to submit SIPANDAI attendance', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'course_id' => $validated['course_id'],
                'discussion_id' => $validated['discussion_id'],
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to submit attendance: '.$e->getMessage(),
                'data' => null,
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'campus_session_valid' => false,
                    'source' => 'sipandai_attendance',
                ],
            ], 500);
        }
    }

    /**
     * Helper to build consistent JSON responses.
     */
    private function buildResponse(array $result, string $source): JsonResponse
    {
        $statusCode = $result['success'] ? 200 : 422;

        return response()->json([
            'status' => $result['success'] ? 'success' : 'error',
            'message' => $result['message'],
            'data' => $result['data'] ?? null,
            'meta' => [
                'timestamp' => now()->toISOString(),
                'campus_session_valid' => $result['success'],
                'source' => $source,
            ],
        ], $statusCode);
    }

    /**
     * Normalise override values to strings.
     */
    private function stringifyOverrides(array $overrides): array
    {
        return array_map(static function ($value) {
            if (is_bool($value)) {
                return $value ? '1' : '0';
            }

            if (is_array($value)) {
                return json_encode($value, JSON_THROW_ON_ERROR);
            }

            return (string) $value;
        }, $overrides);
    }
}
