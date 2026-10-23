<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\PortalSessionManager;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Guards on the page scripts of the academic, thesis and library pages that
 * have no JS test runner: the rendered script must keep each rule below.
 */
class AcademicPageScriptsTest extends TestCase
{
    private const TOKEN = 'token-academic-scripts';

    protected function setUp(): void
    {
        parent::setUp();

        $this->instance(PortalSessionManager::class, Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('validateSession')->with(self::TOKEN)->andReturnTrue();
        }));
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_the_transcript_simulator_never_divides_by_conversion_sks(): void
    {
        $html = $this->html('/transcript');

        $this->assertStringContainsString('Number.isInteger(summary.sks_total)
                    ? Math.max(0, summary.sks_total)
                    : Math.max(0, (Number(summary.sks_passed) || 0) - (Number(summary.conversion_sks) || 0));', $html);
        $this->assertStringNotContainsString('summary.sks_total > 0 ? summary.sks_total : (Number(summary.sks_passed) || 0)', $html);
        $this->assertStringContainsString('Simulasi butuh minimal satu nilai yang sudah keluar.', $html);
    }

    public function test_the_transcript_marks_a_partial_latest_ips_and_a_fully_graded_krs(): void
    {
        $html = $this->html('/transcript');

        $this->assertStringContainsString("latest.is_complete === false ? ' (sebagian)' : ''", $html);
        $this->assertStringContainsString('Semua MK di KRS ini sudah dinilai', $html);
        $this->assertStringContainsString('data.current_load.graded_courses', $html);
    }

    public function test_the_grades_page_marks_a_partial_latest_ips(): void
    {
        $this->assertStringContainsString("latest.is_complete === false ? ' · sebagian' : ''", $this->html('/grades'));
    }

    public function test_krs_detail_keeps_what_it_shows_after_a_portal_hiccup(): void
    {
        $html = $this->html('/krs/90004');

        $this->assertStringContainsString("error?.payload?.reason === 'not_in_history'", $html);
        $this->assertStringContainsString('if (!state.detail || isNotInHistory(error)) {', $html);
        $this->assertStringNotContainsString('if (!state.detail || error?.status === 404) {', $html);

        // The device copy is only forgotten for a KRS that is not the student's.
        $clear = strpos($html, 'app.clearCachedResponse(endpoint);');
        $this->assertNotFalse($clear);
        $this->assertGreaterThan(strpos($html, 'if (isNotInHistory(error)) {'), $clear);
    }

    public function test_the_thesis_page_writes_ipk_with_a_dot_like_the_other_academic_pages(): void
    {
        $html = $this->html('/thesis');

        $this->assertStringContainsString('const formatIpk = (value) => (Number.isFinite(Number(value)) ? Number(value).toFixed(2) : \'–\');', $html);
        $this->assertStringContainsString('`IPK ≥ ${formatIpk(thresholds.min_ipk)}`', $html);
        $this->assertStringNotContainsString('ipkFormatter', $html);
    }

    public function test_the_thesis_repository_restores_load_more_after_a_failed_page(): void
    {
        $html = $this->html('/library/theses');

        $this->assertStringContainsString('try {
                    await fetchResults({ append: true });
                } finally {', $html);
        $this->assertStringContainsString("button.textContent = 'Muat lagi';", $html);
    }

    public function test_the_thesis_repository_advisor_facet_drops_prefix_titles_like_the_server(): void
    {
        $html = $this->html('/library/theses');

        $this->assertStringContainsString(".split(',')[0].replace(/\\./g, ' ')", $html);
        $this->assertStringContainsString("normalized.replace(/^(?:(?:dr|drs|dra|prof|ir|h|hj) )+/, '') || normalized", $html);
    }

    private function html(string $path): string
    {
        return (string) $this->withSession(['campus_session_token' => self::TOKEN])
            ->get($path)
            ->assertOk()
            ->getContent();
    }
}
