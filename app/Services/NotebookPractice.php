<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\NotebookMessage;

/**
 * A student's practice on a quiz or a set of flashcards, kept lightly in the
 * output's own `context.practice` (no table, no AI, no daily budget): the
 * last score, which items were missed, and which items the student marked
 * as wrong ("Soal ini salah?"), which leave the score. A quiz attempt
 * must carry its choices: its score is counted here from each item's
 * `answer_index`, never taken from the client.
 *
 * The missed items also seed a new quiz ("Buat latihan dari yang salah"):
 * missedFocus() turns their text into the studio focus.
 */
class NotebookPractice
{
    /**
     * Outputs that can be practised.
     */
    private const KINDS = ['quiz', 'flashcards'];

    /**
     * The most items an attempt may list (a quiz has at most 15, flashcards
     * 20; older outputs a few more).
     */
    public const MAX_ITEMS = 30;

    /**
     * The longest focus missedFocus() builds, as NotebookStudioRequest allows
     * for a typed focus.
     */
    private const MAX_FOCUS_CHARS = 1500;

    /**
     * Why this attempt cannot be kept on this message, or null when it can.
     *
     * @param  array{score?: int, total?: int, missed?: list<int>, choices?: list<int|null>, flagged?: list<int>}  $practice
     */
    public function refusal(NotebookMessage $message, array $practice): ?string
    {
        if (! $this->isPracticeable($message)) {
            return 'Hasil latihan hanya bisa disimpan untuk kuis atau flashcard.';
        }

        $count = count($this->items($message));
        $indexes = [...($practice['missed'] ?? []), ...($practice['flagged'] ?? [])];

        if (array_filter($indexes, static fn (int $index): bool => $index >= $count) !== []
            || count($practice['choices'] ?? []) > $count) {
            return 'Nomor soal tidak ada di latihan ini.';
        }

        if (! isset($practice['choices']) && ($practice['total'] ?? 0) > $count) {
            return 'Jumlah soal melebihi isi latihan ini.';
        }

        if ($message->kind !== 'quiz' && isset($practice['choices'])) {
            return 'Pilihan jawaban hanya untuk kuis.';
        }

        if ($message->kind === 'quiz' && ! isset($practice['choices'])) {
            return 'Kirim pilihan jawaban untuk kuis.';
        }

        return null;
    }

    /**
     * Keep the attempt in `context.practice`, with the score counted here
     * when the choices came along. Check refusal() first.
     *
     * @param  array{score?: int, total?: int, missed?: list<int>, choices?: list<int|null>, flagged?: list<int>}  $practice
     * @return array{score: int, total: int, missed: list<int>, flagged: list<int>, choices?: list<int|null>, at: string}
     */
    public function record(NotebookMessage $message, array $practice): array
    {
        $flagged = $this->indexes($practice['flagged'] ?? []);
        $stored = isset($practice['choices'])
            ? $this->scored($this->items($message), $practice['choices'], $flagged)
            : [
                'score' => min((int) ($practice['score'] ?? 0), (int) ($practice['total'] ?? 0)),
                'total' => (int) ($practice['total'] ?? 0),
                'missed' => $this->indexes(array_values(array_diff($practice['missed'] ?? [], $flagged))),
                'flagged' => $flagged,
            ];
        $stored['at'] = now()->toISOString();

        $message->update(['context' => [...($message->context ?? []), 'practice' => $stored]]);

        return $stored;
    }

    /**
     * The studio focus for a new quiz on the items the student missed, from
     * their own text (a question, or a flashcard's front), at most
     * MAX_FOCUS_CHARS long. Null when none of the indexes is an item.
     *
     * @param  list<int>  $missed
     */
    public function missedFocus(NotebookMessage $message, array $missed): ?string
    {
        $items = $this->items($message);
        $lines = [];

        foreach ($this->indexes($missed) as $index) {
            $item = $items[$index] ?? null;
            $text = is_array($item) ? trim((string) ($item['question'] ?? $item['front'] ?? '')) : '';

            if ($text !== '') {
                $lines[] = '- '.preg_replace('/\s+/u', ' ', $text);
            }
        }

        if ($lines === []) {
            return null;
        }

        $focus = "Latihan ulang untuk konsep yang belum dikuasai mahasiswa. Buat soal baru yang menguji konsep yang sama dari sudut lain; jangan menyalin soal-soal ini:\n".implode("\n", $lines);

        return mb_strlen($focus) > self::MAX_FOCUS_CHARS ? rtrim(mb_substr($focus, 0, self::MAX_FOCUS_CHARS - 1)).'…' : $focus;
    }

    public function isPracticeable(NotebookMessage $message): bool
    {
        return in_array($message->kind, self::KINDS, true) && $message->role === NotebookMessage::ROLE_ASSISTANT;
    }

    /**
     * @param  list<mixed>  $items
     * @param  list<int|null>  $choices
     * @param  list<int>  $flagged
     * @return array{score: int, total: int, missed: list<int>, flagged: list<int>, choices: list<int|null>}
     */
    private function scored(array $items, array $choices, array $flagged): array
    {
        $score = 0;
        $missed = [];

        foreach ($items as $index => $item) {
            if (in_array($index, $flagged, true)) {
                continue;
            }

            $choice = $choices[$index] ?? null;
            $answer = is_array($item) && is_numeric($item['answer_index'] ?? null) ? (int) $item['answer_index'] : null;

            if ($choice !== null && $answer !== null && $choice === $answer) {
                $score++;
            } else {
                $missed[] = $index;
            }
        }

        return [
            'score' => $score,
            'total' => count($items) - count($flagged),
            'missed' => $missed,
            'flagged' => $flagged,
            'choices' => array_map(static fn (mixed $choice): ?int => $choice === null ? null : (int) $choice, $choices),
        ];
    }

    /**
     * The output's items, or none when it holds no list.
     *
     * @return list<mixed>
     */
    private function items(NotebookMessage $message): array
    {
        $data = json_decode($message->content, true);

        return is_array($data) && array_is_list($data) ? $data : [];
    }

    /**
     * @param  array<array-key, mixed>  $indexes
     * @return list<int>
     */
    private function indexes(array $indexes): array
    {
        $unique = array_values(array_unique(array_map('intval', $indexes)));
        sort($unique);

        return $unique;
    }
}
