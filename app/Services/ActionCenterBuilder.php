<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Pusat tindakan (§4.2, F01): every obligation the portal knows about, as
 * one prioritized list, built only from stored snapshots.
 *
 * Nothing here contacts the portal. A dataset without a snapshot is queued
 * for a background build and reported in `meta.pending_sources`, so the UI
 * can say "sedang diperbarui" and reload when the sync event arrives. A rule
 * that fails is skipped and reported in `meta.failed_sources`; it never
 * takes the other items down with it.
 *
 * @phpstan-type ActionItem array{
 *     id: string,
 *     type: string,
 *     title: string,
 *     description: string|null,
 *     due_at: string|null,
 *     days_left: int|null,
 *     severity: 'critical'|'warning'|'info',
 *     cta_label: string,
 *     cta_url: string,
 *     cta_external: bool,
 *     source: string,
 *     contact?: array{name: string|null, nidn: string|null, phone: string|null, whatsapp: string|null, email: string|null}
 * }
 */
class ActionCenterBuilder
{
    public const MAX_ITEMS = 20;

    /**
     * Snapshots the rules read.
     *
     * @var list<string>
     */
    public const SOURCES = ['krs', 'advising', 'payments', 'skkm', 'announcements', 'portal_context', 'grades', 'assignments', 'exams'];

    /**
     * Sources the action center may build (missing) or re-sync (stale) in
     * the background, all in one job. Pending assignments are a heavy crawl
     * of every class and meeting page, so they are reuse-only: the Beranda's
     * own "Tugas" read builds that snapshot, the action center only reads it.
     * Exams are one page (plus the cached class list), so they are built here
     * too: an open exam upload must never be missed.
     *
     * @var list<string>
     */
    public const QUEUED_SOURCES = ['krs', 'advising', 'payments', 'skkm', 'announcements', 'portal_context', 'grades', 'exams'];

    /**
     * Sources whose items can be critical: without their snapshot the list
     * is not complete, so the Beranda never says "Semua beres" without them.
     *
     * @var list<string>
     */
    public const COMPLETENESS_SOURCES = ['assignments', 'exams'];

    private const SEVERITY_ORDER = ['critical' => 0, 'warning' => 1, 'info' => 2];

    /**
     * Tie-break after severity and due date (§4.2): time-critical coursework,
     * then administration with a deadline, then obligations, then info.
     */
    private const TYPE_ORDER = [
        'exam_upload' => 0,
        'assignment_due' => 1,
        'krs_validation' => 2,
        'krs_submission' => 2,
        'bill_outstanding' => 3,
        'payment_deadline' => 3,
        'questionnaire' => 4,
        'skkm_requirement' => 5,
        'announcement_deadline' => 6,
        'grade_alert' => 7,
    ];

    private const TIMEZONE = 'Asia/Jakarta';

    private const ASSIGNMENT_WINDOW_HOURS = 48;

    private const ASSIGNMENT_CRITICAL_HOURS = 24;

    private const ANNOUNCEMENT_WINDOW_DAYS = 14;

    public function __construct(
        private readonly PortalDatasetRepository $datasets,
        private readonly PortalKrsResolver $krsResolver,
        private readonly PortalFinanceStatusResolver $financeStatusResolver
    ) {}

    /**
     * @return array{
     *     items: list<ActionItem>,
     *     counts: array{total: int, shown: int, critical: int, warning: int, info: int},
     *     meta: array{
     *         pending_sources: list<string>,
     *         syncing_sources: list<string>,
     *         failed_sources: list<string>,
     *         missing_sources: list<string>,
     *         sources: array<string, array{status: 'ready'|'syncing'|'failed'|'pending'|'missing', synced_at: string|null}>,
     *         is_complete: bool,
     *         generated_at: string
     *     }
     * }
     */
    public function build(string $sessionToken, ?string $broadcastSessionId = null, ?CarbonImmutable $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now(self::TIMEZONE))->setTimezone(self::TIMEZONE);
        $today = $now->startOfDay();
        [$snapshots, $sources] = $this->readSources($sessionToken, $broadcastSessionId);
        $failedSources = array_keys(array_filter($sources, static fn (array $source): bool => $source['status'] === 'failed'));
        $items = [];

        foreach ($this->rules() as $source => $rule) {
            if (! isset($snapshots[$source])) {
                continue;
            }

            try {
                array_push($items, ...$rule($snapshots, $today, $now));
            } catch (\Throwable $throwable) {
                Log::warning('Action center rule failed', [
                    'source' => $source,
                    'error' => $throwable->getMessage(),
                ]);
                $failedSources[] = $source;
            }
        }

        $items = $this->sortItems($this->dedupe($items));
        $shown = array_slice($items, 0, self::MAX_ITEMS);
        $pendingSources = array_keys(array_filter($sources, static fn (array $source): bool => $source['status'] === 'pending'));
        $failedSources = array_values(array_unique($failedSources));
        $missingSources = array_keys(array_filter($sources, static fn (array $source): bool => $source['status'] === 'missing'));
        $missingCritical = array_values(array_intersect($missingSources, self::COMPLETENESS_SOURCES));

        return [
            'items' => $shown,
            'counts' => [
                'total' => count($items),
                'shown' => count($shown),
                'critical' => $this->countSeverity($items, 'critical'),
                'warning' => $this->countSeverity($items, 'warning'),
                'info' => $this->countSeverity($items, 'info'),
            ],
            'meta' => [
                'pending_sources' => $pendingSources,
                'syncing_sources' => array_keys(array_filter($sources, static fn (array $source): bool => $source['status'] === 'syncing')),
                'failed_sources' => $failedSources,
                'missing_sources' => $missingSources,
                'sources' => $sources,
                'is_complete' => $pendingSources === [] && $failedSources === [] && $missingCritical === [],
                'generated_at' => now()->toISOString(),
            ],
        ];
    }

    /**
     * @return array{0: array<string, array<string, mixed>>, 1: array<string, array{status: 'ready'|'syncing'|'failed'|'pending'|'missing', synced_at: string|null}>}
     */
    private function readSources(string $sessionToken, ?string $broadcastSessionId): array
    {
        $snapshots = [];
        $sources = [];

        foreach ($this->datasets->peekMany($sessionToken, self::SOURCES, $broadcastSessionId, self::QUEUED_SOURCES) as $source => $result) {
            $status = (string) ($result['meta']['status'] ?? 'synced');
            $sources[$source] = [
                'status' => match (true) {
                    // Unreadable, or its last sync failed (items then use the previous snapshot).
                    $status === 'failed' => 'failed',
                    $result['data'] === null => $status === 'pending' ? 'pending' : 'missing',
                    $status === 'syncing' => 'syncing',
                    default => 'ready',
                },
                'synced_at' => is_string($result['meta']['synced_at'] ?? null) ? $result['meta']['synced_at'] : null,
            ];

            if (is_array($result['data'])) {
                $snapshots[$source] = $result['data'];
            }
        }

        return [$snapshots, $sources];
    }

    /**
     * Rules keyed by the snapshot they need; other snapshots only add detail.
     *
     * @return array<string, callable(array<string, array<string, mixed>>, CarbonImmutable, CarbonImmutable): list<ActionItem>>
     */
    private function rules(): array
    {
        return [
            'exams' => fn (array $snapshots, CarbonImmutable $today): array => $this->examItems($snapshots['exams']),
            'assignments' => fn (array $snapshots, CarbonImmutable $today, CarbonImmutable $now): array => $this->assignmentItems($snapshots['assignments'], $today, $now),
            'krs' => fn (array $snapshots, CarbonImmutable $today): array => $this->krsItems($snapshots['krs'], $snapshots['advising'] ?? null, $snapshots['announcements'] ?? null, $today),
            'payments' => fn (array $snapshots, CarbonImmutable $today): array => $this->paymentItems($snapshots['payments'], [
                $snapshots['portal_context']['notice'] ?? null,
                $snapshots['announcements']['notice'] ?? null,
            ], $today),
            'portal_context' => fn (array $snapshots, CarbonImmutable $today): array => $this->questionnaireItems($snapshots['portal_context']),
            'announcements' => fn (array $snapshots, CarbonImmutable $today): array => $this->announcementItems(
                $snapshots['announcements'],
                $today,
                $this->krsPeriodDeadlines($snapshots, $today)
            ),
            'skkm' => fn (array $snapshots, CarbonImmutable $today): array => $this->skkmItems($snapshots['skkm'], $snapshots['grades'] ?? null),
            'grades' => fn (array $snapshots, CarbonImmutable $today): array => $this->gradeItems($snapshots['grades']),
        ];
    }

    /**
     * KRS not validated by the DPA, or not submitted while a KRS period is open (F09).
     *
     * @param  array<string, mixed>  $krs
     * @param  array<string, mixed>|null  $advising
     * @param  array<string, mixed>|null  $announcements
     * @return list<ActionItem>
     */
    private function krsItems(array $krs, ?array $advising, ?array $announcements, CarbonImmutable $today): array
    {
        if (($krs['found'] ?? false) !== true) {
            return [];
        }

        $insights = $this->krsResolver->insights($krs, $advising, $announcements, $today);
        $period = $insights['active_period'];
        $daysLeft = $period['days_left'] ?? null;
        $dpaName = $insights['dpa']['name'] ?? null;

        if ($insights['status']['code'] === 'pending_dpa') {
            $description = $dpaName !== null
                ? "Hubungi DPA {$dpaName} supaya KRS kamu divalidasi"
                : 'Hubungi DPA kamu supaya KRS divalidasi';
            $description .= $period !== null ? ' sebelum '.$this->longDate($period['end']).'.' : '.';
            $description .= ' Nilai semester ini baru bisa dilihat setelah KRS divalidasi.';

            return [$this->item(
                id: 'krs-pending-dpa',
                type: 'krs_validation',
                title: 'KRS belum divalidasi DPA'.$this->daysLeftSuffix($daysLeft),
                description: $description,
                dueAt: $period['end'] ?? null,
                daysLeft: $daysLeft,
                severity: $daysLeft !== null && $daysLeft <= 3 ? 'critical' : 'warning',
                ctaLabel: 'Lihat KRS',
                ctaUrl: '/krs',
                source: 'krs',
                contact: $insights['dpa'],
            )];
        }

        if ($insights['status']['code'] === 'not_submitted' && $period !== null) {
            return [$this->item(
                id: 'krs-not-submitted',
                type: 'krs_submission',
                title: 'KRS belum diajukan'.$this->daysLeftSuffix($daysLeft),
                description: "{$period['label']} dibuka sampai ".$this->longDate($period['end']).'. Isi KRS lalu minta validasi DPA.',
                dueAt: $period['end'],
                daysLeft: $daysLeft,
                severity: $daysLeft !== null && $daysLeft <= 3 ? 'critical' : 'warning',
                ctaLabel: 'Lihat KRS',
                ctaUrl: '/krs',
                source: 'krs',
                contact: $insights['dpa'],
            )];
        }

        return [];
    }

    /**
     * The her-registrasi announcement deadline a KRS item already stands for
     * (announcement id => period end), so the same obligation is not listed
     * twice: once as "KRS belum diajukan" and once as the announcement.
     * A KRS that cannot be read only costs that dedupe: the announcement
     * items never fail with it.
     *
     * @param  array<string, array<string, mixed>>  $snapshots
     * @return array<string, string>
     */
    private function krsPeriodDeadlines(array $snapshots, CarbonImmutable $today): array
    {
        $krs = $snapshots['krs'] ?? null;

        if (! is_array($krs) || ($krs['found'] ?? false) !== true) {
            return [];
        }

        try {
            $insights = $this->krsResolver->insights($krs, $snapshots['advising'] ?? null, $snapshots['announcements'] ?? null, $today);
        } catch (\Throwable $throwable) {
            Log::warning('KRS period of the action center could not be read', [
                'error' => $throwable->getMessage(),
            ]);

            return [];
        }

        $period = $insights['active_period'];
        $announcementId = $period['announcement_id'] ?? null;

        if (! in_array($insights['status']['code'], ['pending_dpa', 'not_submitted'], true) || ! is_string($announcementId) || ! is_string($period['end'] ?? null)) {
            return [];
        }

        return [$announcementId => $period['end']];
    }

    /**
     * Outstanding bills (F34) and a payment deadline still ahead for a stage
     * not recorded as paid (F36).
     *
     * @param  array<string, mixed>  $payments
     * @param  list<array<string, mixed>|null>  $notices
     * @return list<ActionItem>
     */
    private function paymentItems(array $payments, array $notices, CarbonImmutable $today): array
    {
        $status = $this->financeStatusResolver->summarize($payments, $notices, $today);
        $deadline = $status['active_deadline'];

        if ($status['outstanding_summary']['status'] === 'outstanding') {
            $billNames = array_values(array_filter(array_map(
                static fn (array $bill): ?string => is_string($bill['name'] ?? null) && $bill['name'] !== '' ? $bill['name'] : null,
                $status['outstanding_bills']
            )));
            $description = $billNames !== [] ? $this->joinNames($billNames).'.' : 'Ada tagihan yang belum tercatat lunas di portal.';

            if ($deadline !== null) {
                $description .= ' Batas bayar '.$this->longDate($deadline['date']).'.';
            }

            if (($status['next_stage']['stage'] ?? null) === 1 && ($status['next_stage']['status'] ?? null) === 'unpaid') {
                $description .= ' Selama UKT tahap 1 belum lunas, PIN KRS belum bisa terbit.';
            }

            $label = $status['outstanding_summary']['total_amount_label'];

            return [$this->item(
                id: 'bills-outstanding',
                type: 'bill_outstanding',
                title: $label !== null ? "Tagihan belum lunas: {$label}" : 'Ada tagihan belum lunas',
                description: $description,
                dueAt: $deadline['date'] ?? null,
                daysLeft: $deadline['days_left'] ?? null,
                severity: $deadline !== null && $deadline['days_left'] <= 7 ? 'critical' : 'warning',
                ctaLabel: 'Lihat tagihan',
                ctaUrl: '/finance',
                source: 'payments',
            )];
        }

        if ($deadline === null) {
            return [];
        }

        $daysLeft = $deadline['days_left'];

        return [$this->item(
            id: 'payment-deadline-'.$deadline['date'],
            type: 'payment_deadline',
            title: ($deadline['stage'] !== null ? "Batas bayar UKT tahap {$deadline['stage']}" : 'Batas pembayaran kuliah').$this->daysLeftSuffix($daysLeft),
            description: 'Portal meminta pembayaran paling lambat '.$this->longDate($deadline['date']).'. Kalau sudah membayar, status lunas baru muncul setelah Keuangan mencatatnya.',
            dueAt: $deadline['date'],
            daysLeft: $daysLeft,
            severity: match (true) {
                $daysLeft <= 3 => 'critical',
                $daysLeft <= 14 => 'warning',
                default => 'info',
            },
            ctaLabel: 'Cek status UKT',
            ctaUrl: '/finance',
            source: 'payments',
        )];
    }

    /**
     * SKKM graduation requirement not met yet (F37).
     *
     * @param  array<string, mixed>  $skkm
     * @param  array<string, mixed>|null  $grades
     * @return list<ActionItem>
     */
    private function skkmItems(array $skkm, ?array $grades): array
    {
        if (($skkm['found'] ?? false) !== true || ($skkm['graduation_status']['is_met'] ?? null) !== false) {
            return [];
        }

        $summary = is_array($skkm['summary'] ?? null) ? $skkm['summary'] : [];
        $remaining = $this->number($summary['remaining_points'] ?? null);
        $valid = $this->number($summary['valid_points'] ?? null);
        $pending = $this->number($summary['pending_points'] ?? null);
        $required = $this->number($skkm['stats']['required_points'] ?? null);

        $description = match (true) {
            $valid !== null && $required !== null => 'Poin valid kamu '.$this->formatNumber($valid).' dari '.$this->formatNumber($required).'.',
            $valid !== null => 'Poin valid kamu '.$this->formatNumber($valid).'.',
            default => (string) ($skkm['graduation_status']['text'] ?? 'Status kelulusan SKKM belum memenuhi.'),
        };

        if ($pending !== null && $pending > 0) {
            $description .= ' '.$this->formatNumber($pending).' poin masih menunggu validasi DPA.';
        }

        $progressPercent = $grades['summary']['progress']['progress_percent'] ?? null;

        return [$this->item(
            id: 'skkm-graduation',
            type: 'skkm_requirement',
            title: $remaining !== null && $remaining > 0
                ? 'SKKM kurang '.$this->formatNumber($remaining).' poin untuk syarat lulus'
                : 'SKKM belum memenuhi syarat kelulusan',
            description: trim($description),
            dueAt: null,
            daysLeft: null,
            // Close to graduation the shortfall blocks the next step.
            severity: is_numeric($progressPercent) && (float) $progressPercent >= 75 ? 'warning' : 'info',
            ctaLabel: 'Lihat SKKM',
            ctaUrl: '/skkm',
            source: 'skkm',
        )];
    }

    /**
     * Announcement deadlines in the next 14 days (F03), except those a KRS
     * item already covers.
     *
     * @param  array<string, mixed>  $announcements
     * @param  array<string, string>  $coveredDeadlines  announcement id => date, from krsPeriodDeadlines()
     * @return list<ActionItem>
     */
    private function announcementItems(array $announcements, CarbonImmutable $today, array $coveredDeadlines = []): array
    {
        $byAnnouncement = [];

        foreach (is_array($announcements['upcoming_deadlines'] ?? null) ? $announcements['upcoming_deadlines'] : [] as $deadline) {
            $announcementId = is_array($deadline) ? (string) ($deadline['announcement_id'] ?? '') : '';
            $date = is_array($deadline) ? $this->date($deadline['date'] ?? null) : null;

            if ($announcementId === '' || $date === null || ($coveredDeadlines[$announcementId] ?? null) === $date->toDateString()) {
                continue;
            }

            // The snapshot can be hours old; count the days again.
            $daysLeft = (int) round($today->diffInDays($date));

            if ($daysLeft < 0 || $daysLeft > self::ANNOUNCEMENT_WINDOW_DAYS) {
                continue;
            }

            if (isset($byAnnouncement[$announcementId]) && $byAnnouncement[$announcementId]['days_left'] <= $daysLeft) {
                continue;
            }

            $byAnnouncement[$announcementId] = [...$deadline, 'days_left' => $daysLeft];
        }

        $items = [];

        foreach ($byAnnouncement as $announcementId => $deadline) {
            $start = $this->date($deadline['start'] ?? null);
            $dateLine = match (true) {
                ($deadline['type'] ?? null) === 'period' && $start !== null && $start->greaterThan($today) => 'Mulai '.$this->longDate($start->toDateString()).' sampai '.$this->longDate($deadline['date']),
                ($deadline['type'] ?? null) === 'period' => 'Berlangsung sampai '.$this->longDate($deadline['date']),
                default => 'Tenggat '.$this->longDate($deadline['date']),
            };
            $context = trim((string) ($deadline['context'] ?? ''));
            $daysLeft = $deadline['days_left'];

            $items[] = $this->item(
                id: 'announcement-'.$announcementId,
                type: 'announcement_deadline',
                title: trim((string) ($deadline['title'] ?? 'Pengumuman kampus')),
                description: Str::limit($dateLine.'.'.($context !== '' ? ' '.$context : ''), 200),
                dueAt: $deadline['date'],
                daysLeft: $daysLeft,
                severity: match (true) {
                    $daysLeft <= 2 => 'critical',
                    $daysLeft <= 7 => 'warning',
                    default => 'info',
                },
                ctaLabel: 'Baca pengumuman',
                ctaUrl: '/announcement/'.$announcementId,
                source: 'announcements',
            );
        }

        return $items;
    }

    /**
     * Kuesioner VMTS shown on the portal dashboard (F02, stage 1).
     *
     * @param  array<string, mixed>  $portalContext
     * @return list<ActionItem>
     */
    private function questionnaireItems(array $portalContext): array
    {
        $questionnaire = is_array($portalContext['questionnaire'] ?? null) ? $portalContext['questionnaire'] : [];

        if (($questionnaire['available'] ?? false) !== true) {
            return [];
        }

        $academicYear = is_string($questionnaire['academic_year'] ?? null) ? $questionnaire['academic_year'] : null;

        return [$this->item(
            id: 'questionnaire-vmts'.($academicYear !== null ? '-'.str_replace('/', '-', $academicYear) : ''),
            type: 'questionnaire',
            title: 'Isi Kuesioner VMTS'.($academicYear !== null ? " T.A {$academicYear}" : ''),
            description: 'Kuesioner wajib di awal tahun akademik. Pengisiannya ada di Portal Kampus, jadi kamu perlu login ulang di sana.',
            dueAt: null,
            daysLeft: null,
            severity: 'warning',
            ctaLabel: 'Buka di Portal Kampus',
            ctaUrl: (string) config('portal.login_url'),
            source: 'portal_context',
            ctaExternal: true,
        )];
    }

    /**
     * Pending assignments due within the next 48 hours (F26). The deadline's
     * time of day counts: one that passed this morning is gone, and 23:59 two
     * days ahead (about 64 hours) is not "within 48 hours" yet.
     *
     * @param  array<string, mixed>  $assignments
     * @return list<ActionItem>
     */
    private function assignmentItems(array $assignments, CarbonImmutable $today, CarbonImmutable $now): array
    {
        $items = [];

        foreach (is_array($assignments['assignments'] ?? null) ? $assignments['assignments'] : [] as $assignment) {
            $date = is_array($assignment) ? $this->date($assignment['deadline_date'] ?? null) : null;

            if ($date === null) {
                continue;
            }

            $deadlineAt = $this->deadlineMoment($date, (string) ($assignment['deadline'] ?? ''));

            if ($deadlineAt->lessThanOrEqualTo($now) || $now->diffInMinutes($deadlineAt) > self::ASSIGNMENT_WINDOW_HOURS * 60) {
                continue;
            }

            $daysLeft = (int) round($today->diffInDays($date));
            $hasTime = $deadlineAt->format('H:i:s') !== '23:59:59';
            $courseName = trim((string) ($assignment['course_name'] ?? ''));
            $materialTitle = trim((string) ($assignment['material_title'] ?? ''));
            $meetingUrl = (string) ($assignment['meeting_url'] ?? '');
            $id = (string) ($assignment['meeting_id'] ?? md5($courseName.'|'.$materialTitle));

            $items[] = $this->item(
                id: 'assignment-'.$id,
                type: 'assignment_due',
                title: $courseName !== '' ? "Tugas {$courseName}" : 'Tugas belum dikumpulkan',
                description: trim(($materialTitle !== '' ? $materialTitle.' · ' : '').'Tenggat '.$this->longDate($date->toDateString()).($hasTime ? ' pukul '.$deadlineAt->format('H:i') : '').'.'),
                dueAt: $date->toDateString(),
                daysLeft: $daysLeft,
                severity: $now->diffInMinutes($deadlineAt) <= self::ASSIGNMENT_CRITICAL_HOURS * 60 ? 'critical' : 'warning',
                ctaLabel: 'Kerjakan tugas',
                ctaUrl: str_starts_with($meetingUrl, '/meeting/') ? $meetingUrl : '/dashboard',
                source: 'assignments',
            );
        }

        return $items;
    }

    /**
     * The moment a deadline passes: the time written next to the date in the
     * portal text ("14 April 2026 23:59", "… pukul 08.00"), else the end of
     * that day.
     */
    private function deadlineMoment(CarbonImmutable $date, string $rawDeadline): CarbonImmutable
    {
        if (preg_match('/\b\d{4}\b\D{0,12}?([01]?\d|2[0-3])[:.]([0-5]\d)(?:[:.]([0-5]\d))?\b/u', $rawDeadline, $matches) === 1) {
            return $date->setTime((int) $matches[1], (int) $matches[2], isset($matches[3]) ? (int) $matches[3] : 0);
        }

        return $date->setTime(23, 59, 59);
    }

    /**
     * Exams whose answer upload is open right now (F29).
     *
     * @param  array<string, mixed>  $exams
     * @return list<ActionItem>
     */
    private function examItems(array $exams): array
    {
        $items = [];

        foreach (is_array($exams['exams'] ?? null) ? $exams['exams'] : [] as $exam) {
            if (! is_array($exam) || ($exam['can_upload'] ?? false) !== true) {
                continue;
            }

            if (preg_match('/sudah|terkirim|uploaded|selesai/i', (string) ($exam['status'] ?? '')) === 1) {
                continue;
            }

            $examId = (string) ($exam['id'] ?? '');
            $courseName = trim((string) ($exam['course_name'] ?? ''));
            $schedule = trim((string) ($exam['schedule'] ?? ''));

            if (! ctype_digit($examId)) {
                continue;
            }

            $items[] = $this->item(
                id: 'exam-'.$examId,
                type: 'exam_upload',
                title: $courseName !== '' ? "Ujian {$courseName} sedang dibuka" : 'Ujian sedang dibuka',
                description: 'Upload jawaban sebelum sesi ditutup'.($schedule !== '' ? " ({$schedule})" : '').'.',
                dueAt: null,
                daysLeft: null,
                severity: 'critical',
                ctaLabel: 'Buka ujian',
                ctaUrl: '/exam/'.$examId,
                source: 'exams',
            );
        }

        return $items;
    }

    /**
     * Courses whose best attempt is still D or E (F16): informative only.
     *
     * @param  array<string, mixed>  $grades
     * @return list<ActionItem>
     */
    private function gradeItems(array $grades): array
    {
        $computed = is_array($grades['summary']['computed'] ?? null) ? $grades['summary']['computed'] : [];
        $courses = [];

        foreach ([...($computed['d_courses'] ?? []), ...($computed['e_courses'] ?? [])] as $course) {
            if (is_array($course)) {
                $courses[strtoupper((string) ($course['code'] ?? '')).'|'.strtolower((string) ($course['name'] ?? ''))] = $course;
            }
        }

        if ($courses === []) {
            return [];
        }

        $names = array_values(array_filter(array_map(
            static fn (array $course): ?string => isset($course['name']) && $course['name'] !== ''
                ? $course['name'].(isset($course['letter']) ? " ({$course['letter']})" : '')
                : null,
            $courses
        )));
        $count = count($courses);

        return [$this->item(
            id: 'grades-low',
            type: 'grade_alert',
            title: "{$count} MK bernilai D/E",
            description: ($names !== [] ? 'Pertimbangkan mengulang '.$this->joinNames($names).'. ' : '').'Cek jadwal Semester Pendek atau rencanakan di KRS berikutnya.',
            dueAt: null,
            daysLeft: null,
            severity: 'info',
            ctaLabel: 'Lihat transkrip',
            ctaUrl: '/transcript',
            source: 'grades',
        )];
    }

    /**
     * @param  array{name: string|null, nidn: string|null, phone: string|null, whatsapp: string|null, email: string|null}|null  $contact
     * @return ActionItem
     */
    private function item(
        string $id,
        string $type,
        string $title,
        ?string $description,
        ?string $dueAt,
        ?int $daysLeft,
        string $severity,
        string $ctaLabel,
        string $ctaUrl,
        string $source,
        ?array $contact = null,
        bool $ctaExternal = false
    ): array {
        $item = [
            'id' => $id,
            'type' => $type,
            'title' => $title,
            'description' => $description,
            'due_at' => $dueAt,
            'days_left' => $daysLeft,
            'severity' => $severity,
            'cta_label' => $ctaLabel,
            'cta_url' => $ctaUrl,
            'cta_external' => $ctaExternal,
            'source' => $source,
        ];

        if ($contact !== null) {
            $item['contact'] = $contact;
        }

        return $item;
    }

    /**
     * @param  list<ActionItem>  $items
     * @return list<ActionItem>
     */
    private function dedupe(array $items): array
    {
        $unique = [];

        foreach ($items as $item) {
            $unique[$item['id']] ??= $item;
        }

        return array_values($unique);
    }

    /**
     * Severity first, then the nearest due date (undated items last), then
     * the kind of obligation, then title.
     *
     * @param  list<ActionItem>  $items
     * @return list<ActionItem>
     */
    private function sortItems(array $items): array
    {
        usort($items, static function (array $left, array $right): int {
            return [
                self::SEVERITY_ORDER[$left['severity']] ?? 3,
                $left['due_at'] === null ? 1 : 0,
                $left['due_at'] ?? '',
                self::TYPE_ORDER[$left['type']] ?? 99,
                $left['title'],
            ] <=> [
                self::SEVERITY_ORDER[$right['severity']] ?? 3,
                $right['due_at'] === null ? 1 : 0,
                $right['due_at'] ?? '',
                self::TYPE_ORDER[$right['type']] ?? 99,
                $right['title'],
            ];
        });

        return $items;
    }

    /**
     * @param  list<ActionItem>  $items
     */
    private function countSeverity(array $items, string $severity): int
    {
        return count(array_filter($items, static fn (array $item): bool => $item['severity'] === $severity));
    }

    private function daysLeftSuffix(?int $daysLeft): string
    {
        return match (true) {
            $daysLeft === null => '',
            $daysLeft === 0 => ' · hari terakhir',
            default => " · {$daysLeft} hari lagi",
        };
    }

    /**
     * @param  list<string>  $names
     */
    private function joinNames(array $names, int $limit = 3): string
    {
        $shown = array_slice($names, 0, $limit);
        $rest = count($names) - count($shown);
        $text = count($shown) > 1
            ? implode(', ', array_slice($shown, 0, -1)).' dan '.end($shown)
            : (string) ($shown[0] ?? '');

        return $rest > 0 ? "{$text} (+{$rest} lainnya)" : $text;
    }

    private function longDate(string $date): string
    {
        $parsed = $this->date($date);

        return $parsed === null ? $date : $parsed->locale('id')->isoFormat('dddd, D MMMM YYYY');
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1 || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }

        return CarbonImmutable::create((int) $parts[1], (int) $parts[2], (int) $parts[3], 0, 0, 0, self::TIMEZONE);
    }

    private function number(mixed $value): int|float|null
    {
        return is_int($value) || is_float($value) ? $value : null;
    }

    private function formatNumber(int|float $value): string
    {
        return is_float($value) && floor($value) !== $value
            ? rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ',')
            : number_format((float) $value, 0, ',', '.');
    }
}
