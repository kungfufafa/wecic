<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;

/**
 * Decision view of the current KRS (F09): which KRS period matters now and
 * how many days are left, where the student is in the validation flow, and
 * the canonical DPA contact (K6: Perwalian first, Reg & KRS Online only as a
 * fallback).
 *
 * Periods come from Reg & KRS Online (`krs.periods`) plus the short
 * "Pengisian Her-Registrasi dan KRS" window that only her-registrasi
 * announcements state (F03). It is a pure function of snapshot data.
 *
 * @phpstan-type KrsPeriod array{kind: string, label: string, start: string, end: string, source: 'credit'|'announcement', source_ref: string|null, announcement_id: string|null, is_open: bool, is_upcoming: bool, is_past: bool, days_left: int|null, days_until_start: int|null}
 */
class PortalKrsResolver
{
    private const TIMEZONE = 'Asia/Jakarta';

    /**
     * @var array<string, string>
     */
    public const PERIOD_LABELS = [
        'her_registrasi' => 'Her-registrasi & pengisian KRS',
        'perwalian_perbaikan' => 'Perwalian & perbaikan KRS',
        'krs' => 'Pengisian KRS',
    ];

    /**
     * Period kinds that matter for each KRS status, most relevant first:
     * before submitting it is the filling window, afterwards the
     * advising/correction window in which the DPA validates.
     *
     * @var array<string, list<string>>
     */
    private const RELEVANT_KINDS = [
        'not_submitted' => ['her_registrasi', 'krs', 'perwalian_perbaikan'],
        'pending_dpa' => ['perwalian_perbaikan', 'krs', 'her_registrasi'],
        'validated' => ['perwalian_perbaikan', 'krs', 'her_registrasi'],
        'unknown' => ['her_registrasi', 'krs', 'perwalian_perbaikan'],
    ];

    /**
     * @var array<string, string>
     */
    private const STATUS_LABELS = [
        'pending_dpa' => 'Menunggu validasi DPA',
        'validated' => 'Sudah divalidasi DPA',
        'not_submitted' => 'KRS belum diajukan',
        'unknown' => 'Status KRS belum terbaca',
    ];

    /**
     * @param  array<string, mixed>  $krs  `krs` dataset (KrsExtractor::extractKrsData)
     * @param  array<string, mixed>|null  $advising  `advising` dataset, when a snapshot exists
     * @param  array<string, mixed>|null  $announcements  `announcements` dataset, when a snapshot exists
     * @return array{
     *     status: array{code: 'pending_dpa'|'validated'|'not_submitted'|'unknown', label: string, raw_text: string|null},
     *     periods: list<KrsPeriod>,
     *     active_period: KrsPeriod|null,
     *     next_period: KrsPeriod|null,
     *     is_period_open: bool,
     *     dpa: array{name: string|null, nidn: string|null, phone: string|null, whatsapp: string|null, email: string|null}|null,
     *     dpa_source: 'advising'|'credit'|null,
     *     tracker: list<array{key: 'period_open'|'submitted'|'validation'|'validated', label: string, state: 'done'|'current'|'todo'|'unknown'}>,
     *     latest_krs_id: int|null,
     *     study_load: array{courses: int, sks: int}|null
     * }
     */
    public function insights(array $krs, ?array $advising = null, ?array $announcements = null, ?CarbonImmutable $today = null): array
    {
        $today = ($today ?? CarbonImmutable::now(self::TIMEZONE))->setTimezone(self::TIMEZONE)->startOfDay();
        $status = $this->status($krs);
        $periods = $this->periods($krs, $announcements, $today);
        $openPeriods = array_values(array_filter($periods, static fn (array $period): bool => $period['is_open']));
        $upcomingPeriods = array_values(array_filter($periods, static fn (array $period): bool => $period['is_upcoming']));
        [$dpa, $dpaSource] = $this->dpa($advising, $krs);
        $found = ($krs['found'] ?? false) === true;

        return [
            'status' => $status,
            'periods' => $periods,
            'active_period' => $this->relevantPeriod($openPeriods, $status['code']),
            'next_period' => $upcomingPeriods[0] ?? null,
            'is_period_open' => $openPeriods !== [],
            'dpa' => $dpa,
            'dpa_source' => $dpaSource,
            'tracker' => $this->tracker($status['code'], $openPeriods !== [], $upcomingPeriods !== []),
            'latest_krs_id' => is_int($advising['latest_krs_id'] ?? null) ? $advising['latest_krs_id'] : null,
            'study_load' => $found && is_array($krs['totals'] ?? null) ? [
                'courses' => (int) ($krs['totals']['courses'] ?? 0),
                'sks' => (int) ($krs['totals']['sks'] ?? 0),
            ] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $krs
     * @return array{code: 'pending_dpa'|'validated'|'not_submitted'|'unknown', label: string, raw_text: string|null}
     */
    private function status(array $krs): array
    {
        $status = is_array($krs['status'] ?? null) ? $krs['status'] : [];
        $code = (string) ($status['code'] ?? 'unknown');

        if (! array_key_exists($code, self::STATUS_LABELS) || ($krs['found'] ?? false) !== true) {
            $code = 'unknown';
        }

        $label = is_string($status['label'] ?? null) && $status['label'] !== '' ? $status['label'] : self::STATUS_LABELS[$code];

        return [
            'code' => $code,
            'label' => $label,
            'raw_text' => is_string($status['raw_text'] ?? null) ? $status['raw_text'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $krs
     * @param  array<string, mixed>|null  $announcements
     * @return list<array<string, mixed>>
     */
    private function periods(array $krs, ?array $announcements, CarbonImmutable $today): array
    {
        $periods = [];

        foreach (is_array($krs['periods'] ?? null) ? $krs['periods'] : [] as $period) {
            if (! is_array($period)) {
                continue;
            }

            $periods[] = $this->period(
                (string) ($period['kind'] ?? 'krs'),
                $period['start'] ?? null,
                $period['end'] ?? null,
                'credit',
                is_string($period['source_ref'] ?? null) ? $period['source_ref'] : null,
                null,
                $today
            );
        }

        foreach ($this->announcementPeriods($announcements) as $period) {
            $periods[] = $this->period('her_registrasi', $period['start'], $period['end'], 'announcement', 'announcement', $period['announcement_id'], $today);
        }

        $periods = array_values(array_filter($periods));
        $unique = [];

        foreach ($periods as $period) {
            // An old her-registrasi announcement only adds noise once it is over.
            if ($period['source'] === 'announcement' && $period['is_past']) {
                continue;
            }

            $unique[$period['kind'].'|'.$period['start'].'|'.$period['end']] ??= $period;
        }

        $periods = array_values($unique);
        usort($periods, static fn (array $left, array $right): int => [$left['start'], $left['end']] <=> [$right['start'], $right['end']]);

        return $periods;
    }

    /**
     * Her-registrasi windows ("17 s/d 19 September 2026") stated in announcements.
     *
     * @param  array<string, mixed>|null  $announcements
     * @return list<array{start: string, end: string, announcement_id: string|null}>
     */
    private function announcementPeriods(?array $announcements): array
    {
        $periods = [];

        foreach (is_array($announcements['items'] ?? null) ? $announcements['items'] : [] as $item) {
            if (! is_array($item) || ! preg_match('/her[\s.-]*regis/iu', (string) ($item['title'] ?? ''))) {
                continue;
            }

            foreach (is_array($item['deadlines'] ?? null) ? $item['deadlines'] : [] as $deadline) {
                if (! is_array($deadline) || ($deadline['type'] ?? null) !== 'period' || ! is_string($deadline['start'] ?? null) || ! is_string($deadline['date'] ?? null)) {
                    continue;
                }

                $periods[] = [
                    'start' => $deadline['start'],
                    'end' => $deadline['date'],
                    'announcement_id' => is_string($item['id'] ?? null) ? $item['id'] : null,
                ];
            }
        }

        return $periods;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function period(string $kind, mixed $start, mixed $end, string $source, ?string $sourceRef, ?string $announcementId, CarbonImmutable $today): ?array
    {
        $startDate = $this->date($start);
        $endDate = $this->date($end);

        if ($startDate === null || $endDate === null || $endDate->lessThan($startDate)) {
            return null;
        }

        $isPast = $endDate->lessThan($today);
        $isUpcoming = $startDate->greaterThan($today);

        return [
            'kind' => $kind,
            'label' => self::PERIOD_LABELS[$kind] ?? self::PERIOD_LABELS['krs'],
            'start' => $startDate->toDateString(),
            'end' => $endDate->toDateString(),
            'source' => $source,
            'source_ref' => $sourceRef,
            'announcement_id' => $announcementId,
            'is_open' => ! $isPast && ! $isUpcoming,
            'is_upcoming' => $isUpcoming,
            'is_past' => $isPast,
            'days_left' => $isPast ? null : (int) round($today->diffInDays($endDate)),
            'days_until_start' => $isUpcoming ? (int) round($today->diffInDays($startDate)) : null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $openPeriods
     * @return array<string, mixed>|null
     */
    private function relevantPeriod(array $openPeriods, string $statusCode): ?array
    {
        foreach (self::RELEVANT_KINDS[$statusCode] ?? self::RELEVANT_KINDS['unknown'] as $kind) {
            $candidates = array_values(array_filter($openPeriods, static fn (array $period): bool => $period['kind'] === $kind));

            if ($candidates !== []) {
                // The window that closes first is the one to act on.
                usort($candidates, static fn (array $left, array $right): int => $left['end'] <=> $right['end']);

                return $candidates[0];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $advising
     * @param  array<string, mixed>  $krs
     * @return array{0: array<string, string|null>|null, 1: 'advising'|'credit'|null}
     */
    private function dpa(?array $advising, array $krs): array
    {
        foreach (['advising' => $advising['dpa'] ?? null, 'credit' => $krs['dpa'] ?? null] as $source => $dpa) {
            if (! is_array($dpa) || array_filter($dpa, fn (mixed $value): bool => $this->stringOrNull($value) !== null) === []) {
                continue;
            }

            return [[
                'name' => $this->stringOrNull($dpa['name'] ?? null),
                'nidn' => $this->stringOrNull($dpa['nidn'] ?? null),
                'phone' => $this->stringOrNull($dpa['phone'] ?? null),
                'whatsapp' => $this->stringOrNull($dpa['whatsapp'] ?? null),
                'email' => $this->stringOrNull($dpa['email'] ?? null),
            ], $source];
        }

        return [null, null];
    }

    /**
     * Periode dibuka → KRS diajukan → Menunggu validasi DPA → Tervalidasi.
     *
     * @return list<array{key: 'period_open'|'submitted'|'validation'|'validated', label: string, state: 'done'|'current'|'todo'|'unknown'}>
     */
    private function tracker(string $statusCode, bool $isPeriodOpen, bool $hasUpcomingPeriod): array
    {
        $submittedOrLater = in_array($statusCode, ['pending_dpa', 'validated'], true);

        $periodState = match (true) {
            $isPeriodOpen, $submittedOrLater => 'done',
            $hasUpcomingPeriod => 'todo',
            default => 'unknown',
        };

        [$submitted, $validation, $validated] = match ($statusCode) {
            'not_submitted' => [$isPeriodOpen ? 'current' : 'todo', 'todo', 'todo'],
            'pending_dpa' => ['done', 'current', 'todo'],
            'validated' => ['done', 'done', 'done'],
            default => ['unknown', 'unknown', 'unknown'],
        };

        return [
            ['key' => 'period_open', 'label' => 'Periode KRS dibuka', 'state' => $periodState],
            ['key' => 'submitted', 'label' => 'KRS diajukan', 'state' => $submitted],
            ['key' => 'validation', 'label' => 'Menunggu validasi DPA', 'state' => $validation],
            ['key' => 'validated', 'label' => 'Tervalidasi DPA', 'state' => $validated],
        ];
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1 || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }

        return CarbonImmutable::create((int) $parts[1], (int) $parts[2], (int) $parts[3], 0, 0, 0, self::TIMEZONE);
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && ! in_array(trim($value), ['', '-'], true) ? trim($value) : null;
    }
}
