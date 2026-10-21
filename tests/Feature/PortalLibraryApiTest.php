<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\RefreshPortalDataJob;
use App\Services\Extractors\LibraryExtractor;
use App\Services\Extractors\ThesisRepositoryExtractor;
use App\Services\PortalSessionManager;
use App\Services\PortalSnapshotStore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * GET /api/library (digital libraries + repository summary) and
 * /api/library/theses (search, filters, facets, title similarity).
 */
class PortalLibraryApiTest extends TestCase
{
    private const TOKEN = 'token-library';

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake([RefreshPortalDataJob::class]);
        $this->travelTo(CarbonImmutable::create(2026, 10, 21, 9, 0, 0, 'Asia/Jakarta'));
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_library_returns_links_and_the_repository_summary_from_its_snapshot(): void
    {
        $this->mockValidCampusSession();
        $this->snapshots()->put(self::TOKEN, 'thesis_repository', $this->index());
        $this->instance(LibraryExtractor::class, Mockery::mock(LibraryExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('extractLibraryLinksData')->once()->with(self::TOKEN)->andReturn([
                'found' => true,
                'links' => [
                    ['key' => 'a1', 'title' => 'Perpustakaan UCIC', 'url' => 'https://pustaka.cic.ac.id/', 'host' => 'pustaka.cic.ac.id', 'is_internal' => false, 'internal_route' => null, 'requires_account' => false],
                    ['key' => 'b2', 'title' => 'SiMASITA Repositori', 'url' => null, 'host' => null, 'is_internal' => true, 'internal_route' => '/library/theses', 'requires_account' => false],
                ],
                'source' => 'portal',
                'extracted_at' => '2026-10-21T02:00:00+00:00',
                '_sync' => ['data_origin' => 'upstream'],
            ]);
        }));

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/library')
            ->assertOk()
            ->assertJsonPath('data.links.1.internal_route', '/library/theses')
            ->assertJsonPath('data.source', 'portal')
            ->assertJsonPath('data.repository.status', 'synced')
            ->assertJsonPath('data.repository.record_count', 3)
            ->assertJsonPath('data.repository.total', 3)
            ->assertJsonPath('data.repository.complete', true)
            ->assertJsonPath('data.repository.latest_academic_year', '2023/2024')
            ->assertJsonPath('data.repository.programs.0.key', 'sistem informasi')
            ->assertJsonPath('data.repository.programs.0.count', 2)
            ->assertJsonPath('data.repository.top_advisors.0.key', 'dosen contoh alfa')
            ->assertJsonPath('meta.data_origin', 'upstream')
            ->assertJsonMissingPath('data._sync');

        Bus::assertNotDispatched(RefreshPortalDataJob::class);
    }

    public function test_library_queues_the_first_repository_index_in_the_background(): void
    {
        $this->mockValidCampusSession();
        $this->snapshots()->put(self::TOKEN, 'library', ['found' => true, 'links' => [], 'source' => 'portal', 'extracted_at' => '2026-10-21T02:00:00+00:00']);

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/library')
            ->assertOk()
            ->assertJsonPath('data.repository.status', 'pending')
            ->assertJsonPath('data.repository.record_count', 0);

        Bus::assertDispatched(RefreshPortalDataJob::class, fn (RefreshPortalDataJob $job): bool => $this->jobDomains($job) === ['thesis_repository']);
    }

    public function test_theses_are_searched_filtered_and_faceted_on_the_server(): void
    {
        $this->mockValidCampusSession();
        $this->snapshots()->put(self::TOKEN, 'thesis_repository', $this->index());
        $this->instance(ThesisRepositoryExtractor::class, Mockery::mock(ThesisRepositoryExtractor::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('extractRepositoryPage');
        }));

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/library/theses?'.http_build_query(['q' => 'sistem', 'prodi' => 'sistem informasi', 'per_page' => 10]))
            ->assertOk()
            ->assertJsonPath('data.mode', 'search')
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.record_key', 'r1')
            ->assertJsonPath('data.items.0.advisors.0.key', 'dosen contoh alfa')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.sort', 'relevansi')
            ->assertJsonPath('meta.index.partial', false)
            ->assertJsonPath('meta.index.record_count', 3)
            ->assertJsonPath('meta.status', 'synced')
            ->assertJsonPath('meta.facets.prodi.0.key', 'sistem informasi');
    }

    public function test_similar_to_returns_the_closest_titles(): void
    {
        $this->mockValidCampusSession();
        $this->snapshots()->put(self::TOKEN, 'thesis_repository', $this->index());

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/library/theses?'.http_build_query(['similar_to' => 'Sistem Informasi Persediaan Barang Berbasis Web']))
            ->assertOk()
            ->assertJsonPath('data.mode', 'similar')
            ->assertJsonPath('data.items.0.record_key', 'r1')
            ->assertJsonStructure(['data' => ['items' => [['similarity', 'similarity_percent']]]]);
    }

    public function test_the_first_visit_serves_portal_page_one_while_the_index_builds(): void
    {
        $this->mockValidCampusSession();
        $this->instance(ThesisRepositoryExtractor::class, Mockery::mock(ThesisRepositoryExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('extractRepositoryPage')->once()->with(self::TOKEN, 1)->andReturn([
                'found' => true,
                'records' => array_slice($this->index()['records'], 0, 2),
                'paging' => ['page' => 1, 'per_page' => 100, 'total' => 212, 'last_page' => 3, 'has_next' => true, 'next_page' => 2],
                'extracted_at' => '2026-10-21T02:00:00+00:00',
                '_sync' => ['data_origin' => 'upstream'],
            ]);
        }));

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/library/theses')
            ->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('meta.index.partial', true)
            ->assertJsonPath('meta.index.total', 212)
            ->assertJsonPath('meta.index.complete', false)
            ->assertJsonPath('meta.status', 'pending')
            ->assertJsonPath('meta.data_origin', 'upstream')
            ->assertJsonMissingPath('meta.partial');

        Bus::assertDispatched(RefreshPortalDataJob::class, fn (RefreshPortalDataJob $job): bool => $this->jobDomains($job) === ['thesis_repository']);
    }

    public function test_fresh_reindexes_in_the_background_instead_of_crawling_inline(): void
    {
        $this->mockValidCampusSession();
        $this->snapshots()->put(self::TOKEN, 'thesis_repository', $this->index());
        $this->instance(ThesisRepositoryExtractor::class, Mockery::mock(ThesisRepositoryExtractor::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('extractRepositoryIndex');
            $mock->shouldNotReceive('extractRepositoryPage');
        }));

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/library/theses?fresh=1')
            ->assertOk()
            ->assertJsonCount(3, 'data.items')
            ->assertJsonPath('meta.status', 'syncing');

        Bus::assertDispatched(RefreshPortalDataJob::class, fn (RefreshPortalDataJob $job): bool => $this->jobDomains($job) === ['thesis_repository']);
    }

    public function test_invalid_search_parameters_are_rejected_in_indonesian(): void
    {
        $this->mockValidCampusSession();

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/library/theses?per_page=500')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Jumlah per halaman maksimal 50.');

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/library/theses?ta=2023-2024')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Tahun akademik harus berformat yyyy/yyyy, misalnya 2023/2024.');

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/library/theses?'.http_build_query(['similar_to' => str_repeat('a', 201)]))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Rencana judul maksimal 200 karakter.');

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/library/theses?sort=acak')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Urutan harus terbaru, judul, atau relevansi.');
    }

    public function test_library_routes_require_a_valid_campus_session(): void
    {
        foreach (['/api/library', '/api/library/theses'] as $uri) {
            $this->getJson($uri)
                ->assertUnauthorized()
                ->assertJsonPath('message', 'Sesi kampus tidak ditemukan. Silakan masuk lagi.');
        }
    }

    private function mockValidCampusSession(): void
    {
        $this->instance(PortalSessionManager::class, Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('validateSession')->with(self::TOKEN)->andReturnTrue();
        }));
    }

    private function snapshots(): PortalSnapshotStore
    {
        return app(PortalSnapshotStore::class);
    }

    /**
     * @return list<string>
     */
    private function jobDomains(RefreshPortalDataJob $job): array
    {
        return (new \ReflectionClass($job))->getProperty('domains')->getValue($job);
    }

    /**
     * A complete `thesis_repository` index with synthetic records (K14)
     *
     * @return array<string, mixed>
     */
    private function index(): array
    {
        $records = [
            ['record_key' => 'r1', 'no' => 1, 'academic_year' => '2023/2024', 'program' => 'SISTEM INFORMASI', 'nim' => '190100001', 'author' => 'MAHASISWA CONTOH SATU', 'title' => 'SISTEM INFORMASI PERSEDIAAN BARANG BERBASIS WEB', 'advisor_1' => 'Dosen Contoh Alfa, S.Kom., M.Kom.', 'advisor_2' => null, 'file_path' => 'files/skripsi/kolokium/r1.pdf', 'page' => 1],
            ['record_key' => 'r2', 'no' => 2, 'academic_year' => '2023/2024', 'program' => 'TEKNIK INFORMATIKA', 'nim' => '190200002', 'author' => 'MAHASISWA CONTOH DUA', 'title' => 'Klasifikasi Citra Daun', 'advisor_1' => 'Dosen Contoh Gamma, M.Kom.', 'advisor_2' => null, 'file_path' => null, 'page' => 1],
            ['record_key' => 'r3', 'no' => 3, 'academic_year' => '2022/2023', 'program' => 'SISTEM INFORMASI', 'nim' => '180100003', 'author' => 'MAHASISWA CONTOH TIGA', 'title' => 'Aplikasi Monitoring Kehadiran', 'advisor_1' => 'Dosen Contoh Alfa, S.Kom, M.Kom', 'advisor_2' => null, 'file_path' => 'files/skripsi/kolokium/r3.pdf', 'page' => 1],
        ];

        return [
            'found' => true,
            'records' => $records,
            'total' => 3,
            'record_count' => 3,
            'pages_loaded' => 1,
            'last_page' => 1,
            'complete' => true,
            'reused_snapshot' => false,
            'first_record_key' => 'r1',
            'extracted_at' => '2026-10-21T02:00:00+00:00',
        ];
    }
}
