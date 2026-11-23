<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\NotebookReviewScheduler;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NotebookReviewSchedulerTest extends TestCase
{
    private NotebookReviewScheduler $scheduler;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-23 08:00:00');
        $this->scheduler = new NotebookReviewScheduler;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @return array<string, array{string, int, float}>
     */
    public static function firstReviews(): array
    {
        return [
            'sulit' => ['sulit', 1, 2.35],
            'bisa' => ['bisa', 1, 2.5],
            'mudah' => ['mudah', 3, 2.65],
        ];
    }

    #[DataProvider('firstReviews')]
    public function test_a_new_card_comes_back_after_its_first_interval(string $grade, int $days, float $ease): void
    {
        $next = $this->scheduler->schedule($this->card(), $grade, Carbon::now());

        $this->assertSame($days, $next['interval_days']);
        $this->assertTrue($next['due_at']->equalTo(Carbon::now()->addDays($days)));
        $this->assertSame($ease, $next['ease']);
        $this->assertSame(1, $next['reps']);
        $this->assertSame(0, $next['lapses']);
    }

    /**
     * @return array<string, array{string, int, float}>
     */
    public static function learnedReviews(): array
    {
        // A 10-day interval at ease 2.5: ×1.2, ×2.5, ×2.5×1.3.
        return [
            'sulit' => ['sulit', 12, 2.35],
            'bisa' => ['bisa', 25, 2.5],
            'mudah' => ['mudah', 33, 2.65],
        ];
    }

    #[DataProvider('learnedReviews')]
    public function test_a_learned_card_grows_its_interval_by_its_grade(string $grade, int $days, float $ease): void
    {
        $next = $this->scheduler->schedule($this->card(interval: 10, reps: 3), $grade, Carbon::now());

        $this->assertSame($days, $next['interval_days']);
        $this->assertTrue($next['due_at']->equalTo(Carbon::now()->addDays($days)));
        $this->assertSame($ease, $next['ease']);
        $this->assertSame(4, $next['reps']);
    }

    public function test_lagi_brings_the_card_back_in_ten_minutes_as_a_lapse_and_resets_it(): void
    {
        $next = $this->scheduler->schedule($this->card(interval: 10, reps: 3, lapses: 1), 'lagi', Carbon::now());

        $this->assertTrue($next['due_at']->equalTo(Carbon::now()->addMinutes(10)));
        $this->assertSame(0, $next['interval_days']);
        $this->assertSame(0, $next['reps']);
        $this->assertSame(2, $next['lapses']);
        $this->assertSame(2.3, $next['ease']);

        // After the lapse the card starts over: bisa is 1 day again.
        $again = $this->scheduler->schedule([...$next, 'due_at' => null], 'bisa', Carbon::now());
        $this->assertSame(1, $again['interval_days']);
    }

    public function test_lagi_on_a_card_never_learned_is_not_counted_as_a_lapse(): void
    {
        $next = $this->scheduler->schedule($this->card(), 'lagi', Carbon::now());

        $this->assertSame(0, $next['lapses']);
        $this->assertSame(2.3, $next['ease']);
    }

    public function test_the_ease_never_drops_below_one_point_three(): void
    {
        $card = $this->card(interval: 5, reps: 2, ease: 1.4);

        $this->assertSame(1.3, $this->scheduler->schedule($card, 'lagi', Carbon::now())['ease']);
        $this->assertSame(1.3, $this->scheduler->schedule($card, 'sulit', Carbon::now())['ease']);
        $this->assertSame(1.3, $this->scheduler->schedule($this->card(ease: 1.0), 'bisa', Carbon::now())['ease']);
    }

    public function test_every_grade_moves_a_short_interval_forward_and_mudah_stays_ahead_of_bisa(): void
    {
        $card = $this->card(interval: 1, reps: 1, ease: 1.3);
        $sulit = $this->scheduler->schedule($card, 'sulit', Carbon::now())['interval_days'];
        $bisa = $this->scheduler->schedule($card, 'bisa', Carbon::now())['interval_days'];
        $mudah = $this->scheduler->schedule($card, 'mudah', Carbon::now())['interval_days'];

        $this->assertGreaterThanOrEqual(2, $sulit);
        $this->assertLessThanOrEqual($bisa, $sulit);
        $this->assertGreaterThan($bisa, $mudah);
    }

    public function test_no_interval_is_longer_than_a_year(): void
    {
        $next = $this->scheduler->schedule($this->card(interval: 300, reps: 9, ease: 3.0), 'mudah', Carbon::now());

        $this->assertSame(NotebookReviewScheduler::MAX_INTERVAL_DAYS, $next['interval_days']);
    }

    public function test_the_preview_says_in_how_many_minutes_each_grade_brings_the_card_back(): void
    {
        $this->assertSame(
            ['lagi' => 10, 'sulit' => 1440, 'bisa' => 1440, 'mudah' => 3 * 1440],
            $this->scheduler->preview($this->card(), Carbon::now()),
        );
    }

    /**
     * @return array{interval_days: int, ease: float, reps: int, lapses: int}
     */
    private function card(int $interval = 0, int $reps = 0, int $lapses = 0, float $ease = 2.5): array
    {
        return ['interval_days' => $interval, 'ease' => $ease, 'reps' => $reps, 'lapses' => $lapses];
    }
}
