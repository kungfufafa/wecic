<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Exceptions\PortalException;
use App\Exceptions\PortalOfflineException;
use App\Http\Requests\ClassDetailRequest;
use App\Http\Requests\PortalRefreshRequest;
use App\Http\Requests\SipandaiFlowRequest;
use App\Http\Requests\SipandaiMeetingDetailRequest;
use App\Jobs\RefreshPortalDataJob;
use App\Services\DashboardMetricsBuilder;
use App\Services\DashboardTodayAttendance;
use App\Services\DashboardTodayClassesBuilder;
use App\Services\Extractors\ClassesExtractor;
use App\Services\PortalClassCatalog;
use App\Services\PortalDatasetRepository;
use App\Services\PortalEndpointRegistry;
use App\Services\PortalFetchPolicy;
use App\Services\PortalFinanceStatusResolver;
use App\Services\PortalOfflineAccess;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

#[Group('Portal Data', 'Endpoint pembacaan data profil, kelas, detail materi, dan alur data SIPANDAI.', 20)]
class PortalDataController extends CampusApiController
{
    private const DASHBOARD_RESPONSE = "array{status: 'success', message: string, data: array{user_info: array<string, mixed>, dashboard_overview: array{total_courses: string, gpa: null, total_credits: string, outstanding_payments: null}, recent_activity: list<array<string, mixed>>, classes_summary: array<string, mixed>, status: string, timestamp: string, portal_context: array{academic_period: array{label: string, academic_year: string, term: 'ganjil'|'genap'|null, source: string}|null, semester_number: int|null, semester_source: string|null, semester_is_estimate: bool, semester_label: string|null, summary_label: string|null, server_date: string|null, server_date_label: string|null, guide_links: list<array{label: string, path: string, filename: string}>, questionnaire: array{available: bool, title: string|null, academic_year: string|null, description: string|null, details: list<string>}, notice: array{text: string, dates: list<string>, is_future: bool, kind: string}|null, sidebar_menu: list<array{label: string, href_act: string|null}>, extracted_at: string}|null, metrics: array{ipk: array{value: float|null, source: 'official'|'computed'|null, predicate_estimate: string|null, status: 'ready'|'pending'|'unavailable'|'failed'|'empty'}, sks_passed: array{value: int|null, conversion_sks: int|null, target_sks: int|null, remaining_sks: int|null, progress_percent: float|null, status: string}, sks_current: array{value: int|null, courses: int|null, status: string}, bills: array{status: 'clear'|'outstanding'|'unknown'|'pending'|'failed', has_outstanding: bool|null, total_amount: int|null, total_amount_label: string|null, count: int|null}, skkm: array{valid_points: int|float|null, required_points: int|float|null, remaining_points: int|float|null, progress_percent: float|null, is_met: bool|null, status: string}, sources: array<string, array{status: string, synced_at: string|null}>, pending_sources: list<string>, syncing_sources: list<string>}, pending_sources: list<string>}, meta: array{timestamp: string, campus_session_valid: true, status: string, synced_at: string|null, data_origin: string}}";

    private const PORTAL_CONTEXT_RESPONSE = "array{status: 'success', message: string, data: array{academic_period: array{label: string, academic_year: string, term: 'ganjil'|'genap'|null, source: string}|null, semester_number: int|null, semester_source: string|null, semester_is_estimate: bool, semester_label: string|null, summary_label: string|null, server_date: string|null, server_date_label: string|null, guide_links: list<array{label: string, path: string, filename: string}>, questionnaire: array{available: bool, title: string|null, academic_year: string|null, description: string|null, details: list<string>}, notice: array{text: string, dates: list<string>, is_future: bool, kind: string}|null, sidebar_menu: list<array{label: string, href_act: string|null}>, extracted_at: string}, meta: array{timestamp: string, campus_session_valid: true, status: string, synced_at: string|null, data_origin: string}}";

    private const FINANCE_SUMMARY_RESPONSE = "array{status: 'success', message: string, data: array{outstanding_summary: array{status: 'clear'|'outstanding'|'unknown', has_outstanding: bool|null, total_amount: int|null, total_amount_label: string|null, count: int, rows_sum_matches_total: bool|null}, outstanding_bills: list<array{no: string|null, name: string, amount: int|null, amount_label: string|null, description: string|null, installment_stage: int|null, study_year: int|null, installment_amount: int|null, academic_year: string|null}>, has_outstanding: bool|null, outstanding_total: int|null, outstanding_total_label: string|null, current_study_year: int|null, current_academic_year: string|null, current_year: array<string, mixed>|null, current_year_stages_paid: list<int>, stage1_paid_for_current_year: bool|'unknown', next_stage: array{study_year: int, academic_year: string|null, stage: int, status: 'unpaid'|'upcoming'|'unknown', reason: string, deadline: string|null}|null, active_deadline: array{stage: int|null, date: string, days_left: int, is_future: true, text: string, source: 'portal_notice'}|null, latest_payment: array<string, mixed>|null, total_paid_amount: int|null, portal_total_amount: int|null, portal_total_amount_label: string|null, history_available: bool, extracted_at: string|null}, meta: array{timestamp: string, campus_session_valid: true, status: string, synced_at: string|null, data_origin: string, notice_sources: array<string, string>}}";

    private const SKKM_RESPONSE = "array{status: 'success', message: string, data: array{found: bool, stats: array{stat_counts: array{valid: int|null, invalid: int|null, pending: int|null}, total_points: array{valid: int|float|null, invalid: int|float|null, pending: int|float|null}, required_points: int|float|null, required_points_source: 'portal'|'config'|null}, graduation_status: array{text: string|null, is_met: bool|null}, entries: list<array{no: int|null, skkm_id: string|null, name: string, activity: string|null, level: string|null, type: string|null, points: int|float|null, points_label: string|null, status: 'valid'|'invalid'|'pending'|'unknown', status_label: string|null, has_attachment: bool, attachment_path: string|null, has_actions: bool, action_types: list<string>}>, summary: array<string, mixed>, notice: array{text: string, dates: list<string>, is_future: bool, kind: string}|null, message: string|null, guide_path: string|null, guide_year: int|null, add_available: bool, extracted_at: string}, meta: array{timestamp: string, campus_session_valid: true, status: string, synced_at: string|null, data_origin: string}}";

    private const PROFILE_RESPONSE = "array{status: 'success', message: string, user_info: array<string, mixed>, profile: array<string, mixed>, meta: array{timestamp: string, campus_session_valid: true}}";

    private const CLASSES_RESPONSE = "array{status: 'success', message: string, user_info: array<string, mixed>, classes: list<array<string, mixed>>, meta: array{timestamp: string, campus_session_valid: true}}";

    private const CLASS_DETAIL_RESPONSE = "array{status: 'success', message: string, class_detail: array<string, mixed>, meta: array{timestamp: string, campus_session_valid: true, status: string, synced_at: string|null, data_origin: string, id: string, parameters: array<string, mixed>}}";

    private const MEETING_DETAIL_RESPONSE = "array{status: 'success', message: string, class_detail: array<string, mixed>, meta: array{timestamp: string, campus_session_valid: true, status: string, synced_at: string|null, data_origin: string, meeting_id: string, courses_id: string, notebook_limit_reached?: true}}";

    private const CLASSES_WITH_SEMESTER_RESPONSE = "array{status: 'success', message: string, user_info: array<string, mixed>, classes: list<array<string, mixed>>, semester_info: array<string, mixed>, meta: array{timestamp: string, campus_session_valid: true, data_source: string}}";

    private const MERGED_CLASSES_RESPONSE = "array{status: 'success', message: string, user_info: array<string, mixed>, classes: list<array<string, mixed>>, semester_info: array<string, mixed>, data_sources: array<string, bool>, meta: array{timestamp: string, campus_session_valid: true, data_source: string}}";

    private const PAYMENT_HISTORY_RESPONSE = "array{status: 'success', message: string, data: array{student_info: array<string, string>, payment_history: list<array<string, mixed>>, summary: array{total_records: int, total_paid_amount: int, currency: 'IDR', latest_payment_label: string|null, history_available: bool, portal_total_amount: int|null, portal_total_amount_label: string|null, totals_match: bool|null, latest_payment: array<string, mixed>|null, by_study_year: list<array<string, mixed>>}, outstanding_bills: list<array<string, mixed>>, outstanding_summary: array{status: 'clear'|'outstanding'|'unknown', has_outstanding: bool|null, total_amount: int|null, total_amount_label: string|null, count: int, rows_sum_matches_total: bool|null}, outstanding_total: int|null, outstanding_total_label: string|null, has_outstanding: bool|null, payment_stages: list<array<string, mixed>>, payment_stage_summary: array<string, mixed>, extracted_at: string}, meta: array{timestamp: string, campus_session_valid: true}}";

    private const FLOW_RESPONSE = "array{status: 'success', message: string, data: array{course_detail: array<string, mixed>, meeting_detail: array<string, mixed>|null, links: array<string, string>}, meta: array{timestamp: string, campus_session_valid: true}}";

    public function __construct(
        private readonly DashboardMetricsBuilder $dashboardMetrics,
        private readonly PortalFinanceStatusResolver $financeStatus,
        private readonly PortalClassCatalog $classCatalog,
        private readonly ClassesExtractor $classesExtractor,
        private readonly PortalFetchPolicy $fetchPolicy,
        private readonly PortalOfflineAccess $offlineAccess
    ) {}

    /**
     * Ambil data dashboard kampus terbaru.
     *
     * Menggabungkan ringkasan profil, kelas aktif, dan aktivitas terbaru dari DASHBOARD SIPANDAI.
     * `data.portal_context` (periode akademik, semester, panduan, kuesioner) dan `data.metrics`
     * (IPK, SKS lulus, SKS semester ini, tagihan, SKKM) hanya dibaca dari snapshot: dashboard tidak
     * pernah menunggu portal untuk data ini. Snapshot yang belum ada dibangun di background dan
     * namanya muncul di `data.pending_sources`; nilai yang belum terbaca bernilai `null`, bukan 0.
     *
     * Jadwal hari ini cukup dibangun dari beberapa halaman daftar. Absensi tiap kelas
     * (`data.classes_summary.today_classes.*.attendance`) butuh halaman kelas dan pertemuannya, jadi
     * dibaca dari snapshot tersendiri yang diperbarui di background setelah jadwal: selama snapshot itu
     * dibangun, `attendance.state` bernilai `checking` (`unavailable` bila gagal dibaca), dan
     * `data.classes_summary.attendance_sync` memuat statusnya. `fresh=1` juga memperbarui absensi di background.
     */
    #[Response(description: 'Ringkasan dashboard portal berhasil diambil.', type: self::DASHBOARD_RESPONSE)]
    #[Response(401, description: 'Endpoint ini membutuhkan sesi kampus yang aktif.', type: CampusApiController::SCRAMBLE_SESSION_ERROR_RESPONSE)]
    #[Response(500, description: 'Data dashboard gagal diambil.', type: CampusApiController::SCRAMBLE_STATUS_ERROR_RESPONSE)]
    public function dashboard(Request $request, DashboardTodayClassesBuilder $todayClasses, DashboardTodayAttendance $todayAttendance): JsonResponse
    {
        return $this->withCampusSession('Dashboard extraction failed', 'Gagal memuat dashboard', function (string $sessionToken) use ($request, $todayClasses, $todayAttendance): JsonResponse {
            $dashboard = $this->readDataset($request, $sessionToken, 'dashboard');

            if (is_array($dashboard['data']['classes_summary'] ?? null)) {
                $now = CarbonImmutable::now(config('app.timezone'));

                // The snapshot is served for hours; which class is in session is decided now, not when it was built.
                $classesSummary = $todayClasses->refreshActiveState($dashboard['data']['classes_summary'], $now);

                // Attendance is its own snapshot (class and meeting pages), queued after the schedule's re-sync.
                $dashboard['data']['classes_summary'] = $todayAttendance->attach(
                    $sessionToken,
                    $classesSummary,
                    $now,
                    $request->boolean('fresh'),
                    (string) session()->getId()
                );
            }

            if (! empty($dashboard['data']['user_info'])) {
                $currentUserInfo = (array) session('campus_user_info', []);
                if (empty($currentUserInfo['name']) && empty($currentUserInfo['nama'])) {
                    session(['campus_user_info' => array_merge($currentUserInfo, $dashboard['data']['user_info'])]);
                }
            }

            // Secondary widgets never wait on the portal; missing snapshots are built in one background job.
            $secondary = $this->peekDatasets($sessionToken, ['portal_context', ...DashboardMetricsBuilder::SOURCES]);
            $metrics = $this->dashboardMetrics->fromSnapshots($secondary);
            $pendingSources = array_keys(array_filter(
                $secondary,
                static fn (array $read): bool => $read['data'] === null && ($read['meta']['status'] ?? null) === 'pending'
            ));

            return $this->statusSuccess('Dashboard data retrieved successfully', [
                'data' => [
                    ...$dashboard['data'],
                    'portal_context' => $secondary['portal_context']['data'],
                    'metrics' => $metrics,
                    'pending_sources' => $pendingSources,
                ],
            ], meta: $dashboard['meta'], campusSessionValid: true);
        });
    }

    /**
     * Ambil konteks portal: periode akademik, semester ke-N, panduan PDF, dan kuesioner VMTS.
     *
     * Sumber periode adalah judul dashboard portal; semester ke-N dibaca dari jadwal kuliah atau
     * SIPANDAI (`semester_is_estimate` bernilai true bila hanya bisa diperkirakan dari angkatan).
     */
    #[Response(description: 'Konteks portal berhasil diambil.', type: self::PORTAL_CONTEXT_RESPONSE)]
    #[Response(401, description: 'Endpoint ini membutuhkan sesi kampus yang aktif.', type: CampusApiController::SCRAMBLE_SESSION_ERROR_RESPONSE)]
    #[Response(500, description: 'Konteks portal gagal diambil.', type: CampusApiController::SCRAMBLE_STATUS_ERROR_RESPONSE)]
    public function portalContext(Request $request): JsonResponse
    {
        return $this->withCampusSession('Portal context extraction failed', 'Gagal memuat konteks portal', function (string $sessionToken) use ($request): JsonResponse {
            $portalContext = $this->readDataset($request, $sessionToken, 'portal_context');

            return $this->statusSuccess('Portal context retrieved successfully', [
                'data' => $portalContext['data'],
            ], meta: $portalContext['meta'], campusSessionValid: true);
        });
    }

    /**
     * Ambil data profil mahasiswa langsung dari portal.
     *
     * Menyediakan data pribadi, kontak, pendidikan, dan metadata waktu ekstraksi.
     */
    #[Response(description: 'Profil mahasiswa berhasil diambil.', type: self::PROFILE_RESPONSE)]
    #[Response(401, description: 'Endpoint ini membutuhkan sesi kampus yang aktif.', type: CampusApiController::SCRAMBLE_SESSION_ERROR_RESPONSE)]
    #[Response(500, description: 'Profil mahasiswa gagal diambil.', type: CampusApiController::SCRAMBLE_STATUS_ERROR_RESPONSE)]
    public function profile(Request $request): JsonResponse
    {
        return $this->withCampusSession('Profile extraction failed', 'Gagal memuat profil', function (string $sessionToken) use ($request): JsonResponse {
            $profile = $this->readDataset($request, $sessionToken, 'profile');

            return $this->statusSuccess('Profile data retrieved successfully', [
                'user_info' => $profile['data']['user_info'] ?? [],
                'profile' => $profile['data'],
            ], meta: $profile['meta'], campusSessionValid: true);
        });
    }

    /**
     * Ambil daftar kelas dari LMS/SIPANDAI.
     */
    #[Response(description: 'Daftar kelas LMS/SIPANDAI berhasil diambil.', type: self::CLASSES_RESPONSE)]
    #[Response(401, description: 'Endpoint ini membutuhkan sesi kampus yang aktif.', type: CampusApiController::SCRAMBLE_SESSION_ERROR_RESPONSE)]
    #[Response(500, description: 'Daftar kelas gagal diambil.', type: CampusApiController::SCRAMBLE_STATUS_ERROR_RESPONSE)]
    public function classes(Request $request): JsonResponse
    {
        return $this->withCampusSession('Classes extraction failed', 'Gagal memuat daftar kelas', function (string $sessionToken) use ($request): JsonResponse {
            $classes = $this->readDataset($request, $sessionToken, 'lms_classes');

            return $this->statusSuccess('Classes data retrieved successfully', [
                'user_info' => $classes['data']['student_info'] ?? [],
                'classes' => $classes['data']['classes'] ?? [],
            ], meta: $classes['meta'], campusSessionValid: true);
        });
    }

    /**
     * Ambil histori pembayaran keuangan mahasiswa dari portal mahasiswa.
     */
    #[Response(description: 'Histori pembayaran keuangan berhasil diambil.', type: self::PAYMENT_HISTORY_RESPONSE)]
    #[Response(401, description: 'Endpoint ini membutuhkan sesi kampus yang aktif.', type: CampusApiController::SCRAMBLE_SESSION_ERROR_RESPONSE)]
    #[Response(500, description: 'Histori pembayaran gagal diambil.', type: CampusApiController::SCRAMBLE_STATUS_ERROR_RESPONSE)]
    public function paymentHistory(Request $request): JsonResponse
    {
        return $this->withCampusSession('Payment history extraction failed', 'Gagal memuat riwayat pembayaran', function (string $sessionToken) use ($request): JsonResponse {
            $payments = $this->readDataset($request, $sessionToken, 'payments');

            return $this->statusSuccess('Payment history retrieved successfully', [
                'data' => $payments['data'],
            ], meta: $payments['meta'], campusSessionValid: true);
        });
    }

    /**
     * Ringkasan keuangan untuk keputusan: tagihan aktif, status tahap UKT, dan tenggat bayar.
     *
     * Dibaca dari histori keuangan yang sama dengan `/api/finance/payments`. Bila tabel tagihan tidak
     * terbaca, `outstanding_summary.status` bernilai `unknown` dan nominalnya `null` (tidak pernah "Rp 0").
     * `active_deadline` hanya terisi bila tanggal tenggat di teks berjalan portal belum lewat dan tahap
     * yang disebut belum tercatat lunas.
     */
    #[Response(description: 'Ringkasan keuangan berhasil diambil.', type: self::FINANCE_SUMMARY_RESPONSE)]
    #[Response(401, description: 'Endpoint ini membutuhkan sesi kampus yang aktif.', type: CampusApiController::SCRAMBLE_SESSION_ERROR_RESPONSE)]
    #[Response(500, description: 'Ringkasan keuangan gagal diambil.', type: CampusApiController::SCRAMBLE_STATUS_ERROR_RESPONSE)]
    public function financeSummary(Request $request): JsonResponse
    {
        return $this->withCampusSession('Finance summary extraction failed', 'Gagal memuat ringkasan keuangan', function (string $sessionToken) use ($request): JsonResponse {
            $payments = $this->readDataset($request, $sessionToken, 'payments');
            ['portal_context' => $portalContext, 'announcements' => $announcements] = $this->peekDatasets($sessionToken, ['portal_context', 'announcements']);

            return $this->statusSuccess('Finance summary retrieved successfully', [
                'data' => $this->financeStatus->summarize($payments['data'], [
                    $portalContext['data']['notice'] ?? null,
                    $announcements['data']['notice'] ?? null,
                ]),
            ], meta: [
                ...$payments['meta'],
                'notice_sources' => [
                    'portal_context' => (string) ($portalContext['meta']['status'] ?? 'missing'),
                    'announcements' => (string) ($announcements['meta']['status'] ?? 'missing'),
                ],
            ], campusSessionValid: true);
        });
    }

    /**
     * Ambil data SKKM (SIREKA): poin valid, menunggu, ditolak, status kelulusan, dan daftar kegiatan.
     *
     * `found=false` berarti halaman SIREKA tidak bisa dibaca, bukan 0 poin. Link aksi portal tidak pernah
     * dikirim; `action_types` hanya memberi tahu aksi yang tersedia.
     */
    #[Response(description: 'Data SKKM berhasil diambil.', type: self::SKKM_RESPONSE)]
    #[Response(401, description: 'Endpoint ini membutuhkan sesi kampus yang aktif.', type: CampusApiController::SCRAMBLE_SESSION_ERROR_RESPONSE)]
    #[Response(500, description: 'Data SKKM gagal diambil.', type: CampusApiController::SCRAMBLE_STATUS_ERROR_RESPONSE)]
    public function skkm(Request $request): JsonResponse
    {
        return $this->withCampusSession('SKKM extraction failed', 'Gagal memuat data SKKM', function (string $sessionToken) use ($request): JsonResponse {
            $skkm = $this->readDataset($request, $sessionToken, 'skkm');

            return $this->statusSuccess('SKKM data retrieved successfully', [
                'data' => $this->withoutWriteEffectLinks($skkm['data']),
            ], meta: $skkm['meta'], campusSessionValid: true);
        });
    }

    /**
     * Ambil detail kelas/SIPANDAI berdasarkan parameter eksplisit.
     *
     * Param `id`, `dosen_id`, dan `course_id` mengikuti struktur URL `act=download`. Tanpa `dosen_id`
     * dan `course_id`, keduanya dicari dari daftar kelas tersimpan selama daftar itu masih segar; daftar
     * yang sudah usang dicocokkan dulu dengan daftar kelas portal, dan baru dipakai bila portal gagal
     * atau sesi sedang offline. `fresh=1` selalu membaca daftar kelas dari portal.
     */
    #[PathParameter('id', 'Identifier `id` dari link kelas portal SIPANDAI.', type: 'integer', example: 2265)]
    #[Response(description: 'Detail kelas berhasil diambil.', type: self::CLASS_DETAIL_RESPONSE)]
    #[Response(401, description: 'Endpoint ini membutuhkan sesi kampus yang aktif.', type: CampusApiController::SCRAMBLE_SESSION_ERROR_RESPONSE)]
    #[Response(404, description: 'Identifier `id` tidak ditemukan pada daftar kelas aktif.', type: CampusApiController::SCRAMBLE_STATUS_ERROR_RESPONSE)]
    #[Response(422, description: 'Parameter detail kelas tidak valid.', type: CampusApiController::SCRAMBLE_VALIDATION_ERROR_RESPONSE)]
    #[Response(500, description: 'Detail kelas gagal diambil.', type: CampusApiController::SCRAMBLE_STATUS_ERROR_RESPONSE)]
    public function classDetail(ClassDetailRequest $request): JsonResponse
    {
        return $this->withCampusSession('Class detail extraction failed', 'Gagal memuat detail kelas', function (string $sessionToken) use ($request): JsonResponse {
            $validated = $request->validated();
            $resolvedParameters = $validated;

            if (empty($validated['dosen_id']) || empty($validated['course_id'])) {
                $resolvedParameters = $this->findClassParameters($sessionToken, (string) $validated['id'], $request->boolean('fresh')) ?? [];

                if ($resolvedParameters === []) {
                    return $this->statusError('Kelas tidak ditemukan di daftar kelas kamu.', 404, campusSessionValid: true);
                }
            }

            $classDetail = $this->readDataset($request, $sessionToken, 'class_detail', $resolvedParameters);

            if (($classDetail['data']['found'] ?? true) === false) {
                return $this->statusError('Kelas tidak ditemukan. Periksa kembali link yang kamu buka.', 404, campusSessionValid: true);
            }

            return $this->statusSuccess('Class detail retrieved successfully', [
                'class_detail' => $this->withoutWriteEffectLinks($classDetail['data']),
            ], meta: [
                ...$classDetail['meta'],
                'id' => (string) ($resolvedParameters['id'] ?? $validated['id']),
                'parameters' => $resolvedParameters,
            ], campusSessionValid: true);
        }, $request->query());
    }

    /**
     * Ambil detail pertemuan SIPANDAI berdasarkan query `meeting_id` + `courses_id`.
     *
     * `class_detail.class_url`, bila diketahui, mengarah ke halaman kelas pertemuan ini (dibaca dari breadcrumb portal).
     * `meta.notebook_limit_reached` bernilai `true` bila pertemuan ini tidak masuk notebook mata
     * kuliahnya karena notebook itu sudah penuh; tanpa kondisi itu, kunci ini tidak dikirim.
     */
    #[PathParameter('meeting_id', 'Identifier `meeting_id` dari link diskusi atau materi SIPANDAI.', type: 'integer', example: 445)]
    #[Response(description: 'Detail pertemuan berhasil diambil.', type: self::MEETING_DETAIL_RESPONSE)]
    #[Response(401, description: 'Endpoint ini membutuhkan sesi kampus yang aktif.', type: CampusApiController::SCRAMBLE_SESSION_ERROR_RESPONSE)]
    #[Response(422, description: 'Parameter detail pertemuan tidak valid.', type: CampusApiController::SCRAMBLE_VALIDATION_ERROR_RESPONSE)]
    #[Response(500, description: 'Detail pertemuan gagal diambil.', type: CampusApiController::SCRAMBLE_STATUS_ERROR_RESPONSE)]
    public function meetingDetail(SipandaiMeetingDetailRequest $request): JsonResponse
    {
        return $this->withCampusSession('Meeting detail extraction failed', 'Gagal memuat detail pertemuan', function (string $sessionToken) use ($request): JsonResponse {
            $meetingId = $request->meetingId();
            $coursesId = $request->coursesId();

            $meetingDetail = $this->readDataset($request, $sessionToken, 'meeting_detail', [
                'meeting_id' => $meetingId,
                'courses_id' => $coursesId,
            ]);

            if (($meetingDetail['data']['found'] ?? true) === false) {
                return $this->statusError('Pertemuan tidak ditemukan. Periksa kembali link yang kamu buka.', 404, campusSessionValid: true);
            }

            return $this->statusSuccess('Meeting detail retrieved successfully', [
                'class_detail' => $this->withoutWriteEffectLinks($meetingDetail['data']),
            ], meta: [
                ...$meetingDetail['meta'],
                'meeting_id' => $meetingId,
                'courses_id' => $coursesId,
            ], campusSessionValid: true);
        }, $request->query());
    }

    /**
     * Portal pages of the flow. A link with a write effect, such as the
     * act=jobdel assignment delete, is never built (K13): deleting goes
     * through `DELETE /api/assignment`.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, string>
     */
    private function buildFlowLinks(array $params): array
    {
        $links = [];

        try {
            if (! empty($params['dosen_id'])) {
                $links['course_materials'] = PortalEndpointRegistry::url('course_materials', [
                    'id' => $params['id'],
                    'dosen_id' => $params['dosen_id'],
                    'course_id' => $params['course_id'],
                ]);
            }
        } catch (\Throwable $e) {
            // Optional link
        }

        try {
            $links['discussion'] = PortalEndpointRegistry::url('discussion', [
                'id' => $params['meeting_id'] ?? $params['id'],
                'courses_id' => $params['courses_id'] ?? $params['course_id'],
            ]);
        } catch (\Throwable $e) {
            //
        }

        try {
            $links['assignment_form'] = PortalEndpointRegistry::url('assignment_form', [
                'courses_id' => $params['courses_id'] ?? $params['course_id'],
                'id' => $params['id'],
                'pertemuan' => $params['pertemuan'],
            ]);
        } catch (\Throwable $e) {
            //
        }

        try {
            $links['qr_attendance'] = PortalEndpointRegistry::url('qr_attendance_page', [
                'course_id' => $params['course_id'],
                'id' => $params['meeting_id'] ?? $params['id'],
            ]);
        } catch (\Throwable $e) {
            //
        }

        return $links;
    }

    /**
     * Resolve the upstream `dosen_id`/`course_id` for a class `id` from the class list.
     *
     * A stored class list that is still fresh (within its `fresh_for`)
     * answers without the portal. A stale one (or the archived version
     * restored in its place) may map the id to parameters the portal has
     * since changed, so while the session is online the live catalog
     * answers first and the stored list only when the portal fails, times
     * out or does not list the class; an offline session answers from the
     * stored list at once. A forced read (`fresh`) bypasses the stored list
     * and the catalog cache: the class list is re-read from the portal, and
     * the stored list only answers when the portal cannot. An expired or
     * offline session is rethrown so the student is sent to log in or told
     * the data is not archived, instead of hearing the class is not theirs.
     *
     * @return array<string, mixed>|null
     */
    private function findClassParameters(string $sessionToken, string $id, bool $fresh = false): ?array
    {
        $stored = $fresh ? null : $this->storedClassParameters($sessionToken, $id);

        if ($stored !== null && ($stored['fresh'] || $this->offlineAccess->isOfflineSession($sessionToken))) {
            return $stored['params'];
        }

        try {
            $live = $this->fetchPolicy->runFresh(
                fn (): ?array => $this->classCatalog->findById($sessionToken, $id, $fresh),
                $fresh
            );
        } catch (\Throwable $throwable) {
            if (PortalException::indicatesExpiredSession($throwable)) {
                throw $throwable;
            }

            $stored ??= $fresh ? $this->storedClassParameters($sessionToken, $id) : null;

            if ($stored !== null) {
                return $stored['params'];
            }

            if ($throwable instanceof PortalOfflineException) {
                throw $throwable;
            }

            Log::error('Failed to get class parameters from classes list', [
                'error' => $throwable->getMessage(),
                'id' => $id,
            ]);

            return null;
        }

        if ($live !== null) {
            return $live;
        }

        $stored ??= $fresh ? $this->storedClassParameters($sessionToken, $id) : null;

        return $stored['params'] ?? null;
    }

    /**
     * Class link parameters for `id` from the stored class lists, without
     * contacting the portal or queueing a sync, and whether the class list
     * that holds them is still fresh. A list that holds them fresh wins
     * over one that holds them stale.
     *
     * @return array{params: array<string, mixed>, fresh: bool}|null
     */
    private function storedClassParameters(string $sessionToken, string $id): ?array
    {
        $repository = app(PortalDatasetRepository::class);
        $stored = $repository->peekMany($sessionToken, ['lms_classes', 'classes'], (string) session()->getId(), refreshable: []);
        $staleMatch = null;

        foreach ($stored as $dataset => $read) {
            $params = $this->classParametersIn((array) ($read['data']['classes'] ?? []), $id);

            if ($params === null) {
                continue;
            }

            if ($repository->hasFreshSnapshot($sessionToken, $dataset)) {
                return ['params' => $params, 'fresh' => true];
            }

            $staleMatch ??= ['params' => $params, 'fresh' => false];
        }

        return $staleMatch;
    }

    /**
     * The complete class link parameters of class `id` in a class list.
     *
     * @param  array<array-key, mixed>  $classes
     * @return array<string, mixed>|null
     */
    private function classParametersIn(array $classes, string $id): ?array
    {
        foreach ($classes as $class) {
            $params = is_array($class) ? ($class['join_link']['params'] ?? null) : null;

            if (is_array($params) && (string) ($params['id'] ?? '') === $id && ! empty($params['dosen_id']) && ! empty($params['course_id'])) {
                return $params;
            }
        }

        return null;
    }

    /**
     * Ambil jadwal perkuliahan dari portal mahasiswa.
     */
    #[Response(description: 'Jadwal perkuliahan mahasiswa berhasil diambil.', type: self::CLASSES_WITH_SEMESTER_RESPONSE)]
    #[Response(401, description: 'Endpoint ini membutuhkan sesi kampus yang aktif.', type: CampusApiController::SCRAMBLE_SESSION_ERROR_RESPONSE)]
    #[Response(500, description: 'Jadwal mahasiswa gagal diambil.', type: CampusApiController::SCRAMBLE_STATUS_ERROR_RESPONSE)]
    public function studentCourse(Request $request): JsonResponse
    {
        return $this->withCampusSession('Student course extraction failed', 'Gagal memuat jadwal kuliah', function (string $sessionToken) use ($request): JsonResponse {
            $schedule = $this->readDataset($request, $sessionToken, 'schedule');

            return $this->statusSuccess('Student course data retrieved successfully', [
                'user_info' => $schedule['data']['student_info'] ?? [],
                'classes' => $schedule['data']['classes'] ?? [],
                'semester_info' => $schedule['data']['semester_info'] ?? [],
            ], meta: [
                ...$schedule['meta'],
                'data_source' => 'student_course',
            ], campusSessionValid: true);
        });
    }

    /**
     * Ambil daftar kelas utama API.
     *
     * Data LMS/SIPANDAI dipakai untuk informasi materi dan tautan aksi, sedangkan
     * data jadwal mahasiswa melengkapi ruang kelas dan jadwal rinci.
     */
    #[Response(description: 'Daftar kelas utama API berhasil diambil.', type: self::MERGED_CLASSES_RESPONSE)]
    #[Response(401, description: 'Endpoint ini membutuhkan sesi kampus yang aktif.', type: CampusApiController::SCRAMBLE_SESSION_ERROR_RESPONSE)]
    #[Response(500, description: 'Daftar kelas gabungan gagal diambil.', type: CampusApiController::SCRAMBLE_STATUS_ERROR_RESPONSE)]
    public function mergedClasses(Request $request): JsonResponse
    {
        return $this->withCampusSession('Merged classes extraction failed', 'Gagal memuat daftar kelas', function (string $sessionToken) use ($request): JsonResponse {
            $classes = $this->readDataset($request, $sessionToken, 'classes');

            return $this->statusSuccess('Merged classes data retrieved successfully', [
                'user_info' => $classes['data']['student_info'] ?? [],
                'classes' => $classes['data']['classes'] ?? [],
                'semester_info' => $classes['data']['semester_info'] ?? [],
                'data_sources' => $classes['data']['data_sources'] ?? [],
            ], meta: [
                ...$classes['meta'],
                'data_source' => 'merged',
            ], campusSessionValid: true);
        });
    }

    /**
     * Alur kerja LMS/SIPANDAI lengkap: detail kelas, detail pertemuan (bila
     * `meeting_id` diberikan), dan tautan aksinya dalam satu respons. Halaman
     * portal yang dibaca ada di katalog endpoint (`php artisan portal:endpoints`).
     */
    #[Response(description: 'Alur kerja LMS/SIPANDAI berhasil dirangkai.', type: self::FLOW_RESPONSE)]
    #[Response(401, description: 'Endpoint ini membutuhkan sesi kampus yang aktif.', type: CampusApiController::SCRAMBLE_SESSION_ERROR_RESPONSE)]
    #[Response(422, description: 'Parameter alur SIPANDAI tidak valid.', type: CampusApiController::SCRAMBLE_VALIDATION_ERROR_RESPONSE)]
    #[Response(500, description: 'Alur data SIPANDAI gagal diproses.', type: CampusApiController::SCRAMBLE_STATUS_ERROR_RESPONSE)]
    public function sipandaiFlow(SipandaiFlowRequest $request): JsonResponse
    {
        $params = $request->validated();

        return $this->withCampusSession('SIPANDAI flow extraction failed', 'Gagal menjalankan alur SIPANDAI', fn (string $sessionToken): JsonResponse => $this->fetchPolicy->runFresh(function () use ($sessionToken, $params): JsonResponse {
            $courseDetailParams = [
                'id' => $params['id'],
                'course_id' => $params['course_id'],
            ];

            if (! empty($params['dosen_id'])) {
                $courseDetailParams['dosen_id'] = $params['dosen_id'];
            }

            $courseDetail = $this->classesExtractor->extractClassDetail($sessionToken, $courseDetailParams);

            $meetingDetail = null;
            if (! empty($params['meeting_id'])) {
                $meetingDetail = $this->classesExtractor->extractClassDetail($sessionToken, [
                    'meeting_id' => $params['meeting_id'],
                    'courses_id' => $params['courses_id'] ?? $params['course_id'],
                ]);
            }

            $links = $this->buildFlowLinks($params);

            return $this->statusSuccess('SIPANDAI flow data retrieved successfully', [
                'data' => [
                    'course_detail' => $this->withoutWriteEffectLinks($courseDetail),
                    'meeting_detail' => $meetingDetail === null ? null : $this->withoutWriteEffectLinks($meetingDetail),
                    'links' => $links,
                ],
            ], campusSessionValid: true);
        }, $request->boolean('fresh')), $params);
    }

    public function refresh(PortalRefreshRequest $request): JsonResponse
    {
        $sessionToken = $this->sessionToken();

        if (! $sessionToken) {
            return $this->missingSessionStatusResponse();
        }

        RefreshPortalDataJob::dispatch(
            (string) session()->getId(),
            $sessionToken,
            $request->domains()
        );

        return $this->statusSuccess('Refresh data berjalan di background.', [
            'data' => [
                'domains' => $request->domains(),
                'status' => 'queued',
            ],
        ], campusSessionValid: true, statusCode: 202);
    }
}
