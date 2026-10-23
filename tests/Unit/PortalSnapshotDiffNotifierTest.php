<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Events\PortalNotificationSent;
use App\Models\PortalNotification;
use App\Services\PortalDataCache;
use App\Services\PortalDataOwner;
use App\Services\PortalNotificationCenter;
use App\Services\PortalSessionManager;
use App\Services\PortalSnapshotDiffNotifier;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Every payload here is synthetic: no real student data in the repository.
 */
class PortalSnapshotDiffNotifierTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'campus-token-first-login';

    private const USERNAME = '20990000001';

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([PortalNotificationSent::class]);
        $this->instance(PortalSessionManager::class, Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('laravelSessionId')->andReturn('laravel-session-1');
        }));
        app(PortalDataOwner::class)->remember(self::TOKEN, self::USERNAME, 3600);
        $this->travelTo(CarbonImmutable::create(2026, 10, 21, 9, 0, 0, 'Asia/Jakarta'));
    }

    protected function tearDown(): void
    {
        Cache::flush();
        Mockery::close();

        parent::tearDown();
    }

    public function test_first_sync_records_a_baseline_without_notifying(): void
    {
        $created = $this->notifier()->process(self::TOKEN, 'announcements', $this->announcements([
            $this->announcement('a1', 'Jadwal UTS Ganjil'),
            $this->announcement('a2', 'Libur Nasional'),
        ]));

        $this->assertSame([], $created);
        $this->assertDatabaseCount('portal_notifications', 0);
        Event::assertNotDispatched(PortalNotificationSent::class);
    }

    public function test_krs_validation_by_dpa_is_announced_once(): void
    {
        $created = $this->syncSequence('krs', $this->krs('pending_dpa'), $this->krs('validated'), $this->krs('validated'));

        $this->assertCount(1, $created);
        $this->assertSame('krs_validated', $created[0]->type);
        $this->assertSame('KRS sudah divalidasi DPA', $created[0]->title);
        $this->assertSame('KRS T.A 2026/2027 Semester Ganjil sudah disetujui dosen pembimbing akademik.', $created[0]->body);
        $this->assertSame('/krs', $created[0]->url);
        $this->assertSame('info', $created[0]->urgency);
        $this->assertSame('krs', $created[0]->dataset);
        $this->assertSame(app(PortalDataOwner::class)->keyForUsername(self::USERNAME), $created[0]->owner_hash);
    }

    public function test_krs_status_read_as_unknown_does_not_hide_the_validation(): void
    {
        $created = $this->syncSequence('krs', $this->krs('pending_dpa'), $this->krs('unknown'), $this->krs('validated'));

        $this->assertSame(['krs_validated'], $this->types($created));
    }

    public function test_unreadable_krs_page_keeps_the_previous_baseline(): void
    {
        $created = $this->syncSequence('krs', $this->krs('pending_dpa'), ['found' => false], $this->krs('validated'));

        $this->assertSame(['krs_validated'], $this->types($created));
    }

    public function test_krs_returning_to_pending_dpa_is_a_warning(): void
    {
        $created = $this->syncSequence('krs', $this->krs('validated'), $this->krs('pending_dpa'));

        $this->assertSame(['krs_validation_reopened'], $this->types($created));
        $this->assertSame('warning', $created[0]->urgency);
    }

    public function test_krs_submission_by_the_student_is_not_announced(): void
    {
        $this->assertSame([], $this->syncSequence('krs', $this->krs('not_submitted'), $this->krs('pending_dpa')));
    }

    public function test_new_krs_period_is_announced_with_dates(): void
    {
        $existing = ['kind' => 'her_registrasi', 'start' => '2026-08-01', 'end' => '2026-08-20', 'label' => 'Her-registrasi & pengisian KRS'];
        $added = ['kind' => 'perwalian_perbaikan', 'start' => '2026-09-01', 'end' => '2026-09-07', 'label' => 'Perwalian & perbaikan KRS'];

        $created = $this->syncSequence('krs', $this->krs('validated', [$existing]), $this->krs('validated', [$existing, $added]));

        $this->assertSame(['krs_period_announced'], $this->types($created));
        $this->assertSame('Jadwal Perwalian & perbaikan KRS', $created[0]->title);
        $this->assertSame('1 September 2026 sampai 7 September 2026', $created[0]->body);
    }

    public function test_released_grade_is_announced_per_course(): void
    {
        $before = $this->grades([
            $this->semester('Semester 5', [$this->course('IF501', 'Basis Data', 'B'), $this->course('IF502', 'Jaringan Komputer', null)]),
        ]);
        $after = $this->grades([
            $this->semester('Semester 5', [$this->course('IF501', 'Basis Data', 'B'), $this->course('IF502', 'Jaringan Komputer', 'A-')]),
        ]);

        $created = $this->syncSequence('grades', $before, $after);

        $this->assertSame(['grade_released'], $this->types($created));
        $this->assertSame('Nilai Jaringan Komputer keluar: A-', $created[0]->title);
        $this->assertSame('Semester 5 · 3 SKS', $created[0]->body);
        $this->assertSame('/grades', $created[0]->url);
        $this->assertSame('Semester 5|IF502', $created[0]->record_key);
    }

    public function test_corrected_grade_is_announced_again(): void
    {
        $created = $this->syncSequence(
            'grades',
            $this->grades([$this->semester('Semester 4', [$this->course('IF401', 'Statistika', 'C')])]),
            $this->grades([$this->semester('Semester 4', [$this->course('IF401', 'Statistika', 'B+')])]),
        );

        $this->assertSame('Nilai Statistika keluar: B+', $created[0]->title);
    }

    public function test_grades_missing_from_a_partial_read_are_not_announced_again(): void
    {
        $full = $this->grades([$this->semester('Semester 5', [$this->course('IF501', 'Basis Data', 'B'), $this->course('IF502', 'Jaringan Komputer', 'A')])]);
        $partial = $this->grades([$this->semester('Semester 5', [$this->course('IF501', 'Basis Data', 'B'), $this->course('IF502', 'Jaringan Komputer', null)])]);
        $empty = ['found' => false, 'semesters' => []];

        $this->assertSame([], $this->syncSequence('grades', $full, $partial, $empty, $full));
    }

    public function test_complete_semester_announces_ips(): void
    {
        $before = $this->grades([], [['label' => 'Semester 5', 'ips' => 3.4, 'is_complete' => false]]);
        $after = $this->grades([], [['label' => 'Semester 5', 'ips' => 3.62, 'is_complete' => true]]);

        $created = $this->syncSequence('grades', $before, $after);

        $this->assertSame(['ips_complete'], $this->types($created));
        $this->assertSame('IPS Semester 5: 3,62', $created[0]->title);
    }

    public function test_new_outstanding_bill_is_announced(): void
    {
        $bill = ['name' => 'UKT Tahap 2 tahun ke-2', 'amount_label' => 'Rp 2.500.000', 'installment_stage' => 2, 'study_year' => 2];

        $created = $this->syncSequence('payments', $this->payments('clear'), $this->payments('outstanding', [$bill]));

        $this->assertSame(['bill_new'], $this->types($created));
        $this->assertSame('Tagihan baru: UKT Tahap 2 tahun ke-2', $created[0]->title);
        $this->assertSame('Rp 2.500.000', $created[0]->body);
        $this->assertSame('warning', $created[0]->urgency);
        $this->assertSame('/finance', $created[0]->url);
    }

    public function test_cleared_bills_are_announced_again_in_a_later_semester(): void
    {
        $bill = fn (int $year): array => ['name' => "UKT Tahap 1 tahun ke-{$year}", 'amount_label' => 'Rp 2.500.000', 'installment_stage' => 1, 'study_year' => $year];

        $created = $this->syncSequence(
            'payments',
            $this->payments('outstanding', [$bill(1)]),
            $this->payments('clear'),
            $this->payments('outstanding', [$bill(2)]),
            $this->payments('clear'),
        );

        $this->assertSame(['bill_cleared', 'bill_new', 'bill_cleared'], $this->types($created));
        $this->assertSame('Tagihan lunas tercatat', $created[0]->title);
        $this->assertNotSame($created[0]->dedupe_key, $created[2]->dedupe_key);
    }

    public function test_unreadable_bill_table_does_not_make_known_bills_new(): void
    {
        $bill = ['name' => 'UKT Tahap 1 tahun ke-1', 'amount_label' => 'Rp 2.500.000', 'installment_stage' => 1, 'study_year' => 1];

        $created = $this->syncSequence(
            'payments',
            $this->payments('outstanding', [$bill]),
            $this->payments('unknown'),
            $this->payments('outstanding', [$bill]),
        );

        $this->assertSame([], $created);
    }

    public function test_recorded_payment_is_announced(): void
    {
        $first = ['paid_at' => '2026-08-03', 'paid_at_label' => '03 Agustus 2026', 'amount' => 2500000, 'amount_label' => 'Rp 2.500.000', 'description' => 'UKT Tahap 1', 'installment_stage' => 1, 'study_year' => 2];
        $second = ['paid_at' => '2026-10-20', 'paid_at_label' => '20 Oktober 2026', 'amount' => 2500000, 'amount_label' => 'Rp 2.500.000', 'description' => 'UKT Tahap 2', 'installment_stage' => 2, 'study_year' => 2];

        $created = $this->syncSequence('payments', $this->payments('clear', [], [$first]), $this->payments('clear', [], [$first, $second]));

        $this->assertSame(['payment_recorded'], $this->types($created));
        $this->assertSame('Pembayaran Rp 2.500.000 tercatat', $created[0]->title);
        $this->assertSame('UKT Tahap 2 · 20 Oktober 2026', $created[0]->body);
    }

    public function test_skkm_validation_and_rejection_are_announced(): void
    {
        $created = $this->syncSequence(
            'skkm',
            $this->skkm([$this->skkmEntry('Seminar Keamanan Siber', 'pending'), $this->skkmEntry('Lomba Desain', 'pending')]),
            $this->skkm([$this->skkmEntry('Seminar Keamanan Siber', 'valid'), $this->skkmEntry('Lomba Desain', 'invalid')]),
        );

        $this->assertSame(['skkm_validated', 'skkm_rejected'], $this->types($created));
        $this->assertSame('SKKM divalidasi: Seminar Keamanan Siber', $created[0]->title);
        $this->assertSame('10 poin · Seminar', $created[0]->body);
        $this->assertSame('SKKM ditolak: Lomba Desain', $created[1]->title);
        $this->assertSame('warning', $created[1]->urgency);
        $this->assertSame('/skkm', $created[1]->url);
    }

    public function test_skkm_status_read_as_unknown_is_ignored(): void
    {
        $created = $this->syncSequence(
            'skkm',
            $this->skkm([$this->skkmEntry('Seminar Keamanan Siber', 'valid')]),
            $this->skkm([$this->skkmEntry('Seminar Keamanan Siber', 'unknown')]),
            $this->skkm([$this->skkmEntry('Seminar Keamanan Siber', 'valid')]),
        );

        $this->assertSame([], $created);
    }

    public function test_new_announcement_links_to_its_page_and_skips_duplicates(): void
    {
        $created = $this->syncSequence(
            'announcements',
            $this->announcements([$this->announcement('a1', 'Jadwal UTS Ganjil')]),
            $this->announcements([
                $this->announcement('a2', 'Pembayaran UKT Tahap 2'),
                $this->announcement('a3', 'Pembayaran UKT Tahap 2 (ulang)', duplicateOf: 'a2'),
                $this->announcement('a1', 'Jadwal UTS Ganjil'),
            ]),
        );

        $this->assertSame(['announcement_new'], $this->types($created));
        $this->assertSame('Pengumuman: Pembayaran UKT Tahap 2', $created[0]->title);
        $this->assertSame('BAAK · Ringkasan pengumuman sintetis.', $created[0]->body);
        $this->assertSame('/announcement/'.$this->announcementId('a2'), $created[0]->url);
    }

    public function test_announcement_reposted_on_a_later_sync_is_not_announced_again(): void
    {
        $first = $this->syncSequence(
            'announcements',
            $this->announcements([$this->announcement('a1', 'Jadwal UTS Ganjil')]),
            $this->announcements([$this->announcement('a2', 'Pembayaran UKT Tahap 2'), $this->announcement('a1', 'Jadwal UTS Ganjil')]),
        );
        $this->travel(1)->days();
        app(PortalDataOwner::class)->remember(self::TOKEN, self::USERNAME, 3600);

        // Tuesday's re-post is now the newest copy, so it is the "original" with a new id.
        $repost = $this->syncSequence('announcements', $this->announcements([
            $this->announcement('a3', 'Pembayaran UKT Tahap 2'),
            $this->announcement('a2', 'Pembayaran UKT Tahap 2', duplicateOf: 'a3'),
            $this->announcement('a1', 'Jadwal UTS Ganjil'),
        ]));

        $this->assertCount(1, $first);
        $this->assertSame([], $repost);
    }

    public function test_different_notices_under_one_generic_title_are_each_announced(): void
    {
        $created = $this->syncSequence(
            'announcements',
            $this->announcements([]),
            $this->announcements([$this->announcement('a1', 'PENGUMUMAN', excerpt: 'Pembayaran UKT tahap 2 paling lambat 20 Oktober.')]),
            $this->announcements([
                $this->announcement('a2', 'PENGUMUMAN', excerpt: 'Kartu UTS diambil di BAAK paling lambat 25 Oktober.'),
                $this->announcement('a1', 'PENGUMUMAN', excerpt: 'Pembayaran UKT tahap 2 paling lambat 20 Oktober.'),
            ]),
        );

        $this->assertSame(['BAAK · Pembayaran UKT tahap 2 paling lambat 20 Oktober.', 'BAAK · Kartu UTS diambil di BAAK paling lambat 25 Oktober.'], array_map(
            static fn (PortalNotification $notification): ?string => $notification->body,
            $created
        ));
    }

    public function test_announcements_found_under_their_old_id_are_not_new_once_ids_become_stable(): void
    {
        $created = $this->syncSequence(
            'announcements',
            // Before: ids built from the wording.
            $this->announcements([
                $this->announcement('wording-a1', 'Jadwal UTS Ganjil'),
                $this->announcement('wording-a2', 'Pembayaran UKT Tahap 2'),
            ]),
            // After the deploy: stable ids, the old id kept as legacy_id.
            $this->announcements([
                [...$this->announcement('a1', 'Jadwal UTS Ganjil'), 'legacy_id' => $this->announcementId('wording-a1')],
                [...$this->announcement('a2', 'Pembayaran UKT Tahap 2'), 'legacy_id' => $this->announcementId('wording-a2')],
            ]),
            // Staff correct a1's body: a new wording, the same stable id; a3 is really new.
            $this->announcements([
                [...$this->announcement('a3', 'Libur Nasional'), 'legacy_id' => $this->announcementId('wording-a3')],
                [...$this->announcement('a1', 'Jadwal UTS Ganjil', excerpt: 'Ringkasan yang sudah dikoreksi.'), 'legacy_id' => $this->announcementId('wording-a1-edited')],
                [...$this->announcement('a2', 'Pembayaran UKT Tahap 2'), 'legacy_id' => $this->announcementId('wording-a2')],
            ]),
            // A page still cached from before the deploy keys a2 the old way.
            $this->announcements([
                [...$this->announcement('a1', 'Jadwal UTS Ganjil'), 'legacy_id' => $this->announcementId('wording-a1-edited')],
                $this->announcement('wording-a2', 'Pembayaran UKT Tahap 2'),
            ]),
        );

        $this->assertSame(['Pengumuman: Libur Nasional'], array_map(static fn (PortalNotification $notification): string => $notification->title, $created));
        $this->assertSame('/announcement/'.$this->announcementId('a3'), $created[0]->url);
    }

    public function test_notification_sent_before_stable_ids_follows_its_announcement_to_the_new_id(): void
    {
        $otherStudent = PortalNotification::query()->create([
            'owner_hash' => 'another-student',
            'type' => 'announcement_new',
            'dataset' => 'announcements',
            'record_key' => $this->announcementId('wording-a2'),
            'dedupe_key' => 'another-student-a2',
            'title' => 'Pengumuman: Pembayaran UKT Tahap 2',
            'url' => '/announcement/'.$this->announcementId('wording-a2'),
        ]);

        $created = $this->syncSequence(
            'announcements',
            $this->announcements([$this->announcement('wording-a1', 'Jadwal UTS Ganjil')]),
            // Announced before the deploy, under the wording-based id.
            $this->announcements([
                $this->announcement('wording-a2', 'Pembayaran UKT Tahap 2'),
                $this->announcement('wording-a1', 'Jadwal UTS Ganjil'),
            ]),
            // After the deploy: stable ids, the old one kept as legacy_id.
            $this->announcements([
                [...$this->announcement('a2', 'Pembayaran UKT Tahap 2'), 'legacy_id' => $this->announcementId('wording-a2')],
                [...$this->announcement('a1', 'Jadwal UTS Ganjil'), 'legacy_id' => $this->announcementId('wording-a1')],
            ]),
            // Staff then edit a2's body: its wording-based id changes, its stable id does not.
            $this->announcements([
                [...$this->announcement('a2', 'Pembayaran UKT Tahap 2', excerpt: 'Ringkasan yang sudah dikoreksi.'), 'legacy_id' => $this->announcementId('wording-a2-edited')],
                [...$this->announcement('a1', 'Jadwal UTS Ganjil'), 'legacy_id' => $this->announcementId('wording-a1')],
            ]),
        );

        $this->assertCount(1, $created);
        $notification = $created[0]->fresh();
        $this->assertSame($this->announcementId('a2'), $notification->record_key);
        $this->assertSame('/announcement/'.$this->announcementId('a2'), $notification->url);
        $this->assertSame($created[0]->dedupe_key, $notification->dedupe_key);
        // Another student's notification is theirs to re-key.
        $this->assertSame('/announcement/'.$this->announcementId('wording-a2'), $otherStudent->fresh()->url);
    }

    public function test_deadline_reminder_sent_before_stable_ids_follows_its_announcement_too(): void
    {
        $ownerHash = app(PortalDataOwner::class)->key(self::TOKEN);
        $reminder = PortalNotification::query()->create([
            'owner_hash' => $ownerHash,
            'type' => 'deadline_reminder',
            'dataset' => 'announcements',
            'record_key' => 'announcement-'.$this->announcementId('wording-a2'),
            'dedupe_key' => 'reminder-a2',
            'title' => 'Pembayaran UKT Tahap 2',
            'body' => 'Tenggat Senin, 26 Oktober 2026.',
            'url' => '/announcement/'.$this->announcementId('wording-a2'),
            'urgency' => 'warning',
        ]);
        $unrelated = PortalNotification::query()->create([
            'owner_hash' => $ownerHash,
            'type' => 'deadline_reminder',
            'dataset' => 'announcements',
            'record_key' => 'announcement-'.$this->announcementId('wording-a9'),
            'dedupe_key' => 'reminder-a9',
            'title' => 'Libur Nasional',
            'url' => '/announcement/'.$this->announcementId('wording-a9'),
        ]);

        $created = $this->syncSequence(
            'announcements',
            // Before the deploy, under the wording-based id.
            $this->announcements([$this->announcement('wording-a2', 'Pembayaran UKT Tahap 2')]),
            // After the deploy: stable ids, the old one kept as legacy_id.
            $this->announcements([[...$this->announcement('a2', 'Pembayaran UKT Tahap 2'), 'legacy_id' => $this->announcementId('wording-a2')]]),
        );

        $this->assertSame([], $created);
        $this->assertSame(['announcement-'.$this->announcementId('a2'), '/announcement/'.$this->announcementId('a2')], [$reminder->fresh()->record_key, $reminder->fresh()->url]);
        $this->assertSame('reminder-a2', $reminder->fresh()->dedupe_key);
        $this->assertSame('/announcement/'.$this->announcementId('wording-a9'), $unrelated->fresh()->url);
        $this->assertSame(2, PortalNotification::query()->count());
    }

    public function test_announcement_falling_back_to_its_wording_id_is_not_announced_again(): void
    {
        $created = $this->syncSequence(
            'announcements',
            $this->announcements([
                [...$this->announcement('a1', 'Surat Pengantar Magang'), 'legacy_id' => $this->announcementId('wording-a1')],
            ]),
            // A second post with the same title, day and unit: both fall back to their wording-based ids.
            $this->announcements([
                [...$this->announcement('wording-a2', 'Surat Pengantar Magang', excerpt: 'Pengantar magang gelombang kedua.'), 'legacy_id' => $this->announcementId('wording-a2')],
                [...$this->announcement('wording-a1', 'Surat Pengantar Magang'), 'legacy_id' => $this->announcementId('wording-a1')],
            ]),
            // The second one is taken down: the first goes back to its stable id.
            $this->announcements([
                [...$this->announcement('a1', 'Surat Pengantar Magang'), 'legacy_id' => $this->announcementId('wording-a1')],
            ]),
        );

        $this->assertSame(['BAAK · Pengantar magang gelombang kedua.'], array_map(
            static fn (PortalNotification $notification): ?string => $notification->body,
            $created
        ));
        $this->assertSame('/announcement/'.$this->announcementId('wording-a2'), $created[0]->url);
    }

    public function test_repost_after_the_repeat_window_is_announced_again(): void
    {
        $this->syncSequence(
            'announcements',
            $this->announcements([]),
            $this->announcements([$this->announcement('a1', 'Pembayaran UKT Tahap 2')]),
        );
        $this->travel(8)->days();
        app(PortalDataOwner::class)->remember(self::TOKEN, self::USERNAME, 3600);

        $created = $this->syncSequence('announcements', $this->announcements([
            $this->announcement('a2', 'Pembayaran UKT Tahap 2'),
            $this->announcement('a1', 'Pembayaran UKT Tahap 2'),
        ]));

        $this->assertSame(['announcement_new'], $this->types($created));
    }

    public function test_grades_beyond_the_per_sync_cap_are_summed_up(): void
    {
        $names = ['Basis Data', 'Jaringan Komputer', 'Statistika', 'Kalkulus', 'Etika Profesi', 'Kecerdasan Buatan', 'Sistem Operasi', 'Pemrograman Web'];
        $semester = fn (?string $letter): array => $this->grades([$this->semester('Semester 5', array_map(
            fn (string $name, int $index): array => $this->course('IF50'.$index, $name, $letter),
            $names,
            array_keys($names)
        ))]);

        $created = $this->syncSequence('grades', $semester(null), $semester('A'), $semester('A'));

        $this->assertCount(6, $created);
        $this->assertSame('Nilai Basis Data keluar: A', $created[0]->title);
        $this->assertSame('3 nilai lain juga keluar', $created[5]->title);
        $this->assertSame('Nilai Kecerdasan Buatan keluar: A · Nilai Sistem Operasi keluar: A · Nilai Pemrograman Web keluar: A', $created[5]->body);
        $this->assertSame('/grades', $created[5]->url);
        $this->assertSame('grade_released', $created[5]->type);
    }

    public function test_announcements_beyond_the_per_sync_cap_are_absorbed(): void
    {
        $many = array_map(fn (int $index): array => $this->announcement("n{$index}", "Pengumuman {$index}"), range(1, 8));

        $created = $this->syncSequence('announcements', $this->announcements([]), $this->announcements($many), $this->announcements($many));

        $this->assertCount(5, $created);
        $this->assertSame('Pengumuman: Pengumuman 1', $created[0]->title);
    }

    public function test_opened_exam_is_critical_and_fallback_rows_are_ignored(): void
    {
        $created = $this->syncSequence(
            'exams',
            $this->exams([$this->exam(9186, 'IF503', 'Kecerdasan Buatan', false)]),
            $this->exams([$this->exam(9186, 'IF503', 'Kecerdasan Buatan', true)], 'class_schedule_fallback'),
            $this->exams([$this->exam(9186, 'IF503', 'Kecerdasan Buatan', true)]),
        );

        $this->assertSame(['exam_opened'], $this->types($created));
        $this->assertSame('Ujian Kecerdasan Buatan dibuka', $created[0]->title);
        $this->assertSame('Unggah jawaban sebelum waktunya habis · Rabu, 21 Oktober 2026 08:00', $created[0]->body);
        $this->assertSame('/exam/9186', $created[0]->url);
        $this->assertSame('critical', $created[0]->urgency);
    }

    public function test_exam_opening_again_for_the_final_exam_is_announced(): void
    {
        $created = $this->syncSequence(
            'exams',
            $this->exams([$this->exam(9186, 'IF503', 'Kecerdasan Buatan', false)]),
            $this->exams([$this->exam(9186, 'IF503', 'Kecerdasan Buatan', true)]),
            $this->exams([$this->exam(9186, 'IF503', 'Kecerdasan Buatan', false)]),
            $this->exams([$this->exam(9301, 'IF503', 'Kecerdasan Buatan', true)]),
        );

        $this->assertSame(['exam_opened', 'exam_opened'], $this->types($created));
        $this->assertSame('/exam/9301', $created[1]->url);
    }

    public function test_thesis_status_change_is_announced_and_an_unreadable_status_is_not(): void
    {
        $thesis = static fn (string $state): array => ['found' => true, 'status' => ['state' => $state, 'message' => null, 'raw_sections' => [], 'available' => $state !== 'unknown']];

        $created = $this->syncSequence('thesis', $thesis('not_enrolled'), $thesis('unknown'), $thesis('not_enrolled'), $thesis('active_unparsed'));

        $this->assertSame(['thesis_status_changed'], $this->types($created));
        $this->assertSame('/thesis', $created[0]->url);
        $this->assertSame('Status skripsi di portal berubah', $created[0]->title);
    }

    public function test_portal_context_changes_are_announced(): void
    {
        $created = $this->syncSequence(
            'portal_context',
            $this->portalContext('T.A 2025/2026 Semester Genap', false, ['credit', 'finance']),
            $this->portalContext('T.A 2026/2027 Semester Ganjil', true, ['credit', 'finance', 'skripsi']),
        );

        $this->assertSame(['academic_period_changed', 'questionnaire_opened', 'portal_menu_added'], $this->types($created));
        $this->assertSame('Sekarang T.A 2026/2027 Semester Ganjil. KRS, jadwal, dan tagihan semester baru menyusul di MyCIC.', $created[0]->body);
        $this->assertSame('Kuesioner VMTS 2026/2027', $created[1]->body);
        $this->assertSame('warning', $created[1]->urgency);
        $this->assertSame('Menu baru di portal: Menu skripsi', $created[2]->title);
    }

    public function test_new_assignment_links_to_its_meeting(): void
    {
        $known = ['meeting_id' => '5101', 'course_name' => 'Basis Data', 'material_title' => 'Normalisasi', 'meeting_url' => '/meeting/5101?courses_id=77'];
        $new = ['meeting_id' => '5102', 'course_name' => 'Basis Data', 'material_title' => 'Transaksi', 'meeting_url' => '/meeting/5102?courses_id=77'];

        $created = $this->syncSequence('assignments', ['assignments' => [$known]], ['assignments' => [$known, $new]]);

        $this->assertSame(['assignment_new'], $this->types($created));
        $this->assertSame('Tugas baru: Basis Data', $created[0]->title);
        $this->assertSame('Transaksi', $created[0]->body);
        $this->assertSame('/meeting/5102?courses_id=77', $created[0]->url);
    }

    public function test_scraped_meeting_url_outside_mycic_is_dropped(): void
    {
        $created = $this->syncSequence('assignments', ['assignments' => []], ['assignments' => [
            ['meeting_id' => '5103', 'course_name' => 'Basis Data', 'material_title' => 'Indeks', 'meeting_url' => '//evil.example/meeting'],
        ]]);

        $this->assertNull($created[0]->url);
    }

    public function test_relogin_with_a_new_campus_token_does_not_repeat_notifications(): void
    {
        $this->syncSequence('krs', $this->krs('pending_dpa'), $this->krs('validated'));

        $secondLogin = 'campus-token-second-login';
        app(PortalDataOwner::class)->remember($secondLogin, self::USERNAME, 3600);

        $this->assertSame([], $this->notifier()->process($secondLogin, 'krs', $this->krs('validated')));
        $this->assertDatabaseCount('portal_notifications', 1);
    }

    public function test_the_same_change_found_twice_is_stored_once(): void
    {
        $this->notifier()->process(self::TOKEN, 'krs', $this->krs('pending_dpa'));
        $baselineKey = 'portal_notify_baseline_'.md5(app(PortalDataOwner::class)->key(self::TOKEN).'|krs|'.PortalDataCache::paramsHash([]));
        $baseline = Cache::get($baselineKey);

        $first = $this->notifier()->process(self::TOKEN, 'krs', $this->krs('validated'));
        // A second sync that read the same baseline before the first one wrote it.
        Cache::put($baselineKey, $baseline, 3600);
        $second = $this->notifier()->process(self::TOKEN, 'krs', $this->krs('validated'));

        $this->assertCount(1, $first);
        $this->assertSame([], $second);
        $this->assertDatabaseCount('portal_notifications', 1);
    }

    public function test_new_notification_is_broadcast_with_unread_count(): void
    {
        PortalNotification::factory()->forOwner(app(PortalDataOwner::class)->key(self::TOKEN))->create();

        $this->syncSequence('krs', $this->krs('pending_dpa'), $this->krs('validated'));

        Event::assertDispatched(PortalNotificationSent::class, function (PortalNotificationSent $event): bool {
            $payload = $event->broadcastWith();

            return $event->sessionId === 'laravel-session-1'
                && $payload['type'] === 'krs_validated'
                && $payload['message'] === 'KRS sudah divalidasi DPA'
                && $payload['url'] === '/krs'
                && $payload['unread_count'] === 2
                && $payload['is_read'] === false
                && in_array('/api/notifications', $payload['cache_keys'], true)
                // The unread filter keeps its own device copy.
                && in_array('/api/notifications?unread=1', $payload['cache_keys'], true)
                && $payload['sync_keys'] === ['notification-index'];
        });
    }

    public function test_failure_is_logged_and_never_breaks_the_sync(): void
    {
        Log::spy();
        $this->instance(PortalNotificationCenter::class, Mockery::mock(PortalNotificationCenter::class, function (MockInterface $mock): void {
            $mock->shouldReceive('publish')->andThrow(new \RuntimeException('database unavailable'));
        }));

        $this->notifier()->process(self::TOKEN, 'krs', $this->krs('pending_dpa'));
        $created = $this->notifier()->process(self::TOKEN, 'krs', $this->krs('validated'));

        $this->assertSame([], $created);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => $message === 'Portal snapshot notifications failed');
    }

    public function test_change_not_stored_because_of_a_failure_is_found_on_the_next_sync(): void
    {
        $this->notifier()->process(self::TOKEN, 'krs', $this->krs('pending_dpa'));

        $failing = Mockery::mock(PortalNotificationCenter::class);
        $failing->shouldReceive('publish')->andThrow(new \RuntimeException('database unavailable'));
        (new PortalSnapshotDiffNotifier($failing, app(PortalDataOwner::class)))->process(self::TOKEN, 'krs', $this->krs('validated'));

        $this->assertSame(['krs_validated'], $this->types($this->notifier()->process(self::TOKEN, 'krs', $this->krs('validated'))));
    }

    public function test_dataset_without_rules_is_ignored(): void
    {
        $this->assertSame([], $this->notifier()->rules('profile'));
        $this->assertSame([], $this->syncSequence('profile', ['name' => 'A'], ['name' => 'B']));
    }

    public function test_every_configured_rule_is_valid(): void
    {
        foreach (config('portal.datasets') as $dataset => $definition) {
            $declared = $definition['notify'] ?? [];

            $this->assertCount(count($declared), $this->notifier()->rules($dataset), "Invalid notify rule in {$dataset}");
            $types = array_column($declared, 'type');
            $this->assertSame($types, array_unique($types), "Duplicate notify type in {$dataset}");
        }
    }

    /**
     * Process each payload as one sync, a few minutes apart, and return
     * every notification raised.
     *
     * @param  array<string, mixed>  ...$payloads
     * @return list<PortalNotification>
     */
    private function syncSequence(string $dataset, array ...$payloads): array
    {
        $created = [];

        foreach ($payloads as $payload) {
            array_push($created, ...$this->notifier()->process(self::TOKEN, $dataset, $payload));
            $this->travel(15)->minutes();
        }

        return $created;
    }

    /**
     * @param  list<PortalNotification>  $notifications
     * @return list<string>
     */
    private function types(array $notifications): array
    {
        return array_map(static fn (PortalNotification $notification): string => $notification->type, $notifications);
    }

    private function notifier(): PortalSnapshotDiffNotifier
    {
        return app(PortalSnapshotDiffNotifier::class);
    }

    /**
     * @param  list<array<string, mixed>>  $periods
     * @return array<string, mixed>
     */
    private function krs(string $statusCode, array $periods = []): array
    {
        return [
            'found' => true,
            'academic_period' => ['label' => 'T.A 2026/2027 Semester Ganjil', 'academic_year' => '2026/2027', 'term' => 'ganjil'],
            'status' => ['code' => $statusCode, 'label' => $statusCode, 'raw_text' => ''],
            'periods' => $periods,
            'courses' => [],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $semesters
     * @param  list<array<string, mixed>>  $ipsSeries
     * @return array<string, mixed>
     */
    private function grades(array $semesters, array $ipsSeries = []): array
    {
        return [
            'found' => true,
            'semesters' => $semesters,
            'summary' => ['computed' => ['ips_series' => $ipsSeries]],
            'sources' => ['is_partial' => false],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $courses
     * @return array<string, mixed>
     */
    private function semester(string $label, array $courses): array
    {
        return ['label' => $label, 'semester' => (int) filter_var($label, FILTER_SANITIZE_NUMBER_INT), 'status' => 'partial', 'courses' => $courses];
    }

    /**
     * @return array<string, mixed>
     */
    private function course(string $code, string $name, ?string $letter): array
    {
        return ['code' => $code, 'name' => $name, 'sks' => 3, 'letter' => $letter, 'is_graded' => $letter !== null];
    }

    /**
     * @param  list<array<string, mixed>>  $bills
     * @param  list<array<string, mixed>>  $history
     * @return array<string, mixed>
     */
    private function payments(string $status, array $bills = [], array $history = []): array
    {
        return [
            'outstanding_summary' => ['status' => $status, 'has_outstanding' => $status === 'outstanding', 'total_amount_label' => 'Rp 2.500.000'],
            'outstanding_bills' => $bills,
            'payment_history' => $history,
            'summary' => ['history_available' => true],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return array<string, mixed>
     */
    private function skkm(array $entries): array
    {
        return ['found' => true, 'entries' => $entries];
    }

    /**
     * @return array<string, mixed>
     */
    private function skkmEntry(string $name, string $status): array
    {
        return ['skkm_id' => null, 'name' => $name, 'activity' => 'Seminar', 'level' => 'Nasional', 'type' => 'Peserta', 'points_label' => '10', 'status' => $status];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function announcements(array $items): array
    {
        return ['items' => $items, 'paging' => ['complete' => true]];
    }

    /**
     * @return array<string, mixed>
     */
    private function announcement(string $seed, string $title, ?string $duplicateOf = null, string $excerpt = 'Ringkasan pengumuman sintetis.'): array
    {
        return [
            'id' => $this->announcementId($seed),
            'title' => $title,
            'publisher_unit_short' => 'BAAK',
            'excerpt' => $excerpt,
            'duplicate_of' => $duplicateOf === null ? null : $this->announcementId($duplicateOf),
        ];
    }

    private function announcementId(string $seed): string
    {
        return sha1('synthetic-announcement-'.$seed);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function exams(array $rows, string $source = 'exam_page'): array
    {
        return ['source' => $source, 'exams' => $rows];
    }

    /**
     * @return array<string, mixed>
     */
    private function exam(int $id, string $code, string $name, bool $canUpload): array
    {
        return [
            'id' => $id,
            'course_code' => $code,
            'course_name' => $name,
            'schedule' => 'Rabu, 21 Oktober 2026 08:00',
            'status' => '',
            'upload_url' => $canUpload ? "?mod=sipandai&act=sendujian&id={$id}" : null,
            'can_upload' => $canUpload,
        ];
    }

    /**
     * @param  list<string>  $menuActs
     * @return array<string, mixed>
     */
    private function portalContext(string $period, bool $questionnaire, array $menuActs): array
    {
        return [
            'academic_period' => ['label' => $period],
            'questionnaire' => ['available' => $questionnaire, 'title' => 'Kuesioner VMTS 2026/2027'],
            'sidebar_menu' => array_map(static fn (string $act): array => ['label' => "Menu {$act}", 'href_act' => $act], $menuActs),
        ];
    }
}
