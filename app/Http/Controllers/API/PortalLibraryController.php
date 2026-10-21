<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Requests\PortalThesisSearchRequest;
use App\Services\Extractors\ThesisRepositoryExtractor;
use App\Services\PortalDatasetRepository;
use App\Services\ThesisRepositorySearch;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Skripsi & Pustaka', 'Syarat dan SOP skripsi (SiMASITA), status e-Skripsi, checklist kesiapan, perpustakaan digital, dan repositori skripsi.', 27)]
class PortalLibraryController extends CampusApiController
{
    private const LIBRARY_LINK = 'array{key: string, title: string, url: string|null, host: string|null, is_internal: bool, internal_route: string|null, requires_account: bool}';

    private const THESIS_RECORD = 'array{record_key: string, no: int, academic_year: string|null, program: string|null, program_key: string, nim: string|null, author: string|null, title: string, advisor_1: string|null, advisor_2: string|null, advisors: list<array{name: string, key: string}>, file_path: string|null, page: int, score: int|null}';

    private const FACET = 'list<array{value: string, key: string, count: int}>';

    public function __construct(
        private readonly PortalDatasetRepository $datasets,
        private readonly ThesisRepositoryExtractor $repositoryExtractor,
        private readonly ThesisRepositorySearch $search
    ) {}

    /**
     * Hub perpustakaan: tautan perpustakaan digital dari halaman e-Book portal dan ringkasan repositori skripsi.
     *
     * Tautan repositori portal diganti rute MyCIC `/library/theses` (`is_internal=true`). Bila halaman portal
     * tidak memuat tautan, daftar bawaan dari config dipakai (`source=config`). Ringkasan repositori dibaca dari
     * snapshot saja; snapshot yang belum ada dibangun di latar belakang (`repository.status=pending`).
     */
    #[Response(description: 'Hub perpustakaan berhasil diambil.', type: "array{status: 'success', message: string, data: array{found: bool, links: list<".self::LIBRARY_LINK.">, source: 'portal'|'config', extracted_at: string, repository: array{status: string, synced_at: string|null, total: int|null, record_count: int, complete: bool, first_record_key: string|null, latest_academic_year: string|null, programs: ".self::FACET.', top_advisors: '.self::FACET.', top_keywords: list<array{word: string, count: int}>}}, meta: array{timestamp: string, campus_session_valid: true, status: string, synced_at: string|null, data_origin: string, requested_fresh?: bool}}')]
    #[Response(401, description: 'Endpoint ini membutuhkan sesi kampus yang aktif.', type: CampusApiController::SCRAMBLE_SESSION_ERROR_RESPONSE)]
    #[Response(500, description: 'Hub perpustakaan gagal diambil.', type: CampusApiController::SCRAMBLE_STATUS_ERROR_RESPONSE)]
    public function index(Request $request): JsonResponse
    {
        return $this->withCampusSession('Library extraction failed', 'Gagal memuat perpustakaan', function (string $sessionToken) use ($request): JsonResponse {
            $library = $this->readDataset($request, $sessionToken, 'library');
            $repository = $this->datasets->peek($sessionToken, 'thesis_repository', [], (string) session()->getId());

            return $this->statusSuccess('Library retrieved successfully', [
                'data' => [
                    ...$library['data'],
                    'repository' => $this->repositorySummary($repository),
                ],
            ], meta: $library['meta'], campusSessionValid: true);
        });
    }

    /**
     * Repositori skripsi: cari, saring (prodi, T.A., pembimbing), urutkan, dan cek kemiripan judul.
     *
     * Portal tidak punya pencarian yang berfungsi, jadi seluruh repositori diindeks di latar belakang (sekitar
     * 7 halaman portal) dan dicari di server MyCIC. Selama indeks pertama disiapkan, hasil diambil dari halaman 1
     * portal (`meta.index.partial=true`). `fresh=1` menjadwalkan sinkron ulang indeks tanpa menunggu.
     * `meta.facets` berisi jumlah hasil per opsi filter. Dengan `similar_to`, `data.items` berisi 5 judul
     * paling mirip beserta `similarity` (0–1); ini bukan cek plagiasi resmi. PDF dibuka lewat
     * `GET /api/support/download?path=<file_path>`.
     */
    #[Response(description: 'Hasil repositori skripsi.', type: "array{status: 'success', message: string, data: array{mode: 'search'|'similar', items: list<".self::THESIS_RECORD.'>}, meta: array{timestamp: string, campus_session_valid: true, status: string, synced_at: string|null, data_origin: string, index: array{partial: bool, total: int|null, record_count: int, complete: bool, pages_loaded: int|null}, total: int, page: int, per_page: int, last_page: int, has_more: bool, sort: string|null, query: string|null, filters: array{prodi: string|null, ta: string|null, pembimbing: string|null}, facets: array{prodi: '.self::FACET.', ta: '.self::FACET.', pembimbing: '.self::FACET.'}}}')]
    #[Response(401, description: 'Endpoint ini membutuhkan sesi kampus yang aktif.', type: CampusApiController::SCRAMBLE_SESSION_ERROR_RESPONSE)]
    #[Response(422, description: 'Parameter pencarian tidak valid.', type: CampusApiController::SCRAMBLE_VALIDATION_ERROR_RESPONSE)]
    #[Response(500, description: 'Repositori skripsi gagal dimuat.', type: CampusApiController::SCRAMBLE_STATUS_ERROR_RESPONSE)]
    public function theses(PortalThesisSearchRequest $request): JsonResponse
    {
        return $this->withCampusSession('Thesis repository search failed', 'Gagal memuat repositori skripsi', function (string $sessionToken) use ($request): JsonResponse {
            if ($request->boolean('fresh')) {
                // A full crawl never runs inline: mark the index stale and let one background sync rebuild it.
                $this->datasets->invalidate($sessionToken, ['thesis_repository']);
            }

            $repository = $this->datasets->peek($sessionToken, 'thesis_repository', [], (string) session()->getId());
            $index = is_array($repository['data'] ?? null) ? $repository['data'] : null;
            $meta = $repository['meta'];

            if ($index === null || ! is_array($index['records'] ?? null)) {
                // First visit: page 1 now, the whole index in the background.
                $page = $this->repositoryExtractor->extractRepositoryPage($sessionToken, 1);
                $index = [
                    'records' => $page['records'],
                    'total' => $page['paging']['total'],
                    'complete' => ! $page['paging']['has_next'],
                    'pages_loaded' => 1,
                ];
                $meta['data_origin'] = $page['_sync']['data_origin'] ?? 'upstream';
                $meta['partial'] = true;
            }

            $records = array_values(array_filter($index['records'], 'is_array'));
            $similarTo = $request->similarTo();
            $partial = ($meta['partial'] ?? false) === true;
            unset($meta['partial']);

            $indexMeta = [
                'partial' => $partial,
                'total' => is_int($index['total'] ?? null) ? $index['total'] : null,
                'record_count' => count($records),
                'complete' => ($index['complete'] ?? false) === true,
                'pages_loaded' => is_int($index['pages_loaded'] ?? null) ? $index['pages_loaded'] : null,
            ];

            if ($similarTo !== null) {
                $items = $this->search->similar($records, $similarTo);

                return $this->statusSuccess('Similar theses retrieved successfully', [
                    'data' => ['mode' => 'similar', 'items' => $items],
                ], meta: [...$meta, 'index' => $indexMeta, 'total' => count($items)], campusSessionValid: true);
            }

            $result = $this->search->search($records, $request->filters());

            return $this->statusSuccess('Thesis repository retrieved successfully', [
                'data' => ['mode' => 'search', 'items' => $result['items']],
            ], meta: [...$meta, 'index' => $indexMeta, ...$result['meta']], campusSessionValid: true);
        });
    }

    /**
     * @param  array{data: array<string, mixed>|null, meta: array<string, mixed>}  $repository
     * @return array<string, mixed>
     */
    private function repositorySummary(array $repository): array
    {
        $index = is_array($repository['data'] ?? null) ? $repository['data'] : [];
        $records = array_values(array_filter(is_array($index['records'] ?? null) ? $index['records'] : [], 'is_array'));
        $facets = $records === [] ? ['prodi' => [], 'pembimbing' => []] : $this->search->search($records, ['per_page' => ThesisRepositorySearch::MIN_PER_PAGE])['meta']['facets'];
        $years = array_filter(array_column($records, 'academic_year'), 'is_string');

        return [
            'status' => (string) ($repository['meta']['status'] ?? 'missing'),
            'synced_at' => $repository['meta']['synced_at'] ?? null,
            'total' => is_int($index['total'] ?? null) ? $index['total'] : null,
            'record_count' => count($records),
            'complete' => ($index['complete'] ?? false) === true,
            'first_record_key' => is_string($index['first_record_key'] ?? null) ? $index['first_record_key'] : null,
            'latest_academic_year' => $years === [] ? null : max($years),
            'programs' => $facets['prodi'],
            'top_advisors' => array_slice($facets['pembimbing'], 0, 5),
            'top_keywords' => $this->search->keywords($records, 8),
        ];
    }
}
