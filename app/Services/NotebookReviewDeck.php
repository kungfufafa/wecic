<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Notebook;
use App\Models\NotebookMessage;
use App\Models\NotebookReviewCard;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * The student's "Ulasan harian" across all their notebooks: the queue of
 * cards due today, grading a card (NotebookReviewScheduler), and saving
 * cards from a flashcards output or from the questions missed in a quiz.
 * No AI is involved and nothing counts against the daily AI budget.
 *
 * The queue holds the cards due now that were reviewed before, oldest due
 * first, then the new ones (never graded), at most `review_new_per_day`
 * new cards a day across every notebook.
 */
class NotebookReviewDeck
{
    /**
     * The most cards saved from one output.
     */
    private const MAX_IMPORT = 50;

    public function __construct(private readonly NotebookReviewScheduler $scheduler) {}

    /**
     * @return array{cards: Collection<int, NotebookReviewCard>, counts: array{due: int, reviews: int, new: int, new_today: int, new_limit: int, total: int, suspended: int}, next_due_at: string|null}
     */
    public function queue(string $ownerHash, int $limit, CarbonInterface $now): array
    {
        $counts = $this->counts($ownerHash, $now);
        $reviews = $this->dueCards($ownerHash, $now)
            ->whereNotNull('first_reviewed_at')
            ->with('notebook:id,title')
            ->orderBy('due_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();
        $newRoom = min($counts['new'], $limit - $reviews->count());
        $new = $newRoom > 0
            ? $this->dueCards($ownerHash, $now)
                ->whereNull('first_reviewed_at')
                ->with('notebook:id,title')
                ->orderBy('due_at')
                ->orderBy('id')
                ->limit($newRoom)
                ->get()
            : new Collection;

        return [
            'cards' => $reviews->concat($new),
            'counts' => $counts,
            'next_due_at' => $this->nextDueAt($ownerHash, $now),
        ];
    }

    /**
     * How many cards are waiting: `due` is what today's queue holds (reviews
     * plus the new cards still allowed today), `new` the new ones of those.
     *
     * @return array{due: int, reviews: int, new: int, new_today: int, new_limit: int, total: int, suspended: int}
     */
    public function counts(string $ownerHash, CarbonInterface $now): array
    {
        $limit = $this->newPerDay();
        $newToday = NotebookReviewCard::query()->forOwner($ownerHash)->where('first_reviewed_at', '>=', $now->copy()->startOfDay())->count();
        $reviews = $this->dueCards($ownerHash, $now)->whereNotNull('first_reviewed_at')->count();
        $new = min(max(0, $limit - $newToday), $this->dueCards($ownerHash, $now)->whereNull('first_reviewed_at')->count());

        return [
            'due' => $reviews + $new,
            'reviews' => $reviews,
            'new' => $new,
            'new_today' => $newToday,
            'new_limit' => $limit,
            'total' => NotebookReviewCard::query()->forOwner($ownerHash)->count(),
            'suspended' => NotebookReviewCard::query()->forOwner($ownerHash)->where('suspended', true)->count(),
        ];
    }

    /**
     * How many cards one notebook may hold, held (suspended) cards included.
     */
    public function cardLimit(): int
    {
        return max(1, (int) config('notebook.limits.max_review_cards', 1000));
    }

    /**
     * When the next card not due yet comes back, for "Selesai untuk sekarang".
     */
    private function nextDueAt(string $ownerHash, CarbonInterface $now): ?string
    {
        $next = NotebookReviewCard::query()->forOwner($ownerHash)
            ->where('suspended', false)
            ->where('due_at', '>', $now)
            ->min('due_at');

        return $next === null ? null : Carbon::parse($next)->toISOString();
    }

    public function grade(NotebookReviewCard $card, string $grade, CarbonInterface $now): NotebookReviewCard
    {
        $next = $this->scheduler->schedule($card->schedulingState(), $grade, $now);

        $card->update([
            'due_at' => $next['due_at'],
            'interval_days' => $next['interval_days'],
            'ease' => $next['ease'],
            'reps' => $next['reps'],
            'lapses' => $next['lapses'],
            'first_reviewed_at' => $card->first_reviewed_at ?? $now,
            'last_reviewed_at' => $now,
        ]);

        return $card;
    }

    /**
     * The item indexes "Simpan ke ulasan" takes from this output: every
     * flashcard, or only the items missed in its last practice (always for
     * a quiz), at most MAX_IMPORT. Null when the output holds no cards.
     *
     * @return list<int>|null
     */
    public function importableIndexes(NotebookMessage $message, bool $onlyMissed): ?array
    {
        $items = $this->items($message);

        if ($items === []) {
            return null;
        }

        if ($message->kind === 'quiz' || $onlyMissed) {
            $missed = array_map('intval', (array) ($message->context['practice']['missed'] ?? []));
            $indexes = array_values(array_unique(array_filter($missed, static fn (int $index): bool => isset($items[$index]))));
            sort($indexes);
        } else {
            $indexes = array_keys($items);
        }

        return array_slice($indexes, 0, self::MAX_IMPORT);
    }

    /**
     * Save these items of the output as new cards, due now. An item saved
     * before (from this output) is skipped, as is one without text; past
     * the notebook's card limit the rest are left out.
     *
     * @param  list<int>  $indexes
     * @return array{imported: int, skipped: int, full: bool}
     */
    public function import(Notebook $notebook, NotebookMessage $message, array $indexes, CarbonInterface $now): array
    {
        $items = $this->items($message);
        $saved = $notebook->reviewCards()->where('notebook_message_id', $message->id)->pluck('item_index')->map(static fn (mixed $index): int => (int) $index)->all();
        $room = max(0, $this->cardLimit() - $notebook->reviewCards()->count());
        $imported = 0;
        $skipped = 0;
        $full = false;

        foreach ($indexes as $index) {
            $card = $this->card($message, is_array($items[$index] ?? null) ? $items[$index] : []);

            if (in_array($index, $saved, true) || $card === null) {
                $skipped++;

                continue;
            }

            if ($imported >= $room) {
                $full = true;

                break;
            }

            $created = $notebook->reviewCards()->createOrFirst(
                ['notebook_message_id' => $message->id, 'item_index' => $index],
                [...$card, 'due_at' => $now, 'ease' => NotebookReviewScheduler::DEFAULT_EASE],
            );
            $created->wasRecentlyCreated ? $imported++ : $skipped++;
        }

        return ['imported' => $imported, 'skipped' => $skipped, 'full' => $full];
    }

    /**
     * A card from one item: a flashcard's sides, or a quiz question with its
     * correct option and explanation on the back. Its citations are the
     * output's citations the item points to, so the card stands on its own.
     *
     * @param  array<string, mixed>  $item
     * @return array{front: string, back: string, citations: list<array<string, mixed>>, topic: string|null, meeting_number: int|null}|null
     */
    private function card(NotebookMessage $message, array $item): ?array
    {
        if ($message->kind === 'quiz') {
            $options = is_array($item['options'] ?? null) ? array_values($item['options']) : [];
            $answer = is_numeric($item['answer_index'] ?? null) ? ($options[(int) $item['answer_index']] ?? null) : null;
            $front = trim((string) ($item['question'] ?? ''));
            $back = trim(implode("\n\n", array_filter([
                is_string($answer) && trim($answer) !== '' ? 'Jawaban: '.trim($answer) : null,
                trim((string) ($item['explanation'] ?? '')),
            ], static fn (?string $part): bool => $part !== null && $part !== '')));
        } else {
            $front = trim((string) ($item['front'] ?? ''));
            $back = trim((string) ($item['back'] ?? ''));
        }

        if ($front === '' || $back === '') {
            return null;
        }

        $numbers = array_map('intval', array_filter((array) ($item['citations'] ?? []), 'is_numeric'));
        $citations = array_values(array_filter($message->citations ?? [], static fn (array $citation): bool => in_array($citation['n'], $numbers, true)));
        $topic = trim((string) ($item['topic'] ?? ''));

        return [
            'front' => mb_substr($front, 0, NotebookReviewCard::MAX_SIDE_CHARS),
            'back' => mb_substr($back, 0, NotebookReviewCard::MAX_SIDE_CHARS),
            'citations' => $citations,
            'topic' => $topic === '' ? null : mb_substr($topic, 0, 120),
            'meeting_number' => $this->meetingNumber($message, $citations),
        ];
    }

    /**
     * The meeting of the first cited source that has one.
     *
     * @param  list<array<string, mixed>>  $citations
     */
    private function meetingNumber(NotebookMessage $message, array $citations): ?int
    {
        $meetings = [];

        foreach ($message->context['sources'] ?? [] as $source) {
            if ($source['meeting_number'] !== null) {
                $meetings[$source['id']] = $source['meeting_number'];
            }
        }

        foreach ($citations as $citation) {
            $number = $meetings[(int) ($citation['source_id'] ?? 0)] ?? null;

            if ($number !== null) {
                return $number;
            }
        }

        return null;
    }

    /**
     * @return array<int, mixed>
     */
    private function items(NotebookMessage $message): array
    {
        $data = json_decode($message->content, true);

        return is_array($data) && array_is_list($data) ? $data : [];
    }

    /**
     * @return Builder<NotebookReviewCard>
     */
    private function dueCards(string $ownerHash, CarbonInterface $now): Builder
    {
        return NotebookReviewCard::query()->forOwner($ownerHash)->due($now);
    }

    private function newPerDay(): int
    {
        return max(0, (int) config('notebook.limits.review_new_per_day', 20));
    }
}
