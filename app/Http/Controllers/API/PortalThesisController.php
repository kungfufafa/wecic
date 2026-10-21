<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Services\SkripsiReadinessEvaluator;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Skripsi & Pustaka', 'Syarat dan SOP skripsi (SiMASITA), status e-Skripsi, checklist kesiapan, perpustakaan digital, dan repositori skripsi.', 27)]
class PortalThesisController extends CampusApiController
{
    private const SNAPSHOT_META = 'meta: array{timestamp: string, campus_session_valid: true, status: string, synced_at: string|null, data_origin: string, requested_fresh?: bool}';

    private const THRESHOLDS = 'array{min_sks: array{D3: int, S1: int}, min_ipk: float, max_d: int, allow_e: bool}';

    private const THRESHOLD_SOURCES = "array{min_sks_d3: 'portal'|'default', min_sks_s1: 'portal'|'default', min_ipk: 'portal'|'default', max_d: 'portal'|'default', allow_e: 'portal'|'default'}";

    private const STATUS = "array{state: 'not_enrolled'|'active_unparsed'|'unknown', message: string|null, raw_sections: list<array{title: string|null, fields: list<array{label: string, value: string}>, links: list<array{label: string, path: string|null}>, form_fields: list<string>}>, available: bool}";

    private const CHECKLIST_ITEM = "array{key: string, kind: 'auto'|'manual', label: string, status: 'passed'|'failed'|'unknown'|'manual', current: int|float|string|null, target: int|float|string|null, hint: string|null}";

    /**
     * Thesis courses in the KRS; "Manajemen Proyek" alone is not one.
     */
    private const THESIS_COURSE_PATTERN = '/\b(?:skripsi|tugas\s+akhir|proyek\s+akhir)\b/iu';

    public function __construct(private readonly SkripsiReadinessEvaluator $evaluator) {}

    /**
     * Hub skripsi: syarat dan SOP Tugas Akhir dari SiMASITA beserta status e-Skripsi.
     *
     * Ambang (`thresholds`) dibaca dari teks syarat portal; nilai yang tidak tertulis memakai bawaan MyCIC
     * dan ditandai `default` di `threshold_sources`. `krs_hint.awaiting_status=true` berarti KRS semester ini
     * memuat MK skripsi tetapi status e-Skripsi masih `not_enrolled` (menunggu validasi atau sinkron portal).
     * Dokumen SOP dibuka lewat `GET /api/support/download?path=`.
     */
    #[Response(description: 'Data skripsi berhasil diambil.', type: "array{status: 'success', message: string, data: array{found: bool, title: string|null, student: array{faculty: string|null, program: string|null, program_raw: string|null, jenjang: 'D3'|'S1'|null, nim: string|null, name: string|null}, requirements: array{thesis: list<string>, exam: list<string>}, thresholds: ".self::THRESHOLDS.', threshold_sources: '.self::THRESHOLD_SOURCES.", threshold_source: 'portal'|'partial'|'default', documents: list<array{label: string, path: string, filename: string}>, status: ".self::STATUS.', extracted_at: string, krs_hint: array{has_thesis_course: bool|null, course: array{code: string|null, name: string|null}|null, awaiting_status: bool}}, '.self::SNAPSHOT_META.'}')]
    #[Response(401, description: 'Endpoint ini membutuhkan sesi kampus yang aktif.', type: CampusApiController::SCRAMBLE_SESSION_ERROR_RESPONSE)]
    #[Response(500, description: 'Data skripsi gagal diambil.', type: CampusApiController::SCRAMBLE_STATUS_ERROR_RESPONSE)]
    public function index(Request $request): JsonResponse
    {
        return $this->withCampusSession('Thesis extraction failed', 'Gagal memuat data skripsi', function (string $sessionToken) use ($request): JsonResponse {
            $thesis = $this->readDataset($request, $sessionToken, 'thesis');
            ['krs' => $krs] = $this->peekDatasets($sessionToken, ['krs']);

            return $this->statusSuccess('Thesis data retrieved successfully', [
                'data' => [
                    ...$this->withoutWriteEffectLinks($thesis['data']),
                    'krs_hint' => $this->krsHint($krs['data'], $thesis['data']['status']['state'] ?? null),
                ],
            ], meta: [
                ...$thesis['meta'],
                'insight_sources' => ['krs' => (string) ($krs['meta']['status'] ?? 'missing')],
            ], campusSessionValid: true);
        });
    }

    /**
     * Checklist kesiapan skripsi: SKS lulus, IPK, nilai D, nilai E, administrasi keuangan, dan persiapan ujian (manual).
     *
     * Dihitung dari snapshot SiMASITA, KHS, dan keuangan tanpa request baru ke portal. Snapshot yang belum ada
     * disinkronkan di latar belakang dan barisnya bernilai `unknown` sampai siap (lihat `meta.sources`).
     * Keuangan hanya `failed` bila ada tagihan; tanpa tagihan tetap `unknown` ("Perlu dicek").
     */
    #[Response(description: 'Checklist kesiapan skripsi berhasil dihitung.', type: "array{status: 'success', message: string, data: array{jenjang: 'D3'|'S1', jenjang_source: 'portal'|'default', thresholds: array{min_sks: int, min_ipk: float, max_d: int, allow_e: bool}, threshold_source: 'portal'|'partial'|'default', checklist_items: list<".self::CHECKLIST_ITEM.'>, summary: array{passed: int, failed: int, unknown: int, manual: int, automatic_total: int, academic_ready: bool|null, progress_percent: float|null}, sks_progress: array{current: int|null, target: int, remaining: int|null, percent: float|null}, courses: array{d: list<array<string, mixed>>, e: list<array<string, mixed>>}, insights: array{semesters_to_min_sks: int|null, average_sks_per_semester: float|null, warnings: list<array{key: string, message: string}>}, grades_available: bool}, meta: array{timestamp: string, campus_session_valid: true, status: string, synced_at: string|null, data_origin: string, sources: array{thesis: string, grades: string, payments: string}}}')]
    #[Response(401, description: 'Endpoint ini membutuhkan sesi kampus yang aktif.', type: CampusApiController::SCRAMBLE_SESSION_ERROR_RESPONSE)]
    #[Response(500, description: 'Checklist kesiapan gagal dihitung.', type: CampusApiController::SCRAMBLE_STATUS_ERROR_RESPONSE)]
    public function readiness(): JsonResponse
    {
        return $this->withCampusSession('Thesis readiness failed', 'Gagal menghitung kesiapan skripsi', function (string $sessionToken): JsonResponse {
            $sources = $this->peekDatasets($sessionToken, ['thesis', 'grades', 'payments']);
            $thesis = $sources['thesis']['data'] ?? [];
            $result = $this->evaluator->evaluate(
                is_array($thesis['thresholds'] ?? null) ? $thesis['thresholds'] : [],
                $sources['grades']['data'] ?? [],
                $sources['payments']['data'] ?? [],
                (string) ($thesis['student']['jenjang'] ?? '')
            );

            return $this->statusSuccess('Thesis readiness evaluated successfully', [
                'data' => [
                    ...$result,
                    'threshold_source' => $thesis === [] ? 'default' : (string) ($thesis['threshold_source'] ?? 'default'),
                ],
            ], meta: [
                'data_origin' => 'snapshot',
                'status' => $this->combinedStatus(array_map(static fn (array $source): string => (string) ($source['meta']['status'] ?? 'missing'), $sources)),
                'synced_at' => $this->oldestSyncedAt($sources),
                'sources' => array_map(static fn (array $source): string => (string) ($source['meta']['status'] ?? 'missing'), $sources),
            ], campusSessionValid: true);
        });
    }

    /**
     * @param  array<string, mixed>|null  $krs
     * @return array{has_thesis_course: bool|null, course: array{code: string|null, name: string|null}|null, awaiting_status: bool}
     */
    private function krsHint(?array $krs, mixed $state): array
    {
        if (($krs['found'] ?? false) !== true || ! is_array($krs['courses'] ?? null)) {
            return ['has_thesis_course' => null, 'course' => null, 'awaiting_status' => false];
        }

        foreach ($krs['courses'] as $course) {
            if (is_array($course) && is_string($course['name'] ?? null) && preg_match(self::THESIS_COURSE_PATTERN, $course['name']) === 1) {
                return [
                    'has_thesis_course' => true,
                    'course' => ['code' => is_string($course['code'] ?? null) ? $course['code'] : null, 'name' => $course['name']],
                    'awaiting_status' => $state === 'not_enrolled',
                ];
            }
        }

        return ['has_thesis_course' => false, 'course' => null, 'awaiting_status' => false];
    }

    /**
     * The least ready source decides: failed, then pending/missing, then syncing.
     *
     * @param  array<string, string>  $statuses
     */
    private function combinedStatus(array $statuses): string
    {
        foreach (['failed', 'pending', 'missing', 'syncing'] as $status) {
            if (in_array($status, $statuses, true)) {
                return $status;
            }
        }

        return 'synced';
    }

    /**
     * @param  array<string, array{data: array<string, mixed>|null, meta: array<string, mixed>}>  $sources
     */
    private function oldestSyncedAt(array $sources): ?string
    {
        $times = array_filter(array_map(static fn (array $source): mixed => $source['meta']['synced_at'] ?? null, $sources), 'is_string');

        if ($times === []) {
            return null;
        }

        usort($times, static fn (string $left, string $right): int => strtotime($left) <=> strtotime($right));

        return $times[0];
    }
}
