<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Requests\PortalKrsDetailRequest;
use App\Http\Requests\PortalTranscriptRequest;
use App\Services\AcademicProgressCalculator;
use App\Services\PortalDatasetRepository;
use App\Services\PortalKrsResolver;
use App\Services\PortalSnapshotStore;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Akademik', 'KRS aktif, perwalian/DPA, detail KRS per semester, KHS, dan transkrip beserta progres studi.', 25)]
class PortalAcademicController extends CampusApiController
{
    private const SNAPSHOT_META = 'meta: array{timestamp: string, campus_session_valid: true, status: string, synced_at: string|null, data_origin: string, requested_fresh?: bool}';

    private const DPA = 'array{name: string|null, nidn: string|null, phone: string|null, whatsapp: string|null, email: string|null}';

    private const KRS_PERIOD = "array{kind: 'her_registrasi'|'perwalian_perbaikan'|'krs', label: string, start: string, end: string, source: 'credit'|'announcement', source_ref: string|null, announcement_id: string|null, is_open: bool, is_upcoming: bool, is_past: bool, days_left: int|null, days_until_start: int|null}";

    private const KRS_STATUS = "array{code: 'pending_dpa'|'validated'|'not_submitted'|'unknown', label: string, raw_text: string|null}";

    private const KRS_COURSE = 'array{code: string, name: string|null, sks: int|null, semester: int|null}';

    private const KRS_TOTALS = "array{courses: int, sks: int, source: 'portal'|'computed', consistent: bool}";

    private const KHS_SEMESTER = "array{label: string, semester: int|string|null, is_regular: bool, status: 'graded'|'partial'|'pending'|'empty', columns: list<string>, courses: list<array{code: string, name: string, sks: int, components: array<string, float|null>, score: float|null, letter: string|null, weight: float|null, quality_points: float|null, is_graded: bool}>, course_count: int, graded_count: int, total_sks: int|null, total_quality_points: float|null, ips: float|null}";

    private const TRANSCRIPT_ROW = 'array{code: string, name: string, sks: int, letter: string, weight: float, quality_points: float, semester: int, attempt_count: int, attempts: list<array{semester: int, letter: string, weight: float}>}';

    public function __construct(
        private readonly PortalDatasetRepository $datasets,
        private readonly PortalKrsResolver $krsResolver,
        private readonly AcademicProgressCalculator $calculator,
        private readonly PortalSnapshotStore $snapshots
    ) {}

    /**
     * KRS semester berjalan (Reg & KRS Online): periode, status validasi DPA, MK rencana, dan total SKS.
     *
     * `insights` menyimpulkan periode yang relevan dengan status KRS saat ini (`active_period`, sisa hari),
     * tracker validasi, dan kontak DPA kanonik dari Perwalian. Periode her-registrasi dari pengumuman ikut
     * digabung bila snapshot pengumuman sudah ada.
     */
    #[Response(description: 'KRS semester berjalan berhasil diambil.', type: "array{status: 'success', message: string, data: array{found: bool, academic_period: array{label: string, academic_year: string, term: 'ganjil'|'genap'|null, source: 'credit'}|null, student: array{faculty: string|null, program: string|null, academic_year: string|null, nim: string|null, name: string|null}, period: array{start: string, end: string}|null, periods: list<array{kind: string, start: string, end: string, label: string, source: 'credit', source_ref: string}>, notice: array{text: string, dates: list<string>, is_future: bool, kind: string}|null, notice_text: string|null, status: ".self::KRS_STATUS.', dpa: '.self::DPA.'|null, courses: list<'.self::KRS_COURSE.'>, totals: '.self::KRS_TOTALS.', print_available: bool, registration_form_detected: bool, extracted_at: string, insights: array{status: '.self::KRS_STATUS.', periods: list<'.self::KRS_PERIOD.'>, active_period: '.self::KRS_PERIOD.'|null, next_period: '.self::KRS_PERIOD.'|null, is_period_open: bool, dpa: '.self::DPA."|null, dpa_source: 'advising'|'credit'|null, tracker: list<array{key: 'period_open'|'submitted'|'validation'|'validated', label: string, state: 'done'|'current'|'todo'|'unknown'}>, latest_krs_id: int|null, study_load: array{courses: int, sks: int}|null}}, ".self::SNAPSHOT_META.'}')]
    #[Response(401, description: 'Endpoint ini membutuhkan sesi kampus yang aktif.', type: CampusApiController::SCRAMBLE_SESSION_ERROR_RESPONSE)]
    #[Response(500, description: 'KRS gagal diambil.', type: CampusApiController::SCRAMBLE_STATUS_ERROR_RESPONSE)]
    public function krs(Request $request): JsonResponse
    {
        return $this->withCampusSession('KRS extraction failed', 'Gagal memuat KRS', function (string $sessionToken) use ($request): JsonResponse {
            $krs = $this->readDataset($request, $sessionToken, 'krs');
            ['advising' => $advising, 'announcements' => $announcements] = $this->peekDatasets($sessionToken, ['advising', 'announcements']);

            $data = $krs['data'];
            // The detected form's action could submit a KRS: it stays server-side (K13).
            unset($data['registration_form']);
            $data['insights'] = $this->krsResolver->insights($krs['data'], $advising['data'], $announcements['data']);

            return $this->statusSuccess('KRS retrieved successfully', [
                'data' => $this->withoutWriteEffectLinks($data),
            ], meta: [
                ...$krs['meta'],
                'insight_sources' => [
                    'advising' => (string) ($advising['meta']['status'] ?? 'missing'),
                    'announcements' => (string) ($announcements['meta']['status'] ?? 'missing'),
                ],
            ], campusSessionValid: true);
        });
    }

    /**
     * Perwalian: kontak DPA (sumber kanonik) dan histori KRS per semester.
     *
     * `latest_krs_id` adalah KRS dengan semester numerik tertinggi (bukan tanggal terbaru). Semester `T`
     * diberi label non-reguler dan tidak dihitung sebagai beban studi.
     */
    #[Response(description: 'Data perwalian berhasil diambil.', type: "array{status: 'success', message: string, data: array{found: bool, student: array{photo_path: string|null, program: string|null, cohort: string|null, nim: string|null, name: string|null, status: string|null}, dpa: ".self::DPA.'|null, krs_history: list<array{krs_id: int, academic_year: string|null, date: string|null, semester: int|string|null, semester_is_regular: bool, semester_label: string|null}>, latest_krs_id: int|null, latest_semester_number: int|null, krs_add_action_detected: bool, extracted_at: string}, '.self::SNAPSHOT_META.'}')]
    #[Response(401, description: 'Endpoint ini membutuhkan sesi kampus yang aktif.', type: CampusApiController::SCRAMBLE_SESSION_ERROR_RESPONSE)]
    #[Response(500, description: 'Data perwalian gagal diambil.', type: CampusApiController::SCRAMBLE_STATUS_ERROR_RESPONSE)]
    public function advising(Request $request): JsonResponse
    {
        return $this->withCampusSession('Advising extraction failed', 'Gagal memuat data perwalian', function (string $sessionToken) use ($request): JsonResponse {
            $advising = $this->readDataset($request, $sessionToken, 'advising');

            return $this->statusSuccess('Advising data retrieved successfully', [
                'data' => $this->withoutWriteEffectLinks($advising['data']),
            ], meta: $advising['meta'], campusSessionValid: true);
        });
    }

    /**
     * Detail KRS satu semester: MK, SKS, status Baru/Ulang.
     *
     * Hanya `krs_id` yang ada di histori KRS milik mahasiswa (`GET /api/academic/advising`) yang dilayani;
     * id lain dijawab 404 tanpa request ke portal. `counts_as_study_load=false` untuk KRS semester `T`.
     */
    #[PathParameter('id', 'Identifier `krs_id` dari `krs_history` di `GET /api/academic/advising`.', type: 'integer', example: 90004)]
    #[Response(description: 'Detail KRS berhasil diambil.', type: "array{status: 'success', message: string, data: array{found: true, krs_id: int, header: array{academic_year: string|null, semester: int|string|null, semester_is_regular: bool, semester_label: string|null, date: string|null, nim: string|null, name: string|null}, counts_as_study_load: bool, status: ".self::KRS_STATUS.', courses: list<array{code: string, name: string|null, sks: int|null, semester: int|null, status: string|null, is_retake: bool}>, totals: '.self::KRS_TOTALS.', print_available: bool, krs_id_confirmed: bool|null, extracted_at: string, history: array{krs_id: int, academic_year: string|null, date: string|null, semester: int|string|null, semester_is_regular: bool, semester_label: string|null}, navigation: array{newer_krs_id: int|null, older_krs_id: int|null, is_latest: bool}}, '.self::SNAPSHOT_META.'}')]
    #[Response(401, description: 'Endpoint ini membutuhkan sesi kampus yang aktif.', type: CampusApiController::SCRAMBLE_SESSION_ERROR_RESPONSE)]
    #[Response(404, description: 'KRS tidak ada di histori KRS mahasiswa atau tidak terbaca di portal.', type: CampusApiController::SCRAMBLE_NOT_FOUND_RESPONSE)]
    #[Response(422, description: 'Parameter id KRS tidak valid.', type: CampusApiController::SCRAMBLE_VALIDATION_ERROR_RESPONSE)]
    #[Response(500, description: 'Detail KRS gagal diambil.', type: CampusApiController::SCRAMBLE_STATUS_ERROR_RESPONSE)]
    public function krsDetail(PortalKrsDetailRequest $request): JsonResponse
    {
        return $this->withCampusSession('KRS detail extraction failed', 'Gagal memuat detail KRS', function (string $sessionToken) use ($request): JsonResponse {
            $krsId = $request->krsId();
            // Ownership is decided by the student's own history; `fresh` only applies to the detail page.
            $advising = $this->datasets->read($sessionToken, 'advising', [], false, (string) session()->getId());
            $history = array_values(array_filter(
                is_array($advising['data']['krs_history'] ?? null) ? $advising['data']['krs_history'] : [],
                'is_array'
            ));
            $position = $this->historyPosition($history, $krsId);

            if ($position === null) {
                return $this->statusError('KRS tidak ditemukan di riwayat KRS kamu.', 404, campusSessionValid: true);
            }

            $params = ['id' => $krsId];
            $detail = $this->readDataset($request, $sessionToken, 'krs_detail', $params);

            if (($detail['data']['found'] ?? false) !== true) {
                // An empty page is usually a portal hiccup: do not keep it for hours.
                $this->snapshots->forget($sessionToken, 'krs_detail', $params);

                return $this->statusError('KRS ini tidak terbaca di portal. Coba lagi beberapa saat.', 404, campusSessionValid: true);
            }

            return $this->statusSuccess('KRS detail retrieved successfully', [
                'data' => [
                    ...$this->withoutWriteEffectLinks($detail['data']),
                    'history' => $history[$position],
                    'navigation' => [
                        'newer_krs_id' => $this->historyKrsId($history, $position - 1),
                        'older_krs_id' => $this->historyKrsId($history, $position + 1),
                        'is_latest' => ($advising['data']['latest_krs_id'] ?? null) === $krsId,
                    ],
                ],
            ], meta: [
                ...$detail['meta'],
                'krs_id' => $krsId,
            ], campusSessionValid: true);
        }, ['krs_id' => (string) $request->route('id')]);
    }

    /**
     * Kartu Hasil Studi semua semester, ringkasan IPK resmi vs hitungan MyCIC, dan transkrip nilai terbaik.
     *
     * `krs_validation` diambil dari snapshot KRS supaya UI bisa menjelaskan kenapa nilai semester berjalan
     * belum tampil (nilai baru keluar setelah KRS divalidasi DPA). `sources.is_partial=true` berarti data
     * sebagian dari halaman KRS (tanpa ABSEN/TUGAS). `found=false` berarti portal belum punya KHS sama
     * sekali (misalnya mahasiswa semester pertama): `semesters` kosong dan IPK `null`, bukan error.
     */
    #[Response(description: 'KHS berhasil diambil.', type: "array{status: 'success', message: string, data: array{found: bool, semesters: list<".self::KHS_SEMESTER.'>, summary: array<string, mixed>, transcript: list<'.self::TRANSCRIPT_ROW.">, transcript_print_available: bool, sources: array{khs: 'sipandai_score'|'student_krs_detail', is_partial: bool, score_page: string, krs_detail: string, krs_id: string|null, cross_check: array{compared: int, mismatched: int}|null}, extracted_at: string, krs_validation: ".self::KRS_STATUS.'|null}, '.self::SNAPSHOT_META.'}')]
    #[Response(401, description: 'Endpoint ini membutuhkan sesi kampus yang aktif.', type: CampusApiController::SCRAMBLE_SESSION_ERROR_RESPONSE)]
    #[Response(500, description: 'KHS gagal diambil.', type: CampusApiController::SCRAMBLE_STATUS_ERROR_RESPONSE)]
    public function grades(Request $request): JsonResponse
    {
        return $this->withCampusSession('Grades extraction failed', 'Gagal memuat nilai', function (string $sessionToken) use ($request): JsonResponse {
            $grades = $this->readDataset($request, $sessionToken, 'grades');
            ['krs' => $krs] = $this->peekDatasets($sessionToken, ['krs']);

            return $this->statusSuccess('Grades retrieved successfully', [
                'data' => [
                    ...$this->withoutPrintUrl($grades['data']),
                    'krs_validation' => ($krs['data']['found'] ?? false) === true && is_array($krs['data']['status'] ?? null)
                        ? $krs['data']['status']
                        : null,
                ],
            ], meta: $grades['meta'], campusSessionValid: true);
        });
    }

    /**
     * Transkrip & progres studi: IPK, SKS lulus dari target, sisa SKS, proyeksi, MK D/E, dan simulasi target IPK.
     *
     * Semua angka dihitung dari snapshot KHS tanpa request tambahan ke portal. Kirim `target_ipk` dan
     * `next_sks` untuk mendapat `simulation` (IPS minimal semester depan; `achievable=false` berarti
     * tidak tercapai semester ini).
     */
    #[Response(description: 'Transkrip dan progres studi berhasil diambil.', type: "array{status: 'success', message: string, data: array{summary: array{ipk: float|null, source: 'official'|'computed', sks_passed: int, sks_total: int|null, quality_points_total: float, predicate_estimate: string|null, mismatch: bool, mismatch_fields: list<string>, official: array<string, mixed>|null}, progress: array{target_sks: int, earned_sks: int, remaining_sks: int, progress_percent: float, average_sks_per_semester: float|null, projected_semesters_remaining: int|null, target_sks_source: 'official'|'config', graduation_targets: list<array{predicate: string, target_ipk: float, current_ipk: float|null, additional_sks: int, required_ips: float|null, achievable: bool, already_reached: bool}>}, target_sks: int, remaining_sks: int, current_load: array{sks: int, courses: int}|null, transcript: list<".self::TRANSCRIPT_ROW.'>, ips_series: list<array{semester: int, label: string, ips: float|null, ips_portal: float|null, ips_computed: float|null, ips_mismatch: bool, sks: int, quality_points: float, graded_count: int, course_count: int, is_complete: bool}>, latest_ips: array{semester: int, ips: float, previous_semester: int|null, previous_ips: float|null, delta: float|null}|null, letter_counts: array<string, int>, low_grades: array{d_courses: list<'.self::TRANSCRIPT_ROW.'>, e_courses: list<'.self::TRANSCRIPT_ROW.'>, low_grade_courses: list<'.self::TRANSCRIPT_ROW.'>, repeated_courses: list<'.self::TRANSCRIPT_ROW.'>}, excluded_semesters: list<string>, simulation: array{target_ipk: float, current_ipk: float|null, additional_sks: int, required_ips: float|null, achievable: bool, already_reached: bool}|null, is_partial: bool, transcript_print_available: bool, extracted_at: string|null}, '.self::SNAPSHOT_META.'}')]
    #[Response(401, description: 'Endpoint ini membutuhkan sesi kampus yang aktif.', type: CampusApiController::SCRAMBLE_SESSION_ERROR_RESPONSE)]
    #[Response(422, description: 'Parameter simulasi target IPK tidak valid.', type: CampusApiController::SCRAMBLE_VALIDATION_ERROR_RESPONSE)]
    #[Response(500, description: 'Transkrip gagal diambil.', type: CampusApiController::SCRAMBLE_STATUS_ERROR_RESPONSE)]
    public function transcript(PortalTranscriptRequest $request): JsonResponse
    {
        return $this->withCampusSession('Transcript build failed', 'Gagal memuat transkrip', function (string $sessionToken) use ($request): JsonResponse {
            $grades = $this->readDataset($request, $sessionToken, 'grades');
            ['krs' => $krs] = $this->peekDatasets($sessionToken, ['krs']);

            return $this->statusSuccess('Transcript retrieved successfully', [
                'data' => $this->transcriptPayload($grades['data'], $krs['data'], $request->simulation()),
            ], meta: $grades['meta'], campusSessionValid: true);
        });
    }

    /**
     * @param  array<string, mixed>  $grades
     * @param  array<string, mixed>|null  $krs
     * @param  array{target_ipk: float, next_sks: int}|null  $simulation
     * @return array<string, mixed>
     */
    private function transcriptPayload(array $grades, ?array $krs, ?array $simulation): array
    {
        $summary = is_array($grades['summary'] ?? null) ? $grades['summary'] : [];
        $official = is_array($summary['official'] ?? null) ? $summary['official'] : null;
        $computed = is_array($summary['computed'] ?? null) ? $summary['computed'] : [];
        $progress = is_array($summary['progress'] ?? null) ? $summary['progress'] : [];
        $qualityPoints = (float) ($summary['quality_points_total'] ?? $computed['quality_points_total'] ?? 0);
        $sksTotal = $official['sks_total'] ?? $computed['sks_total'] ?? null;
        $krsFound = ($krs['found'] ?? false) === true && is_array($krs['totals'] ?? null);

        return [
            'summary' => [
                'ipk' => $summary['ipk'] ?? null,
                'source' => $summary['source'] ?? 'computed',
                'sks_passed' => (int) ($summary['sks_passed'] ?? 0),
                'sks_total' => is_int($sksTotal) ? $sksTotal : null,
                'quality_points_total' => $qualityPoints,
                'predicate_estimate' => $summary['predicate_estimate'] ?? null,
                'mismatch' => (bool) ($summary['mismatch'] ?? false),
                'mismatch_fields' => $summary['mismatch_fields'] ?? [],
                'official' => $official,
            ],
            'progress' => $progress,
            'target_sks' => $progress['target_sks'] ?? $this->calculator->defaultTargetSks(),
            'remaining_sks' => $progress['remaining_sks'] ?? null,
            'current_load' => $krsFound ? [
                'sks' => (int) ($krs['totals']['sks'] ?? 0),
                'courses' => (int) ($krs['totals']['courses'] ?? 0),
            ] : null,
            'transcript' => $grades['transcript'] ?? [],
            'ips_series' => $computed['ips_series'] ?? [],
            'latest_ips' => $computed['latest_ips'] ?? null,
            'letter_counts' => ($official['letter_counts'] ?? []) !== [] ? $official['letter_counts'] : ($computed['letter_counts'] ?? []),
            'low_grades' => [
                'd_courses' => $computed['d_courses'] ?? [],
                'e_courses' => $computed['e_courses'] ?? [],
                'low_grade_courses' => $computed['low_grade_courses'] ?? [],
                'repeated_courses' => $computed['repeated_courses'] ?? [],
            ],
            'excluded_semesters' => $computed['excluded_semesters'] ?? [],
            'simulation' => $simulation !== null && is_int($sksTotal)
                ? $this->calculator->simulateTarget($simulation['target_ipk'], $qualityPoints, $sksTotal, $simulation['next_sks'])
                : null,
            'is_partial' => (bool) ($grades['sources']['is_partial'] ?? false),
            'transcript_print_available' => is_string($grades['transcript_print_url'] ?? null),
            'extracted_at' => $grades['extracted_at'] ?? null,
        ];
    }

    /**
     * The official print page needs the campus session, which the browser
     * does not have; only its availability is exposed.
     *
     * @param  array<string, mixed>  $grades
     * @return array<string, mixed>
     */
    private function withoutPrintUrl(array $grades): array
    {
        $grades['transcript_print_available'] = is_string($grades['transcript_print_url'] ?? null);
        unset($grades['transcript_print_url']);

        return $grades;
    }

    /**
     * @param  list<array<string, mixed>>  $history
     */
    private function historyPosition(array $history, int $krsId): ?int
    {
        foreach ($history as $position => $entry) {
            if ((int) ($entry['krs_id'] ?? 0) === $krsId) {
                return $position;
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $history
     */
    private function historyKrsId(array $history, int $position): ?int
    {
        $krsId = $history[$position]['krs_id'] ?? null;

        return is_int($krsId) ? $krsId : null;
    }
}
