<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Jobs\RefreshPortalDataJob;
use App\Services\ActionCenterBuilder;
use App\Services\PortalDatasetRepository;
use App\Services\PortalFinanceStatusResolver;
use App\Services\PortalSnapshotStore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class ActionCenterBuilderTest extends TestCase
{
    private const TOKEN = 'campus-token-action';

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake([RefreshPortalDataJob::class]);
        // Tuesday 20 October 2026, 09:00 WIB.
        $this->travelTo(CarbonImmutable::create(2026, 10, 20, 9, 0, 0, 'Asia/Jakarta'));
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_krs_waiting_for_dpa_becomes_critical_near_the_end_of_the_period(): void
    {
        $this->seedSnapshots([
            'krs' => $this->krs('pending_dpa', [['kind' => 'perwalian_perbaikan', 'start' => '2026-10-01', 'end' => '2026-10-22']]),
            'advising' => $this->advising(),
        ]);

        $item = $this->item($this->build(), 'krs-pending-dpa');

        $this->assertSame('krs_validation', $item['type']);
        $this->assertSame('critical', $item['severity']);
        $this->assertSame('KRS belum divalidasi DPA · 2 hari lagi', $item['title']);
        $this->assertSame('2026-10-22', $item['due_at']);
        $this->assertSame(2, $item['days_left']);
        $this->assertSame('/krs', $item['cta_url']);
        $this->assertStringContainsString('Dosen Pembimbing Uji', (string) $item['description']);
        $this->assertStringContainsString('Kamis, 22 Oktober 2026', (string) $item['description']);
        // The canonical DPA contact comes from Perwalian, not the credit page.
        $this->assertSame('6281200000001', $item['contact']['whatsapp']);
    }

    public function test_krs_waiting_for_dpa_with_time_left_is_a_warning(): void
    {
        $this->seedSnapshots([
            'krs' => $this->krs('pending_dpa', [['kind' => 'perwalian_perbaikan', 'start' => '2026-10-01', 'end' => '2026-11-10']]),
        ]);

        $item = $this->item($this->build(), 'krs-pending-dpa');

        $this->assertSame('warning', $item['severity']);
        $this->assertSame(21, $item['days_left']);
        // Without an advising snapshot the credit page contact is the fallback.
        $this->assertSame('Dosen DPA Kredit', $item['contact']['name']);
    }

    public function test_krs_waiting_for_dpa_after_the_period_closed_has_no_due_date(): void
    {
        $this->seedSnapshots([
            'krs' => $this->krs('pending_dpa', [['kind' => 'perwalian_perbaikan', 'start' => '2026-09-01', 'end' => '2026-09-30']]),
        ]);

        $item = $this->item($this->build(), 'krs-pending-dpa');

        $this->assertSame('warning', $item['severity']);
        $this->assertNull($item['due_at']);
        $this->assertSame('KRS belum divalidasi DPA', $item['title']);
    }

    public function test_unsubmitted_krs_is_only_flagged_while_a_krs_period_is_open(): void
    {
        $announcements = $this->announcements([
            $this->announcement('a1', 'Pengisian Her-Registrasi dan KRS Ganjil', [
                ['date' => '2026-10-21', 'start' => '2026-10-19', 'type' => 'period', 'context' => 'Pengisian 19 s/d 21 Oktober 2026'],
            ]),
        ]);

        $this->seedSnapshots([
            'krs' => $this->krs('not_submitted', [['kind' => 'perwalian_perbaikan', 'start' => '2026-10-01', 'end' => '2026-11-30']]),
            'announcements' => $announcements,
        ]);

        $item = $this->item($this->build(), 'krs-not-submitted');

        // The short her-registrasi window from the announcement wins over the long advising period.
        $this->assertSame('2026-10-21', $item['due_at']);
        $this->assertSame('critical', $item['severity']);
        $this->assertSame('KRS belum diajukan · 1 hari lagi', $item['title']);

        $this->seedSnapshots([
            'krs' => $this->krs('not_submitted', [['kind' => 'perwalian_perbaikan', 'start' => '2026-08-01', 'end' => '2026-08-30']]),
            'announcements' => $this->announcements([]),
        ]);

        $this->assertNull($this->findItem($this->build(), 'krs-not-submitted'));
    }

    public function test_validated_or_unreadable_krs_needs_no_action(): void
    {
        $this->seedSnapshots(['krs' => $this->krs('validated', [['kind' => 'perwalian_perbaikan', 'start' => '2026-10-01', 'end' => '2026-10-22']])]);
        $this->assertSame([], $this->itemsOfSource($this->build(), 'krs'));

        $this->seedSnapshots(['krs' => [...$this->krs('pending_dpa', []), 'found' => false]]);
        $this->assertSame([], $this->itemsOfSource($this->build(), 'krs'));
    }

    public function test_outstanding_bill_names_the_bill_and_the_portal_deadline(): void
    {
        $this->seedSnapshots([
            'payments' => $this->payments('outstanding'),
            'announcements' => [
                ...$this->announcements([]),
                'notice' => [
                    'text' => 'Kewajiban Pembayaran Kuliah Tahap ke 2 paling lambat pada Hari Senin, 26 Oktober 2026',
                    'dates' => ['2026-10-26'],
                    'is_future' => true,
                    'kind' => 'payment',
                ],
            ],
        ]);

        $item = $this->item($this->build(), 'bills-outstanding');

        $this->assertSame('bill_outstanding', $item['type']);
        $this->assertSame('Tagihan belum lunas: Rp 1.250.000', $item['title']);
        $this->assertSame('critical', $item['severity']);
        $this->assertSame('2026-10-26', $item['due_at']);
        $this->assertSame(6, $item['days_left']);
        $this->assertStringContainsString('UKT Tahap 2 tahun ke-1', (string) $item['description']);
        $this->assertSame('/finance', $item['cta_url']);
        $this->assertNull($this->findItem($this->build(), 'payment-deadline-2026-10-26'));
    }

    public function test_unreadable_bill_table_never_becomes_an_item_or_rp_zero(): void
    {
        $this->seedSnapshots(['payments' => $this->payments('unknown')]);

        $result = $this->build();

        $this->assertSame([], $this->itemsOfSource($result, 'payments'));
        $this->assertStringNotContainsString('Rp 0', (string) json_encode($result));
    }

    public function test_payment_deadline_is_shown_only_while_ahead_and_unpaid(): void
    {
        $notice = [
            'text' => 'Kewajiban Pembayaran Kuliah Tahap ke 2 paling lambat pada Hari Senin, 2 November 2026',
            'dates' => ['2026-11-02'],
            'is_future' => true,
            'kind' => 'payment',
        ];

        $this->seedSnapshots([
            'payments' => $this->payments('clear'),
            'portal_context' => $this->portalContext(['notice' => $notice]),
        ]);

        $item = $this->item($this->build(), 'payment-deadline-2026-11-02');
        $this->assertSame('payment_deadline', $item['type']);
        $this->assertSame('Batas bayar UKT tahap 2 · 13 hari lagi', $item['title']);
        $this->assertSame('warning', $item['severity']);

        // Stage 2 of the current year already recorded as paid: no reminder.
        $this->seedSnapshots([
            'payments' => $this->payments('clear', paidStages: [1, 2]),
            'portal_context' => $this->portalContext(['notice' => $notice]),
        ]);
        $this->assertNull($this->findItem($this->build(), 'payment-deadline-2026-11-02'));

        // A stale marquee with a past date is ignored.
        $this->seedSnapshots([
            'payments' => $this->payments('clear'),
            'portal_context' => $this->portalContext(['notice' => [...$notice, 'dates' => ['2026-09-01']]]),
        ]);
        $this->assertSame([], $this->itemsOfSource($this->build(), 'payments'));
    }

    public function test_skkm_shortfall_is_reported_with_points_and_escalates_near_graduation(): void
    {
        $this->seedSnapshots(['skkm' => $this->skkm(isMet: false)]);

        $item = $this->item($this->build(), 'skkm-graduation');
        $this->assertSame('SKKM kurang 30 poin untuk syarat lulus', $item['title']);
        $this->assertSame('info', $item['severity']);
        $this->assertStringContainsString('Poin valid kamu 70 dari 100.', (string) $item['description']);
        $this->assertStringContainsString('10 poin masih menunggu validasi DPA', (string) $item['description']);

        $this->seedSnapshots([
            'skkm' => $this->skkm(isMet: false),
            'grades' => $this->grades(progressPercent: 80.0),
        ]);
        $this->assertSame('warning', $this->item($this->build(), 'skkm-graduation')['severity']);
    }

    public function test_skkm_met_or_unknown_needs_no_action(): void
    {
        $this->seedSnapshots(['skkm' => $this->skkm(isMet: true)]);
        $this->assertNull($this->findItem($this->build(), 'skkm-graduation'));

        $this->seedSnapshots(['skkm' => $this->skkm(isMet: null)]);
        $this->assertNull($this->findItem($this->build(), 'skkm-graduation'));
    }

    public function test_announcement_deadlines_within_fourteen_days_are_listed_with_recomputed_days(): void
    {
        $this->seedSnapshots([
            'announcements' => $this->announcements([], [
                $this->upcomingDeadline('a-soon', 'Batas pengajuan Semester Pendek', '2026-10-21', 99),
                $this->upcomingDeadline('a-week', 'Perbaikan data PDDikti', '2026-10-27', 99),
                $this->upcomingDeadline('a-later', 'Wisuda', '2026-11-20', 31),
                $this->upcomingDeadline('a-past', 'Pengumuman lama', '2026-10-19', 0),
            ]),
        ]);

        $result = $this->build();
        $soon = $this->item($result, 'announcement-a-soon');

        $this->assertSame(1, $soon['days_left']);
        $this->assertSame('critical', $soon['severity']);
        $this->assertSame('/announcement/a-soon', $soon['cta_url']);
        $this->assertSame('warning', $this->item($result, 'announcement-a-week')['severity']);
        $this->assertNull($this->findItem($result, 'announcement-a-later'));
        $this->assertNull($this->findItem($result, 'announcement-a-past'));
    }

    public function test_vmts_questionnaire_opens_the_campus_portal(): void
    {
        $this->seedSnapshots(['portal_context' => $this->portalContext()]);

        $item = $this->item($this->build(), 'questionnaire-vmts-2026-2027');

        $this->assertSame('Isi Kuesioner VMTS T.A 2026/2027', $item['title']);
        $this->assertTrue($item['cta_external']);
        $this->assertSame(config('portal.login_url'), $item['cta_url']);
    }

    public function test_assignments_due_within_48_hours_and_open_exams_are_listed(): void
    {
        // Now: Tuesday 20 October 2026, 09:00 WIB.
        $this->seedSnapshots([
            'assignments' => ['assignments' => [
                $this->assignment('501', 'Algoritma', '2026-10-20'),
                $this->assignment('502', 'Basis Data', '2026-10-21'),
                $this->assignment('503', 'Jaringan', '2026-10-23'),
                $this->assignment('504', 'Statistika', '2026-10-19'),
                $this->assignment('505', 'Tanpa Tenggat', null),
                // Passed earlier today: gone, even though the date is today.
                $this->assignment('506', 'Pemrograman Web', '2026-10-20', '20 Oktober 2026 08:00'),
                // 23:59 two days ahead is about 63 hours away: not within 48 hours yet.
                $this->assignment('507', 'Sistem Operasi', '2026-10-22', '22 Oktober 2026 23:59'),
                // 47.5 hours away: within the window, but not critical.
                $this->assignment('508', 'Kecerdasan Buatan', '2026-10-22', 'Kamis, 22 Oktober 2026 pukul 08.30 WIB'),
            ]],
            'exams' => ['exams' => [
                ['id' => 77, 'course_name' => 'Algoritma', 'schedule' => 'Selasa, 20 Oktober 2026 08:00', 'status' => 'Dibuka', 'can_upload' => true],
                ['id' => 78, 'course_name' => 'Basis Data', 'schedule' => '', 'status' => 'Sudah upload', 'can_upload' => true],
                ['id' => 79, 'course_name' => 'Jaringan', 'schedule' => '', 'status' => 'Terjadwal', 'can_upload' => false],
            ], 'source' => 'exam_page'],
        ]);

        $result = $this->build();

        $this->assertSame('critical', $this->item($result, 'assignment-501')['severity']);
        $this->assertSame('/meeting/501?courses_id=9001', $this->item($result, 'assignment-501')['cta_url']);
        $this->assertSame(0, $this->item($result, 'assignment-501')['days_left']);
        $this->assertSame('warning', $this->item($result, 'assignment-502')['severity']);
        $this->assertNull($this->findItem($result, 'assignment-503'));
        $this->assertNull($this->findItem($result, 'assignment-504'));
        $this->assertNull($this->findItem($result, 'assignment-505'));
        $this->assertNull($this->findItem($result, 'assignment-506'));
        $this->assertNull($this->findItem($result, 'assignment-507'));

        $nearWindowEnd = $this->item($result, 'assignment-508');
        $this->assertSame('warning', $nearWindowEnd['severity']);
        $this->assertSame(2, $nearWindowEnd['days_left']);
        $this->assertStringContainsString('pukul 08:30', (string) $nearWindowEnd['description']);

        $exam = $this->item($result, 'exam-77');
        $this->assertSame('critical', $exam['severity']);
        $this->assertSame('/exam/77', $exam['cta_url']);
        $this->assertNull($this->findItem($result, 'exam-78'));
        $this->assertNull($this->findItem($result, 'exam-79'));
    }

    public function test_d_and_e_courses_raise_an_informative_grade_alert(): void
    {
        $this->seedSnapshots(['grades' => $this->grades(dCourses: [
            ['code' => 'MK1004', 'name' => 'Matematika Diskrit', 'letter' => 'D'],
        ], eCourses: [
            ['code' => 'MK2001', 'name' => 'Fisika Dasar', 'letter' => 'E'],
        ])]);

        $item = $this->item($this->build(), 'grades-low');

        $this->assertSame('info', $item['severity']);
        $this->assertSame('2 MK bernilai D/E', $item['title']);
        $this->assertStringContainsString('Matematika Diskrit (D) dan Fisika Dasar (E)', (string) $item['description']);
        $this->assertSame('/transcript', $item['cta_url']);

        $this->seedSnapshots(['grades' => $this->grades()]);
        $this->assertNull($this->findItem($this->build(), 'grades-low'));
    }

    public function test_items_are_sorted_by_severity_then_due_date_and_capped_at_twenty(): void
    {
        $assignments = [];

        for ($index = 0; $index < 22; $index++) {
            // Even ones due tonight (critical), odd ones tomorrow night (warning).
            $assignments[] = $this->assignment((string) (600 + $index), "MK {$index}", $index % 2 === 0 ? '2026-10-20' : '2026-10-21');
        }

        $this->seedSnapshots([
            'assignments' => ['assignments' => $assignments],
            'grades' => $this->grades(dCourses: [['code' => 'MK1004', 'name' => 'Matematika Diskrit', 'letter' => 'D']]),
            'portal_context' => $this->portalContext(),
        ]);

        $result = $this->build();
        $severityRank = ['critical' => 0, 'warning' => 1, 'info' => 2];
        $ranks = array_map(static fn (array $item): int => $severityRank[$item['severity']], $result['items']);

        $this->assertCount(ActionCenterBuilder::MAX_ITEMS, $result['items']);
        $this->assertSame(24, $result['counts']['total']);
        $this->assertSame(20, $result['counts']['shown']);
        $this->assertSame(11, $result['counts']['critical']);
        $this->assertSame(1, $result['counts']['info']);
        $sortedRanks = $ranks;
        sort($sortedRanks);
        $this->assertSame($sortedRanks, $ranks);
        $this->assertSame('2026-10-20', $result['items'][0]['due_at']);
        $this->assertSame(array_values(array_unique(array_column($result['items'], 'id'))), array_column($result['items'], 'id'));

        $warnings = array_values(array_filter($result['items'], static fn (array $item): bool => $item['severity'] === 'warning'));
        // Dated warnings come before the undated questionnaire.
        $this->assertSame('2026-10-21', $warnings[0]['due_at']);
    }

    public function test_missing_sources_are_queued_once_and_reported_as_pending(): void
    {
        $result = $this->build();

        $this->assertSame([], $result['items']);
        $this->assertSame(ActionCenterBuilder::QUEUED_SOURCES, $result['meta']['pending_sources']);
        $this->assertContains('exams', $result['meta']['pending_sources']);
        $this->assertSame('missing', $result['meta']['sources']['assignments']['status']);
        $this->assertSame(['assignments'], $result['meta']['missing_sources']);
        $this->assertFalse($result['meta']['is_complete']);

        // One job for every missing source, so its scope shares the pages they have in common.
        Bus::assertDispatchedTimes(RefreshPortalDataJob::class, 1);
        Bus::assertDispatched(RefreshPortalDataJob::class, fn (RefreshPortalDataJob $job): bool => $this->jobDomains($job) === ActionCenterBuilder::QUEUED_SOURCES);

        // A second build while the background build is pending queues nothing new.
        $this->assertSame(ActionCenterBuilder::QUEUED_SOURCES, $this->build()['meta']['pending_sources']);
        Bus::assertDispatchedTimes(RefreshPortalDataJob::class, 1);
    }

    public function test_stale_snapshot_is_used_and_resynced_in_the_background(): void
    {
        $this->seedSnapshots(['krs' => $this->krs('pending_dpa', [])]);
        $this->travel(2)->hours();

        $result = $this->build();

        $this->assertNotNull($this->findItem($result, 'krs-pending-dpa'));
        $this->assertContains('krs', $result['meta']['syncing_sources']);
        $this->assertSame('syncing', $result['meta']['sources']['krs']['status']);
        // The stale snapshot rides along with the missing ones: still one job.
        Bus::assertDispatchedTimes(RefreshPortalDataJob::class, 1);
        Bus::assertDispatched(RefreshPortalDataJob::class, fn (RefreshPortalDataJob $job): bool => in_array('krs', $this->jobDomains($job), true));
    }

    public function test_every_stale_source_is_resynced_in_one_job_and_assignments_are_only_reused(): void
    {
        $this->seedSnapshots([
            'krs' => $this->krs('validated', []),
            'advising' => $this->advising(),
            'payments' => $this->payments('clear'),
            'skkm' => ['found' => true, 'graduation_status' => ['is_met' => true]],
            'announcements' => ['items' => [], 'upcoming_deadlines' => []],
            'portal_context' => ['questionnaire' => ['available' => false]],
            'grades' => $this->grades(),
            'exams' => ['exams' => []],
            'assignments' => ['assignments' => []],
        ]);
        $this->travel(7)->hours();

        $result = $this->build();

        Bus::assertDispatchedTimes(RefreshPortalDataJob::class, 1);
        Bus::assertDispatched(RefreshPortalDataJob::class, fn (RefreshPortalDataJob $job): bool => $this->jobDomains($job) === ActionCenterBuilder::QUEUED_SOURCES);
        // The heavy class + meeting crawl is never started from the action center.
        $this->assertSame('ready', $result['meta']['sources']['assignments']['status']);
        $this->assertNotContains('assignments', $result['meta']['syncing_sources']);

        // Repeated reads while that job runs queue nothing more.
        $this->build();
        Bus::assertDispatchedTimes(RefreshPortalDataJob::class, 1);
    }

    public function test_a_stale_assignments_snapshot_alone_queues_nothing(): void
    {
        $this->seedSnapshots(['assignments' => ['assignments' => []]]);
        $this->travel(11)->minutes();

        foreach (ActionCenterBuilder::QUEUED_SOURCES as $source) {
            app(PortalSnapshotStore::class)->put(self::TOKEN, $source, ['found' => true]);
        }

        $this->build();

        Bus::assertNotDispatched(RefreshPortalDataJob::class);
    }

    public function test_a_failing_rule_is_skipped_and_reported_without_hiding_other_items(): void
    {
        $this->instance(
            PortalFinanceStatusResolver::class,
            Mockery::mock(PortalFinanceStatusResolver::class, function (MockInterface $mock): void {
                $mock->shouldReceive('summarize')->andThrow(new \RuntimeException('unexpected payments shape'));
            })
        );

        $this->seedSnapshots([
            'payments' => $this->payments('outstanding'),
            'portal_context' => $this->portalContext(),
        ]);

        $result = $this->build();

        $this->assertContains('payments', $result['meta']['failed_sources']);
        $this->assertNotNull($this->findItem($result, 'questionnaire-vmts-2026-2027'));
        $this->assertFalse($result['meta']['is_complete']);
    }

    public function test_an_unreadable_source_is_reported_as_failed(): void
    {
        $real = app(PortalDatasetRepository::class);
        $this->instance(
            PortalDatasetRepository::class,
            Mockery::mock(PortalDatasetRepository::class, function (MockInterface $mock) use ($real): void {
                $mock->shouldReceive('peekMany')->andReturnUsing(function (string $token, array $datasets, ?string $sessionId, ?array $queue) use ($real): array {
                    $reads = $real->peekMany($token, array_values(array_diff($datasets, ['skkm'])), $sessionId, $queue);
                    $reads['skkm'] = ['data' => null, 'meta' => ['status' => 'failed', 'synced_at' => null]];

                    return $reads;
                });
            })
        );

        $this->seedSnapshots(['portal_context' => $this->portalContext()]);

        $result = $this->build();

        $this->assertSame('failed', $result['meta']['sources']['skkm']['status']);
        $this->assertContains('skkm', $result['meta']['failed_sources']);
        $this->assertNotNull($this->findItem($result, 'questionnaire-vmts-2026-2027'));
    }

    public function test_items_never_carry_write_effect_portal_links(): void
    {
        $this->seedSnapshots([
            'krs' => $this->krs('pending_dpa', [['kind' => 'perwalian_perbaikan', 'start' => '2026-10-01', 'end' => '2026-10-22']]),
            'payments' => $this->payments('outstanding'),
            'skkm' => $this->skkm(isMet: false),
            'portal_context' => $this->portalContext(),
            'exams' => ['exams' => [['id' => 77, 'course_name' => 'Algoritma', 'schedule' => '', 'status' => '', 'can_upload' => true, 'upload_url' => 'https://my.cic.ac.id/portal/sipandai/?mod=sipandai&act=sendujian&id=77']]],
        ]);

        $json = (string) json_encode($this->build());

        $this->assertDoesNotMatchRegularExpression('/act=(?:jobdel|jobdelujian|kesesuaian|sendujian|sentujian)/', $json);
    }

    /**
     * @return array<string, mixed>
     */
    private function build(): array
    {
        return app(ActionCenterBuilder::class)->build(self::TOKEN, 'laravel-session');
    }

    /**
     * @param  array<string, array<string, mixed>>  $snapshots
     */
    private function seedSnapshots(array $snapshots): void
    {
        $store = app(PortalSnapshotStore::class);

        foreach (ActionCenterBuilder::SOURCES as $dataset) {
            $store->forget(self::TOKEN, $dataset);
        }

        foreach ($snapshots as $dataset => $data) {
            $store->put(self::TOKEN, $dataset, $data);
        }
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function item(array $result, string $id): array
    {
        $item = $this->findItem($result, $id);
        $this->assertNotNull($item, "Action item [{$id}] is missing; got: ".implode(', ', array_column($result['items'], 'id')));

        return $item;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>|null
     */
    private function findItem(array $result, string $id): ?array
    {
        foreach ($result['items'] as $item) {
            if ($item['id'] === $id) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return list<array<string, mixed>>
     */
    private function itemsOfSource(array $result, string $source): array
    {
        return array_values(array_filter($result['items'], static fn (array $item): bool => $item['source'] === $source));
    }

    /**
     * @return list<string>
     */
    private function jobDomains(RefreshPortalDataJob $job): array
    {
        $property = (new \ReflectionClass($job))->getProperty('domains');

        return $property->getValue($job);
    }

    /**
     * @param  list<array{kind: string, start: string, end: string}>  $periods
     * @return array<string, mixed>
     */
    private function krs(string $status, array $periods): array
    {
        return [
            'found' => true,
            'academic_period' => ['label' => 'T.A 2026/2027 Semester Ganjil', 'academic_year' => '2026/2027', 'term' => 'ganjil', 'source' => 'credit'],
            'periods' => array_map(static fn (array $period): array => [...$period, 'label' => 'Perwalian & perbaikan KRS', 'source' => 'credit', 'source_ref' => 'table_row'], $periods),
            'status' => ['code' => $status, 'label' => 'Status uji', 'raw_text' => 'Belum Validasi DPA'],
            'dpa' => ['name' => 'Dosen DPA Kredit', 'nidn' => null, 'phone' => '081200000002', 'whatsapp' => '6281200000002', 'email' => null],
            'courses' => [['code' => 'MK1001', 'name' => 'Algoritma', 'sks' => 3, 'semester' => 3]],
            'totals' => ['courses' => 6, 'sks' => 14, 'source' => 'portal', 'consistent' => true],
            'print_available' => true,
            'registration_form_detected' => false,
            'registration_form' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function advising(): array
    {
        return [
            'found' => true,
            'dpa' => ['name' => 'Dosen Pembimbing Uji', 'nidn' => '0000000001', 'phone' => '081200000001', 'whatsapp' => '6281200000001', 'email' => 'dosen@example.test'],
            'krs_history' => [['krs_id' => 90004, 'academic_year' => '2026/2027', 'date' => '2026-09-14', 'semester' => 3, 'semester_is_regular' => true, 'semester_label' => 'Semester 3']],
            'latest_krs_id' => 90004,
            'latest_semester_number' => 3,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  list<array<string, mixed>>  $upcoming
     * @return array<string, mixed>
     */
    private function announcements(array $items, array $upcoming = []): array
    {
        return [
            'items' => $items,
            'paging' => ['pages_loaded' => 1, 'last_page' => 1, 'total' => count($items), 'has_more' => false, 'next_page' => null, 'complete' => true, 'stopped_at_known' => false],
            'notice' => null,
            'upcoming_deadlines' => $upcoming,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $deadlines
     * @return array<string, mixed>
     */
    private function announcement(string $id, string $title, array $deadlines): array
    {
        return [
            'id' => $id,
            'title' => $title,
            'category' => 'krs',
            'category_label' => 'KRS & Her-Registrasi',
            'deadlines' => $deadlines,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function upcomingDeadline(string $id, string $title, string $date, int $staleDaysLeft): array
    {
        return [
            'announcement_id' => $id,
            'title' => $title,
            'category' => 'akademik',
            'category_label' => 'Akademik',
            'date' => $date,
            'start' => null,
            'type' => 'deadline',
            'context' => "{$title} paling lambat",
            'days_left' => $staleDaysLeft,
            'is_ongoing' => false,
        ];
    }

    /**
     * @param  list<int>  $paidStages
     * @return array<string, mixed>
     */
    private function payments(string $status, array $paidStages = [1]): array
    {
        $outstanding = $status === 'outstanding';

        return [
            'student_info' => ['academic_year' => '2026/2027'],
            'payment_history' => array_map(static fn (int $stage): array => [
                'no' => (string) $stage,
                'paid_at' => sprintf('2026-%02d-05', 8 + $stage),
                'paid_at_label' => 'Tanggal uji',
                'category' => 'Pembayaran UKT',
                'amount' => 1250000,
                'installment_stage' => $stage,
                'study_year' => 1,
                'installment_amount' => 1250000,
            ], $paidStages),
            'summary' => ['total_paid_amount' => 1250000 * count($paidStages), 'history_available' => true, 'latest_payment' => null],
            'outstanding_bills' => $outstanding ? [[
                'no' => '1',
                'name' => 'UKT Tahap 2 tahun ke-1',
                'amount' => 1250000,
                'amount_label' => 'Rp 1.250.000',
                'description' => null,
                'installment_stage' => 2,
                'study_year' => 1,
            ]] : [],
            'outstanding_summary' => match ($status) {
                'outstanding' => ['status' => 'outstanding', 'has_outstanding' => true, 'total_amount' => 1250000, 'total_amount_label' => 'Rp 1.250.000', 'count' => 1, 'rows_sum_matches_total' => true],
                'clear' => ['status' => 'clear', 'has_outstanding' => false, 'total_amount' => 0, 'total_amount_label' => 'Rp 0', 'count' => 0, 'rows_sum_matches_total' => true],
                default => ['status' => 'unknown', 'has_outstanding' => null, 'total_amount' => null, 'total_amount_label' => null, 'count' => 0, 'rows_sum_matches_total' => null],
            },
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function skkm(?bool $isMet): array
    {
        return [
            'found' => true,
            'stats' => ['required_points' => 100, 'required_points_source' => 'config'],
            'graduation_status' => ['text' => $isMet === false ? 'Belum Memenuhi' : 'Tidak terbaca', 'is_met' => $isMet],
            'entries' => [],
            'summary' => ['valid_points' => 70, 'pending_points' => 10, 'invalid_points' => 0, 'remaining_points' => 30, 'progress_percent' => 70.0],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function portalContext(array $overrides = []): array
    {
        return [
            'academic_period' => ['label' => 'T.A 2026/2027 Semester Ganjil', 'academic_year' => '2026/2027', 'term' => 'ganjil', 'source' => 'dashboard'],
            'questionnaire' => ['available' => true, 'title' => 'Kuesioner VMTS', 'academic_year' => '2026/2027', 'description' => null, 'details' => []],
            'notice' => null,
            ...$overrides,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $dCourses
     * @param  list<array<string, mixed>>  $eCourses
     * @return array<string, mixed>
     */
    private function grades(array $dCourses = [], array $eCourses = [], float $progressPercent = 13.9): array
    {
        return [
            'summary' => [
                'ipk' => 3.1,
                'source' => 'official',
                'sks_passed' => 20,
                'progress' => ['target_sks' => 144, 'earned_sks' => 20, 'remaining_sks' => 124, 'progress_percent' => $progressPercent],
                'computed' => ['d_courses' => $dCourses, 'e_courses' => $eCourses],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function assignment(string $meetingId, string $courseName, ?string $deadlineDate, ?string $deadline = null): array
    {
        return [
            'meeting_id' => $meetingId,
            'course_name' => $courseName,
            'material_title' => 'Tugas pertemuan 6',
            'deadline' => $deadline,
            'deadline_date' => $deadlineDate,
            'meeting_url' => "/meeting/{$meetingId}?courses_id=9001",
        ];
    }
}
