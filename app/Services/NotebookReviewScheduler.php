<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * When an "Ulasan harian" card comes back, by a variant of SM-2. Pure: it
 * reads only the card's state and the time it is given.
 *
 * - `lagi`: back in 10 minutes, the interval starts over, and the ease drops
 *   by 0.2. A card that had been learned (reps > 0) counts a lapse.
 * - A card not learned yet (reps 0) comes back after 1 day (`sulit`, `bisa`)
 *   or 3 days (`mudah`).
 * - A learned card's interval is multiplied by 1.2 (`sulit`), its ease
 *   (`bisa`) or its ease × 1.3 (`mudah`), and always grows by at least a
 *   day; `mudah` lands at least a day after `bisa`.
 * - `sulit` lowers the ease by 0.15 and `mudah` raises it by 0.15. The ease
 *   never drops below 1.3, and no interval is longer than MAX_INTERVAL_DAYS.
 */
class NotebookReviewScheduler
{
    public const GRADES = ['lagi', 'sulit', 'bisa', 'mudah'];

    public const DEFAULT_EASE = 2.5;

    private const MIN_EASE = 1.3;

    public const MAX_INTERVAL_DAYS = 365;

    private const RELEARN_MINUTES = 10;

    /**
     * The card's state after this grade.
     *
     * @param  array{interval_days: int, ease: float, reps: int, lapses: int}  $card
     * @return array{due_at: CarbonImmutable, interval_days: int, ease: float, reps: int, lapses: int}
     */
    public function schedule(array $card, string $grade, CarbonInterface $now): array
    {
        $now = CarbonImmutable::instance($now);
        $ease = max(self::MIN_EASE, (float) $card['ease']);
        $interval = max(0, (int) $card['interval_days']);
        $reps = max(0, (int) $card['reps']);
        $lapses = max(0, (int) $card['lapses']);

        if ($grade === 'lagi') {
            return [
                'due_at' => $now->addMinutes(self::RELEARN_MINUTES),
                'interval_days' => 0,
                'ease' => $this->ease($ease - 0.2),
                'reps' => 0,
                'lapses' => $reps > 0 ? $lapses + 1 : $lapses,
            ];
        }

        $days = $reps === 0 || $interval === 0
            ? ($grade === 'mudah' ? 3 : 1)
            : $this->grown($interval, $ease, $grade);
        $days = min(self::MAX_INTERVAL_DAYS, $days);

        return [
            'due_at' => $now->addDays($days),
            'interval_days' => $days,
            'ease' => $this->ease($ease + match ($grade) {
                'sulit' => -0.15,
                'mudah' => 0.15,
                default => 0.0,
            }),
            'reps' => $reps + 1,
            'lapses' => $lapses,
        ];
    }

    /**
     * In how many minutes the card comes back for each grade, for the
     * buttons ("Bisa · 3 hari").
     *
     * @param  array{interval_days: int, ease: float, reps: int, lapses: int}  $card
     * @return array{lagi: int, sulit: int, bisa: int, mudah: int}
     */
    public function preview(array $card, CarbonInterface $now): array
    {
        $now = CarbonImmutable::instance($now);
        $minutes = [];

        foreach (self::GRADES as $grade) {
            $minutes[$grade] = (int) round($now->diffInMinutes($this->schedule($card, $grade, $now)['due_at']));
        }

        /** @var array{lagi: int, sulit: int, bisa: int, mudah: int} $minutes */
        return $minutes;
    }

    private function grown(int $interval, float $ease, string $grade): int
    {
        $good = max($interval + 1, (int) round($interval * $ease));

        return match ($grade) {
            'sulit' => min($good, max($interval + 1, (int) round($interval * 1.2))),
            'mudah' => max($good + 1, (int) round($interval * $ease * 1.3)),
            default => $good,
        };
    }

    private function ease(float $ease): float
    {
        return round(max(self::MIN_EASE, $ease), 2);
    }
}
