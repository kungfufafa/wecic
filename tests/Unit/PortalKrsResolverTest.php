<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PortalKrsResolver;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class PortalKrsResolverTest extends TestCase
{
    public function test_pending_krs_points_at_the_open_advising_period_and_the_canonical_dpa(): void
    {
        $insights = $this->resolver()->insights(
            $this->krs('pending_dpa', [
                ['kind' => 'perwalian_perbaikan', 'start' => '2026-10-01', 'end' => '2026-10-30'],
            ]),
            ['dpa' => ['name' => 'Dosen Pembimbing Uji', 'nidn' => null, 'phone' => '081200000001', 'whatsapp' => '6281200000001', 'email' => '-'], 'latest_krs_id' => 90004],
            null,
            $this->today()
        );

        $this->assertSame('pending_dpa', $insights['status']['code']);
        $this->assertTrue($insights['is_period_open']);
        $this->assertSame('perwalian_perbaikan', $insights['active_period']['kind']);
        $this->assertSame(10, $insights['active_period']['days_left']);
        $this->assertSame('advising', $insights['dpa_source']);
        $this->assertSame('Dosen Pembimbing Uji', $insights['dpa']['name']);
        $this->assertSame(90004, $insights['latest_krs_id']);
        $this->assertSame(['courses' => 6, 'sks' => 14], $insights['study_load']);
        $this->assertSame(
            ['done', 'done', 'current', 'todo'],
            array_column($insights['tracker'], 'state')
        );
    }

    public function test_unsubmitted_krs_prefers_the_her_registration_window_from_announcements(): void
    {
        $announcements = ['items' => [
            [
                'id' => 'ann-her',
                'title' => 'Pengisian Her-Registrasi dan KRS Semester Ganjil',
                'deadlines' => [
                    ['date' => '2026-10-23', 'start' => '2026-10-19', 'type' => 'period'],
                    ['date' => '2026-10-25', 'start' => null, 'type' => 'deadline'],
                ],
            ],
            [
                'id' => 'ann-old',
                'title' => 'Her-registrasi Semester Genap',
                'deadlines' => [['date' => '2026-03-05', 'start' => '2026-03-01', 'type' => 'period']],
            ],
            [
                'id' => 'ann-other',
                'title' => 'Jadwal Wisuda',
                'deadlines' => [['date' => '2026-10-28', 'start' => '2026-10-26', 'type' => 'period']],
            ],
        ]];

        $insights = $this->resolver()->insights(
            $this->krs('not_submitted', [['kind' => 'perwalian_perbaikan', 'start' => '2026-10-01', 'end' => '2026-11-30']]),
            null,
            $announcements,
            $this->today()
        );

        $this->assertSame('her_registrasi', $insights['active_period']['kind']);
        $this->assertSame('announcement', $insights['active_period']['source']);
        $this->assertSame('ann-her', $insights['active_period']['announcement_id']);
        $this->assertSame(3, $insights['active_period']['days_left']);
        // Past windows from old announcements and unrelated announcements are dropped.
        $this->assertCount(2, $insights['periods']);
        $this->assertSame('credit', $insights['dpa_source']);
        $this->assertSame(['done', 'current', 'todo', 'todo'], array_column($insights['tracker'], 'state'));
    }

    public function test_closed_and_upcoming_periods_are_told_apart(): void
    {
        $insights = $this->resolver()->insights(
            $this->krs('not_submitted', [
                ['kind' => 'perwalian_perbaikan', 'start' => '2026-08-01', 'end' => '2026-08-31'],
                ['kind' => 'krs', 'start' => '2026-11-02', 'end' => '2026-11-06'],
            ]),
            null,
            null,
            $this->today()
        );

        $this->assertFalse($insights['is_period_open']);
        $this->assertNull($insights['active_period']);
        $this->assertSame('krs', $insights['next_period']['kind']);
        $this->assertSame(13, $insights['next_period']['days_until_start']);
        $this->assertTrue($insights['periods'][0]['is_past']);
        $this->assertNull($insights['periods'][0]['days_left']);
        $this->assertSame(['todo', 'todo', 'todo', 'todo'], array_column($insights['tracker'], 'state'));
    }

    public function test_validated_krs_completes_the_tracker(): void
    {
        $insights = $this->resolver()->insights($this->krs('validated', []), null, null, $this->today());

        $this->assertSame(['done', 'done', 'done', 'done'], array_column($insights['tracker'], 'state'));
    }

    public function test_unreadable_krs_page_is_unknown_without_study_load(): void
    {
        $insights = $this->resolver()->insights(
            [...$this->krs('pending_dpa', [['kind' => 'krs', 'start' => 'bukan-tanggal', 'end' => '2026-10-30']]), 'found' => false, 'dpa' => null],
            null,
            null,
            $this->today()
        );

        $this->assertSame('unknown', $insights['status']['code']);
        $this->assertNull($insights['study_load']);
        $this->assertNull($insights['dpa']);
        $this->assertSame([], $insights['periods']);
        $this->assertSame(['unknown', 'unknown', 'unknown', 'unknown'], array_column($insights['tracker'], 'state'));
    }

    private function resolver(): PortalKrsResolver
    {
        return new PortalKrsResolver;
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::create(2026, 10, 20, 9, 0, 0, 'Asia/Jakarta');
    }

    /**
     * @param  list<array{kind: string, start: string, end: string}>  $periods
     * @return array<string, mixed>
     */
    private function krs(string $status, array $periods): array
    {
        return [
            'found' => true,
            'periods' => array_map(static fn (array $period): array => [...$period, 'label' => 'Periode uji', 'source' => 'credit', 'source_ref' => 'table_row'], $periods),
            'status' => ['code' => $status, 'label' => 'Status uji', 'raw_text' => 'Belum Validasi DPA'],
            'dpa' => ['name' => 'Dosen DPA Kredit', 'nidn' => null, 'phone' => null, 'whatsapp' => '6281200000002', 'email' => null],
            'totals' => ['courses' => 6, 'sks' => 14, 'source' => 'portal', 'consistent' => true],
        ];
    }
}
