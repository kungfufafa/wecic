<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\PortalScrapingException;
use App\Services\Extractors\PortalContextExtractor;
use App\Services\Extractors\ProfileExtractor;
use App\Services\Extractors\SipandaiClassesExtractor;
use App\Services\Extractors\StudentCourseExtractor;
use App\Services\PortalContextResolver;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;
use Tests\Traits\InteractsWithPortalFixtures;

class PortalContextResolverTest extends TestCase
{
    use InteractsWithPortalFixtures;

    private const SESSION = 'token-context';

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_takes_the_semester_from_the_schedule_page(): void
    {
        $resolver = $this->makeResolver(
            studentCourse: fn (MockInterface $mock) => $mock->shouldReceive('extractStudentCourseData')->once()->with(self::SESSION)
                ->andReturn(['semester_info' => ['semester' => '3'], '_sync' => ['data_origin' => 'server_cache']]),
            sipandaiClasses: fn (MockInterface $mock) => $mock->shouldNotReceive('extractSipandaiClassesData'),
            profile: fn (MockInterface $mock) => $mock->shouldNotReceive('extractProfileData'),
        );

        $context = $resolver->resolve(self::SESSION);

        $this->assertSame(3, $context['semester_number']);
        $this->assertSame('student_course', $context['semester_source']);
        $this->assertFalse($context['semester_is_estimate']);
        $this->assertSame('Semester 3', $context['semester_label']);
        $this->assertSame('T.A 2026/2027 · Ganjil · Semester 3', $context['summary_label']);
        $this->assertSame($this->portalContext()['academic_period'], $context['academic_period']);
        $this->assertSame($this->portalContext()['guide_links'], $context['guide_links']);
        $this->assertSame($this->portalContext()['sidebar_menu'], $context['sidebar_menu']);
        $this->assertNull($context['notice']);
        $this->assertSame(['data_origin' => 'server_cache'], $context['_sync']);
    }

    public function test_it_resolves_the_context_from_the_raw_dashboard_and_schedule_pages(): void
    {
        $resolver = new PortalContextResolver(
            $this->makeExtractorWithRawFixture(PortalContextExtractor::class, 'tests/Fixtures/portal/student_dashboard_raw.html'),
            $this->makeExtractorWithRawFixture(StudentCourseExtractor::class, 'tests/Fixtures/portal/student_course_raw.html'),
            Mockery::mock(SipandaiClassesExtractor::class, fn (MockInterface $mock) => $mock->shouldNotReceive('extractSipandaiClassesData')),
            Mockery::mock(ProfileExtractor::class, fn (MockInterface $mock) => $mock->shouldNotReceive('extractProfileData')),
        );

        $context = $resolver->resolve(self::SESSION);

        $this->assertSame(5, $context['semester_number']);
        $this->assertSame('student_course', $context['semester_source']);
        $this->assertFalse($context['semester_is_estimate']);
        $this->assertSame('T.A 2026/2027 · Ganjil · Semester 5', $context['summary_label']);
        $this->assertSame('2026-10-20', $context['server_date']);
        $this->assertCount(1, $context['guide_links']);
        $this->assertTrue($context['questionnaire']['available']);
        $this->assertCount(11, $context['sidebar_menu']);
        $this->assertNull($context['notice']);
        $this->assertSame(['data_origin' => 'upstream'], $context['_sync']);
    }

    public function test_it_falls_back_to_the_sipandai_class_list_when_the_schedule_page_fails(): void
    {
        $resolver = $this->makeResolver(
            studentCourse: fn (MockInterface $mock) => $mock->shouldReceive('extractStudentCourseData')->once()
                ->andThrow(new \RuntimeException('Portal timeout')),
            sipandaiClasses: fn (MockInterface $mock) => $mock->shouldReceive('extractSipandaiClassesData')->once()->with(self::SESSION)
                ->andReturn(['semester_info' => ['semester' => '3'], '_sync' => ['data_origin' => 'upstream']]),
            profile: fn (MockInterface $mock) => $mock->shouldNotReceive('extractProfileData'),
        );

        $context = $resolver->resolve(self::SESSION);

        $this->assertSame(3, $context['semester_number']);
        $this->assertSame('sipandai_classes', $context['semester_source']);
        $this->assertSame(['data_origin' => 'upstream'], $context['_sync']);
    }

    public function test_a_schedule_page_without_a_semester_falls_through_to_sipandai(): void
    {
        $resolver = $this->makeResolver(
            studentCourse: fn (MockInterface $mock) => $mock->shouldReceive('extractStudentCourseData')->once()
                ->andReturn(['semester_info' => [], '_sync' => ['data_origin' => 'upstream']]),
            sipandaiClasses: fn (MockInterface $mock) => $mock->shouldReceive('extractSipandaiClassesData')->once()
                ->andReturn(['semester_info' => ['semester' => '4'], '_sync' => ['data_origin' => 'upstream']]),
            profile: fn (MockInterface $mock) => $mock->shouldNotReceive('extractProfileData'),
        );

        $context = $resolver->resolve(self::SESSION);

        $this->assertSame(4, $context['semester_number']);
        $this->assertSame('sipandai_classes', $context['semester_source']);
    }

    public function test_it_labels_a_count_from_the_intake_year_as_an_estimate(): void
    {
        $resolver = $this->makeResolver(
            studentCourse: fn (MockInterface $mock) => $mock->shouldReceive('extractStudentCourseData')->once()
                ->andReturn(['semester_info' => [], '_sync' => ['data_origin' => 'upstream']]),
            sipandaiClasses: fn (MockInterface $mock) => $mock->shouldReceive('extractSipandaiClassesData')->once()
                ->andThrow(new \RuntimeException('SIPANDAI down')),
            profile: fn (MockInterface $mock) => $mock->shouldReceive('extractProfileData')->once()->with(self::SESSION)
                ->andReturn(['personal_data' => ['angkatan' => '2025'], '_sync' => ['data_origin' => 'upstream']]),
        );

        $context = $resolver->resolve(self::SESSION);

        $this->assertSame(3, $context['semester_number']);
        $this->assertSame(PortalContextResolver::ESTIMATE_SOURCE, $context['semester_source']);
        $this->assertTrue($context['semester_is_estimate']);
        $this->assertSame('Semester 3 (perkiraan)', $context['semester_label']);
        $this->assertSame('T.A 2026/2027 · Ganjil · Semester 3 (perkiraan)', $context['summary_label']);
    }

    public function test_it_leaves_the_semester_empty_when_no_source_answers(): void
    {
        $resolver = $this->makeResolver(
            studentCourse: fn (MockInterface $mock) => $mock->shouldReceive('extractStudentCourseData')->once()
                ->andThrow(new \RuntimeException('Portal timeout')),
            sipandaiClasses: fn (MockInterface $mock) => $mock->shouldReceive('extractSipandaiClassesData')->once()
                ->andThrow(new \RuntimeException('Portal timeout')),
            profile: fn (MockInterface $mock) => $mock->shouldReceive('extractProfileData')->once()
                ->andThrow(new \RuntimeException('Portal timeout')),
        );

        $context = $resolver->resolve(self::SESSION);

        $this->assertNull($context['semester_number']);
        $this->assertNull($context['semester_source']);
        $this->assertFalse($context['semester_is_estimate']);
        $this->assertNull($context['semester_label']);
        $this->assertSame('T.A 2026/2027 · Ganjil', $context['summary_label']);
        $this->assertSame('2026/2027', $context['academic_period']['academic_year']);
    }

    public function test_an_expired_session_is_not_swallowed_by_the_semester_fallbacks(): void
    {
        $resolver = $this->makeResolver(
            studentCourse: fn (MockInterface $mock) => $mock->shouldReceive('extractStudentCourseData')->once()
                ->andThrow(PortalScrapingException::pageNotAccessible('https://portal.example.test/')),
            sipandaiClasses: fn (MockInterface $mock) => $mock->shouldNotReceive('extractSipandaiClassesData'),
            profile: fn (MockInterface $mock) => $mock->shouldNotReceive('extractProfileData'),
        );

        $this->expectException(PortalScrapingException::class);
        $this->expectExceptionCode(403);

        $resolver->resolve(self::SESSION);
    }

    public function test_a_dashboard_failure_propagates(): void
    {
        $resolver = $this->makeResolver(
            portalContext: fn (MockInterface $mock) => $mock->shouldReceive('extractPortalContextData')->once()
                ->andThrow(PortalScrapingException::dataExtractionFailed('portal context', 'empty shell')),
            studentCourse: fn (MockInterface $mock) => $mock->shouldNotReceive('extractStudentCourseData'),
        );

        $this->expectException(PortalScrapingException::class);
        $this->expectExceptionMessage('portal context');

        $resolver->resolve(self::SESSION);
    }

    public function test_compose_follows_the_portal_source_order_and_logs_disagreement(): void
    {
        Log::spy();

        $context = $this->makeResolver()->compose($this->portalContext(), [
            'student_course' => null,
            'sipandai_classes' => 'T',
            'exam_page' => 'Semester : 5',
            'krs_print' => '4',
            'krs_history' => ['1', '2', '3', '4'],
            'intake_year' => '2025',
        ]);

        $this->assertSame(5, $context['semester_number']);
        $this->assertSame('exam_page', $context['semester_source']);
        $this->assertFalse($context['semester_is_estimate']);

        Log::shouldHaveReceived('info')->once()->with('Portal semester sources disagree', [
            'candidates' => ['exam_page' => 5, 'krs_print' => 4, 'krs_history' => 4],
        ]);
    }

    public function test_compose_takes_the_highest_regular_krs_history_semester_and_ignores_t(): void
    {
        $context = $this->makeResolver()->compose($this->portalContext(), [
            'krs_history' => ['1', '2', '3', 'T', '', null, 'Semester 99'],
        ]);

        $this->assertSame(3, $context['semester_number']);
        $this->assertSame('krs_history', $context['semester_source']);
    }

    public function test_compose_prefers_any_portal_source_over_the_intake_year(): void
    {
        $context = $this->makeResolver()->compose($this->portalContext(), [
            'krs_print' => 3,
            'intake_year' => '2020',
        ]);

        $this->assertSame(3, $context['semester_number']);
        $this->assertSame('krs_print', $context['semester_source']);
        $this->assertFalse($context['semester_is_estimate']);
    }

    public function test_compose_estimates_an_even_term_and_rejects_implausible_counts(): void
    {
        $resolver = $this->makeResolver();
        $evenTerm = [...$this->portalContext(), 'academic_period' => [
            'label' => 'T.A 2025/2026 Semester Genap',
            'academic_year' => '2025/2026',
            'term' => 'genap',
            'source' => 'dashboard',
        ]];

        $this->assertSame(2, $resolver->compose($evenTerm, ['intake_year' => '2025'])['semester_number']);
        $this->assertSame(2, $resolver->compose($evenTerm, ['intake_year' => 2025])['semester_number']);
        $this->assertNull($resolver->compose($evenTerm, ['intake_year' => '2030'])['semester_number']);
        $this->assertNull($resolver->compose($evenTerm, ['intake_year' => 'tidak diketahui'])['semester_number']);

        $unknownTerm = [...$this->portalContext(), 'academic_period' => [
            'label' => 'T.A 2026/2027',
            'academic_year' => '2026/2027',
            'term' => null,
            'source' => 'dashboard',
        ]];

        $this->assertNull($resolver->compose($unknownTerm, ['intake_year' => '2025'])['semester_number']);
    }

    public function test_compose_without_an_academic_period_has_no_summary_or_estimate(): void
    {
        $context = $this->makeResolver()->compose(
            [...$this->portalContext(), 'academic_period' => null],
            ['intake_year' => '2025']
        );

        $this->assertNull($context['semester_number']);
        $this->assertNull($context['summary_label']);
    }

    public function test_compose_uses_a_fallback_notice_only_when_the_dashboard_has_none(): void
    {
        $resolver = $this->makeResolver();
        $dashboardNotice = ['text' => 'Pastikan Status Absensi Kehadiran Saudara di Sistem SIPANDAI', 'dates' => [], 'is_future' => false, 'kind' => 'attendance'];
        $fallbackNotice = ['text' => 'Pembayaran paling lambat 26 Oktober 2026.', 'dates' => ['2026-10-26'], 'is_future' => true, 'kind' => 'payment'];

        $this->assertSame($fallbackNotice, $resolver->compose($this->portalContext(), [], $fallbackNotice)['notice']);
        $this->assertSame(
            $dashboardNotice,
            $resolver->compose([...$this->portalContext(), 'notice' => $dashboardNotice], [], $fallbackNotice)['notice']
        );
        $this->assertNull($resolver->compose($this->portalContext(), [], ['dates' => []])['notice']);
    }

    /**
     * @return array<string, mixed>
     */
    private function portalContext(): array
    {
        return [
            'academic_period' => [
                'label' => 'T.A 2026/2027 Semester Ganjil',
                'academic_year' => '2026/2027',
                'term' => 'ganjil',
                'source' => 'dashboard',
            ],
            'server_date' => '2026-10-12',
            'server_date_label' => 'Senin, 12 Oktober 2026',
            'guide_links' => [
                [
                    'label' => 'Panduan Her-registrasi Online',
                    'path' => 'files/Panduan_Her-registrasi-KRS_dan_Perwalian.pdf',
                    'filename' => 'Panduan_Her-registrasi-KRS_dan_Perwalian.pdf',
                ],
            ],
            'questionnaire' => [
                'available' => true,
                'title' => 'Kuesioner Pemahaman VMTS Untuk Tahun Akademik 2026/2027',
                'academic_year' => '2026/2027',
                'description' => 'Silakan saudara mengisi kuesioner.',
                'details' => [],
            ],
            'notice' => null,
            'sidebar_menu' => [
                ['label' => 'Dashboard', 'href_act' => 'dashboard'],
                ['label' => 'SiPANDAI', 'href_act' => null],
            ],
            'extracted_at' => '2026-10-12T02:00:00.000000Z',
        ];
    }

    /**
     * @param  (callable(MockInterface): mixed)|null  $portalContext
     * @param  (callable(MockInterface): mixed)|null  $studentCourse
     * @param  (callable(MockInterface): mixed)|null  $sipandaiClasses
     * @param  (callable(MockInterface): mixed)|null  $profile
     */
    private function makeResolver(
        ?callable $portalContext = null,
        ?callable $studentCourse = null,
        ?callable $sipandaiClasses = null,
        ?callable $profile = null
    ): PortalContextResolver {
        $portalContext ??= fn (MockInterface $mock) => $mock->shouldReceive('extractPortalContextData')->with(self::SESSION)
            ->andReturn([...$this->portalContext(), '_sync' => ['data_origin' => 'upstream', 'requested_fresh' => false, 'synced_at' => '2026-10-12T02:00:00.000000Z']]);

        return new PortalContextResolver(
            Mockery::mock(PortalContextExtractor::class, $portalContext),
            Mockery::mock(StudentCourseExtractor::class, $studentCourse ?? static fn (): null => null),
            Mockery::mock(SipandaiClassesExtractor::class, $sipandaiClasses ?? static fn (): null => null),
            Mockery::mock(ProfileExtractor::class, $profile ?? static fn (): null => null),
        );
    }
}
