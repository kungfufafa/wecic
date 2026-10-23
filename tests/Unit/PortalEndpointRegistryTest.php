<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PortalEndpointRegistry;
use InvalidArgumentException;
use Tests\TestCase;

class PortalEndpointRegistryTest extends TestCase
{
    public function test_url_for_simple_endpoint_matches_config_value(): void
    {
        $expected = data_get(config('portal.endpoints.profile'), 'url');

        $this->assertSame($expected, PortalEndpointRegistry::url('profile'));
    }

    public function test_url_merges_default_and_override_query_values(): void
    {
        $url = PortalEndpointRegistry::url('course_materials', [
            'id' => '1451',
            'dosen_id' => '2744',
            'course_id' => '8833',
        ]);

        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame([
            'mod' => 'sipandai',
            'act' => 'download',
            'id' => '1451',
            'dosen_id' => '2744',
            'course_id' => '8833',
        ], $query);
    }

    public function test_missing_required_params_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Portal endpoint [course_materials] is missing required parameters');

        PortalEndpointRegistry::url('course_materials', [
            'id' => '1451',
        ]);
    }

    public function test_grouped_by_category_contains_expected_keys(): void
    {
        $grouped = PortalEndpointRegistry::groupedByCategory();

        $this->assertArrayHasKey('data', $grouped);
        $this->assertArrayHasKey('action', $grouped);
        $this->assertArrayHasKey('profile', $grouped['data']);
        $this->assertArrayHasKey('assignment_submit', $grouped['action']);
    }

    public function test_keys_match_configured_endpoints(): void
    {
        $this->assertSame(
            array_keys(config('portal.endpoints')),
            PortalEndpointRegistry::keys()
        );
    }

    public function test_decision_feature_endpoints_are_registered(): void
    {
        $expected = [
            'student_announcement' => ['GET', 'data'],
            'student_questionnaire_form' => ['POST', 'action'],
            'student_credit' => ['GET', 'data'],
            'student_advising' => ['GET', 'data'],
            'student_krs_detail' => ['GET', 'data'],
            'student_krs_print' => ['GET', 'data'],
            'student_skkm' => ['GET', 'data'],
            'student_skkm_add_form' => ['GET', 'action'],
            'student_skkm_submit' => ['POST', 'action'],
            'student_skkm_kegiatan_options' => ['POST', 'data'],
            'student_skkm_tingkat_options' => ['POST', 'data'],
            'student_skkm_jenis_options' => ['POST', 'data'],
            'student_simasita' => ['GET', 'data'],
            'student_skripsi' => ['GET', 'data'],
            'student_ebook' => ['GET', 'data'],
            'student_repository' => ['GET', 'data'],
            'sipandai_score' => ['GET', 'data'],
            'sipandai_transcript_print' => ['GET', 'data'],
            'exam_card' => ['GET', 'data'],
            'meeting_conformity' => ['GET', 'action'],
            'exam_submission_delete' => ['GET', 'action'],
        ];

        foreach ($expected as $key => [$method, $category]) {
            $definition = PortalEndpointRegistry::definition($key);

            $this->assertSame($method, PortalEndpointRegistry::method($key), $key);
            $this->assertSame($category, $definition['category'], $key);
            $this->assertTrue($definition['requires_session'], $key);
            $this->assertNotSame('', $definition['description'], $key);
        }
    }

    public function test_student_page_endpoints_build_portal_query_urls(): void
    {
        $mahasiswaBase = config('portal.base_url').'/portal/mahasiswa/';

        $this->assertSame($mahasiswaBase.'?mod=student&act=pengumuman', PortalEndpointRegistry::url('student_announcement'));
        $this->assertSame($mahasiswaBase.'?mod=student&act=pengumuman&page=2', PortalEndpointRegistry::url('student_announcement', ['page' => 2]));
        $this->assertSame($mahasiswaBase.'?mod=student&act=perwalian', PortalEndpointRegistry::url('student_advising'));
        $this->assertSame($mahasiswaBase.'?mod=student&act=skkm', PortalEndpointRegistry::url('student_skkm'));
        $this->assertSame($mahasiswaBase.'?mod=student&act=repositori&page=3', PortalEndpointRegistry::url('student_repository', ['page' => 3]));
        $this->assertSame($mahasiswaBase.'?mod=student&act=viewkrs&id=90001', PortalEndpointRegistry::url('student_krs_detail', ['id' => 90001]));
    }

    public function test_student_credit_endpoint_replaces_the_flat_credit_url(): void
    {
        $this->assertSame(config('portal.student_credit_url'), PortalEndpointRegistry::url('student_credit'));
    }

    public function test_krs_detail_requires_a_krs_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Portal endpoint [student_krs_detail] is missing required parameters: id');

        PortalEndpointRegistry::url('student_krs_detail');
    }

    public function test_static_view_endpoints_have_no_query(): void
    {
        $baseUrl = config('portal.base_url');

        $this->assertSame($baseUrl.'/portal/mahasiswa/views/cetakkrs/print.php', PortalEndpointRegistry::url('student_krs_print'));
        $this->assertSame($baseUrl.'/portal/mahasiswa/views/skkm/get_tingkat.php', PortalEndpointRegistry::url('student_skkm_tingkat_options'));
        $this->assertSame($baseUrl.'/portal/sipandai/views/transcripts/print.php', PortalEndpointRegistry::url('sipandai_transcript_print'));
        $this->assertSame($baseUrl.'/portal/sipandai/views/ujian/kartuujian.php', PortalEndpointRegistry::url('exam_card'));
    }

    public function test_sipandai_score_keeps_the_trailing_slash(): void
    {
        $this->assertSame(
            config('portal.base_url').'/portal/sipandai/?mod=sipandai&act=score',
            PortalEndpointRegistry::url('sipandai_score')
        );
    }

    public function test_write_effect_endpoints_require_their_server_side_payload(): void
    {
        $this->assertSame(['subject', 'pertemuan', 'courses', 'dosen', 'sesuai', 'modules'], PortalEndpointRegistry::requiredParams('meeting_conformity'));
        $this->assertSame(['id'], PortalEndpointRegistry::requiredParams('exam_submission_delete'));

        $grouped = PortalEndpointRegistry::groupedByCategory();

        $this->assertArrayHasKey('meeting_conformity', $grouped['action']);
        $this->assertArrayHasKey('exam_submission_delete', $grouped['action']);
        $this->assertArrayHasKey('student_skkm_submit', $grouped['action']);
    }

    public function test_every_endpoint_without_required_params_can_be_described(): void
    {
        foreach (PortalEndpointRegistry::keys() as $key) {
            if (PortalEndpointRegistry::requiredParams($key) !== []) {
                continue;
            }

            $description = PortalEndpointRegistry::describe($key);

            $this->assertNotFalse(filter_var($description['url'], FILTER_VALIDATE_URL), $key);
        }
    }

    public function test_an_endpoint_with_required_params_is_described_with_a_url_template(): void
    {
        $description = PortalEndpointRegistry::describe('course_materials');

        $this->assertSame(
            config('portal.base_url').'/portal/sipandai/?mod=sipandai&act=download&id={id}&dosen_id={dosen_id}&course_id={course_id}',
            $description['url']
        );
        $this->assertSame(['id', 'dosen_id', 'course_id'], $description['required_params']);
        $this->assertSame('GET', $description['method']);
    }

    public function test_every_registered_endpoint_can_be_described(): void
    {
        foreach (PortalEndpointRegistry::keys() as $key) {
            $description = PortalEndpointRegistry::describe($key);

            foreach (PortalEndpointRegistry::requiredParams($key) as $param) {
                $this->assertStringContainsString($param.'=', $description['url'], $key);
            }

            $this->assertStringNotContainsString('%7B', $description['url'], $key);
        }
    }

    public function test_download_and_login_failure_limits_are_configurable(): void
    {
        $this->assertSame(120, config('portal.scraping.download_timeout'));
        $this->assertSame(30, config('portal.scraping.download_read_timeout'));
        $this->assertSame(50 * 1024 * 1024, config('portal.scraping.download_max_bytes'));
        $this->assertSame(30, config('portal.security.login_failure_attempts_per_ip'));
    }

    public function test_decision_feature_resources_have_cache_ttls(): void
    {
        foreach (['portal_context', 'announcements', 'krs', 'advising', 'krs_detail', 'khs', 'skkm'] as $resource) {
            $ttl = config("portal.cache.resources.{$resource}");

            $this->assertIsInt($ttl, $resource);
            $this->assertGreaterThan(0, $ttl, $resource);
        }

        // One name per resource: the unused aliases are gone.
        $this->assertNull(config('portal.cache.resources.krs_current'));
        $this->assertNull(config('portal.cache.resources.grades'));
    }

    public function test_decision_datasets_and_targets_are_configured(): void
    {
        foreach (['portal_context', 'announcements', 'krs', 'advising', 'grades', 'skkm'] as $dataset) {
            $this->assertIsInt(config("portal.datasets.{$dataset}.fresh_for"), $dataset);
            $this->assertContains('/api/action-items', config("portal.datasets.{$dataset}.cache_keys"), $dataset);
            $this->assertContains('dashboard-action-items', config("portal.datasets.{$dataset}.sync_keys"), $dataset);
            $this->assertFalse(config("portal.datasets.{$dataset}.parameterized", false), $dataset);
        }

        $this->assertTrue(config('portal.datasets.krs_detail.parameterized'));
        $this->assertTrue(config('portal.datasets.announcement_page.parameterized'));
        $this->assertContains('/api/finance/summary', config('portal.datasets.payments.cache_keys'));
        $this->assertGreaterThan(0, config('portal.academic.target_sks'));
        $this->assertSame(['portal_context', 'krs', 'advising', 'payments', 'grades', 'skkm', 'announcements', 'exams'], config('portal.sync.warmup_datasets'));
    }

    public function test_thesis_and_library_datasets_are_configured(): void
    {
        foreach (['thesis' => 'thesis', 'library' => 'library_links', 'thesis_repository' => 'thesis_repository'] as $dataset => $resource) {
            $this->assertIsInt(config("portal.datasets.{$dataset}.fresh_for"), $dataset);
            $this->assertFalse(config("portal.datasets.{$dataset}.parameterized", false), $dataset);
            $this->assertGreaterThan(0, config("portal.cache.resources.{$resource}"), $resource);
        }

        $this->assertSame(['/api/thesis', '/api/thesis/readiness'], config('portal.datasets.thesis.cache_keys'));
        $this->assertContains('/api/library/theses', config('portal.datasets.thesis_repository.cache_keys'));
        $this->assertContains('library-theses', config('portal.datasets.thesis_repository.sync_keys'));

        // Readiness is computed from grades and payments too, so their syncs reload it.
        foreach (['grades', 'payments'] as $dataset) {
            $this->assertContains('/api/thesis/readiness', config("portal.datasets.{$dataset}.cache_keys"), $dataset);
            $this->assertContains('thesis-index', config("portal.datasets.{$dataset}.sync_keys"), $dataset);
        }

        // A full repository crawl never joins the login warm-up.
        $this->assertNotContains('thesis_repository', config('portal.sync.warmup_datasets'));
        $this->assertContains('/library/theses', array_column(config('portal.library.fallback_links'), 'internal_route'));
    }
}
