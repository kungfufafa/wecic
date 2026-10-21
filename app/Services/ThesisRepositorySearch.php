<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Str;

/**
 * Search, filters, facets and title similarity over the thesis repository
 * snapshot (F47). The portal's own search form is empty, so this is the only
 * way to search the repository; it never talks to the portal.
 *
 * - Text is compared lowercased and transliterated to ASCII.
 * - Every query token must match the title, an advisor or the author (or
 *   NIM); the title weighs 3, advisors 2, the author 1. The program has its
 *   own filter.
 * - Advisors are matched by a key without academic titles: everything after
 *   the first comma and every dot is dropped ("Dr. Ana, M.Kom." → "dr ana").
 * - Facets count the records that match every other filter, so a filter
 *   sheet shows how many results each option would give.
 */
class ThesisRepositorySearch
{
    public const DEFAULT_PER_PAGE = 20;

    public const MIN_PER_PAGE = 10;

    public const MAX_PER_PAGE = 50;

    public const SORTS = ['terbaru', 'judul', 'relevansi'];

    public const SIMILAR_LIMIT = 5;

    private const WEIGHT_TITLE = 3;

    private const WEIGHT_ADVISOR = 2;

    private const WEIGHT_AUTHOR = 1;

    private const ADVISOR_FACET_LIMIT = 60;

    /**
     * Indonesian (and English) function words left out of similarity and topic keywords.
     */
    private const STOPWORDS = [
        'a', 'ada', 'adalah', 'akan', 'an', 'and', 'antara', 'atas', 'atau', 'bagi', 'dalam', 'dan', 'dari', 'dengan', 'di',
        'for', 'guna', 'in', 'ini', 'itu', 'kasus', 'ke', 'kepada', 'menggunakan', 'metode', 'of', 'oleh', 'on', 'pada',
        'para', 'sebagai', 'secara', 'serta', 'studi', 'tentang', 'terhadap', 'the', 'to', 'untuk', 'using', 'with', 'yang',
    ];

    /**
     * @param  list<array<string, mixed>>  $records
     * @param  array{q?: string|null, prodi?: string|null, ta?: string|null, pembimbing?: string|null, sort?: string|null, page?: int|null, per_page?: int|null}  $filters
     * @return array{items: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function search(array $records, array $filters): array
    {
        $tokens = $this->tokens((string) ($filters['q'] ?? ''));
        $programKey = $this->filterKey($filters['prodi'] ?? null, static fn (string $value): string => self::programKey($value));
        $academicYear = is_string($filters['ta'] ?? null) && $filters['ta'] !== '' ? $filters['ta'] : null;
        $advisorKey = $this->filterKey($filters['pembimbing'] ?? null, static fn (string $value): string => self::advisorKey($value));
        $sort = in_array($filters['sort'] ?? null, self::SORTS, true) ? $filters['sort'] : ($tokens !== [] ? 'relevansi' : 'terbaru');
        $perPage = max(self::MIN_PER_PAGE, min(self::MAX_PER_PAGE, (int) ($filters['per_page'] ?? self::DEFAULT_PER_PAGE)));
        $page = max(1, (int) ($filters['page'] ?? 1));

        $indexed = [];

        foreach (array_values($records) as $position => $record) {
            if (! is_array($record) || ! is_string($record['title'] ?? null)) {
                continue;
            }

            $entry = $this->indexRecord($record, $position);
            $entry['score'] = $tokens === [] ? 0 : $this->score($entry, $tokens);

            if ($tokens === [] || $entry['score'] !== null) {
                $indexed[] = $entry;
            }
        }

        $matches = fn (array $entry, ?string $skip = null): bool => ($skip === 'prodi' || $programKey === null || $entry['program_key'] === $programKey)
            && ($skip === 'ta' || $academicYear === null || $entry['record']['academic_year'] === $academicYear)
            && ($skip === 'pembimbing' || $advisorKey === null || in_array($advisorKey, $entry['advisor_keys'], true));

        $results = array_values(array_filter($indexed, $matches));
        $results = $this->sort($results, $sort);
        $total = count($results);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $items = array_map(
            fn (array $entry): array => $this->presentEntry($entry),
            array_slice($results, ($page - 1) * $perPage, $perPage)
        );

        return [
            'items' => $items,
            'meta' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'last_page' => $lastPage,
                'has_more' => $page < $lastPage,
                'sort' => $sort,
                'query' => $tokens === [] ? null : (string) $filters['q'],
                'tokens' => $tokens,
                'filters' => [
                    'prodi' => $programKey === null ? null : $filters['prodi'],
                    'ta' => $academicYear,
                    'pembimbing' => $advisorKey === null ? null : $filters['pembimbing'],
                ],
                'facets' => [
                    'prodi' => $this->facet(array_filter($indexed, fn (array $entry): bool => $matches($entry, 'prodi')), 'prodi'),
                    'ta' => $this->facet(array_filter($indexed, fn (array $entry): bool => $matches($entry, 'ta')), 'ta'),
                    'pembimbing' => $this->facet(array_filter($indexed, fn (array $entry): bool => $matches($entry, 'pembimbing')), 'pembimbing'),
                ],
            ],
        ];
    }

    /**
     * Records whose title is closest to a planned title: the mean of the word
     * Jaccard (stopwords left out) and the character-trigram Jaccard. This is
     * a hint for choosing a topic, not a plagiarism check.
     *
     * @param  list<array<string, mixed>>  $records
     * @return list<array<string, mixed>>
     */
    public function similar(array $records, string $title, int $limit = self::SIMILAR_LIMIT): array
    {
        $words = $this->contentWords($title);
        $trigrams = $this->trigrams($title);

        if ($words === [] && $trigrams === []) {
            return [];
        }

        $scored = [];

        foreach (array_values($records) as $position => $record) {
            if (! is_array($record) || ! is_string($record['title'] ?? null) || $record['title'] === '') {
                continue;
            }

            $similarity = round(
                ($this->jaccard($words, $this->contentWords($record['title'])) + $this->jaccard($trigrams, $this->trigrams($record['title']))) / 2,
                4
            );

            if ($similarity > 0) {
                $entry = $this->indexRecord($record, $position);
                $scored[] = [...$this->presentEntry($entry), 'similarity' => $similarity, 'similarity_percent' => (int) round($similarity * 100)];
            }
        }

        usort($scored, static fn (array $left, array $right): int => [$right['similarity'], $left['no'] ?? 0] <=> [$left['similarity'], $right['no'] ?? 0]);

        return array_slice($scored, 0, max(1, $limit));
    }

    /**
     * Most frequent topic words of the titles (stopwords left out)
     *
     * @param  list<array<string, mixed>>  $records
     * @return list<array{word: string, count: int}>
     */
    public function keywords(array $records, int $limit = 10): array
    {
        $counts = [];

        foreach ($records as $record) {
            if (! is_array($record) || ! is_string($record['title'] ?? null)) {
                continue;
            }

            foreach (array_unique($this->contentWords($record['title'])) as $word) {
                if (mb_strlen($word) >= 4 && preg_match('/^\d+$/', $word) !== 1) {
                    $counts[$word] = ($counts[$word] ?? 0) + 1;
                }
            }
        }

        arsort($counts);
        $keywords = [];

        foreach (array_slice($counts, 0, $limit, true) as $word => $count) {
            $keywords[] = ['word' => (string) $word, 'count' => $count];
        }

        return $keywords;
    }

    public static function normalize(string $text): string
    {
        $text = Str::lower(Str::ascii(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $text));
    }

    /**
     * Advisor name without academic titles: "Dr. Ana Putri, S.Kom., M.Kom." → "dr ana putri"
     */
    public static function advisorKey(string $name): string
    {
        return self::normalize(str_replace('.', '', Str::before($name, ',')));
    }

    public static function programKey(string $program): string
    {
        return self::normalize($program);
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function indexRecord(array $record, int $position): array
    {
        $advisors = array_values(array_filter(
            [$record['advisor_1'] ?? null, $record['advisor_2'] ?? null],
            static fn (mixed $advisor): bool => is_string($advisor) && trim($advisor) !== ''
        ));

        return [
            'record' => $record,
            'position' => $position,
            'program_key' => self::programKey((string) ($record['program'] ?? '')),
            'advisor_keys' => array_values(array_unique(array_map(static fn (string $advisor): string => self::advisorKey($advisor), $advisors))),
            'advisors' => $advisors,
            'title_text' => self::normalize((string) $record['title']),
            'advisor_text' => self::normalize(implode(' ', $advisors)),
            'author_text' => self::normalize(implode(' ', array_filter([(string) ($record['author'] ?? ''), (string) ($record['nim'] ?? '')]))),
        ];
    }

    /**
     * Weighted score, or null when a token matches nothing
     *
     * @param  array<string, mixed>  $entry
     * @param  list<string>  $tokens
     */
    private function score(array $entry, array $tokens): ?int
    {
        $score = 0;

        foreach ($tokens as $token) {
            $tokenScore = 0;
            $tokenScore += str_contains($entry['title_text'], $token) ? self::WEIGHT_TITLE : 0;
            $tokenScore += str_contains($entry['advisor_text'], $token) ? self::WEIGHT_ADVISOR : 0;
            $tokenScore += str_contains($entry['author_text'], $token) ? self::WEIGHT_AUTHOR : 0;

            if ($tokenScore === 0) {
                return null;
            }

            $score += $tokenScore;
        }

        // The whole query as one phrase in the title ranks first.
        if (count($tokens) > 1 && str_contains($entry['title_text'], implode(' ', $tokens))) {
            $score += self::WEIGHT_TITLE * count($tokens);
        }

        return $score;
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return list<array<string, mixed>>
     */
    private function sort(array $entries, string $sort): array
    {
        $newest = static fn (array $left, array $right): int => [(string) ($right['record']['academic_year'] ?? ''), $left['position']]
            <=> [(string) ($left['record']['academic_year'] ?? ''), $right['position']];

        usort($entries, match ($sort) {
            'judul' => static fn (array $left, array $right): int => [$left['title_text'], $left['position']] <=> [$right['title_text'], $right['position']],
            'relevansi' => static fn (array $left, array $right): int => $right['score'] <=> $left['score'] ?: $newest($left, $right),
            default => $newest,
        });

        return $entries;
    }

    /**
     * @param  array<array<string, mixed>>  $entries
     * @return list<array{value: string, key: string, count: int}>
     */
    private function facet(array $entries, string $dimension): array
    {
        $buckets = [];

        foreach ($entries as $entry) {
            $values = match ($dimension) {
                'prodi' => $entry['program_key'] === '' ? [] : [$entry['program_key'] => (string) $entry['record']['program']],
                'ta' => is_string($entry['record']['academic_year'] ?? null) ? [$entry['record']['academic_year'] => $entry['record']['academic_year']] : [],
                default => array_combine($entry['advisor_keys'], array_slice($this->advisorLabels($entry['advisors']), 0, count($entry['advisor_keys']))) ?: [],
            };

            foreach ($values as $key => $label) {
                $key = (string) $key;
                $buckets[$key] ??= ['value' => $label, 'key' => $key, 'count' => 0];
                $buckets[$key]['count']++;
            }
        }

        $buckets = array_values($buckets);

        usort($buckets, match ($dimension) {
            'ta' => static fn (array $left, array $right): int => strcmp($right['key'], $left['key']),
            default => static fn (array $left, array $right): int => [$right['count'], $left['value']] <=> [$left['count'], $right['value']],
        });

        return $dimension === 'pembimbing' ? array_slice($buckets, 0, self::ADVISOR_FACET_LIMIT) : $buckets;
    }

    /**
     * One label per distinct advisor key, in the record's order
     *
     * @param  list<string>  $advisors
     * @return list<string>
     */
    private function advisorLabels(array $advisors): array
    {
        $labels = [];

        foreach ($advisors as $advisor) {
            $labels[self::advisorKey($advisor)] ??= trim($advisor);
        }

        return array_values($labels);
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function presentEntry(array $entry): array
    {
        return [
            ...$entry['record'],
            'program_key' => $entry['program_key'],
            'advisors' => array_map(
                static fn (string $advisor): array => ['name' => $advisor, 'key' => self::advisorKey($advisor)],
                $entry['advisors']
            ),
            'score' => $entry['score'] ?? null,
        ];
    }

    /**
     * @param  callable(string): string  $normalize
     */
    private function filterKey(mixed $value, callable $normalize): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $key = $normalize($value);

        return $key === '' ? null : $key;
    }

    /**
     * @return list<string>
     */
    private function tokens(string $query): array
    {
        $normalized = self::normalize($query);

        return $normalized === '' ? [] : array_values(array_unique(explode(' ', $normalized)));
    }

    /**
     * @return list<string>
     */
    private function contentWords(string $text): array
    {
        $words = explode(' ', self::normalize($text));

        return array_values(array_unique(array_filter(
            $words,
            static fn (string $word): bool => $word !== '' && ! in_array($word, self::STOPWORDS, true)
        )));
    }

    /**
     * @return list<string>
     */
    private function trigrams(string $text): array
    {
        $normalized = self::normalize($text);

        if ($normalized === '') {
            return [];
        }

        $padded = " {$normalized} ";
        $trigrams = [];

        for ($index = 0, $length = strlen($padded) - 2; $index < $length; $index++) {
            $trigrams[substr($padded, $index, 3)] = true;
        }

        return array_keys($trigrams);
    }

    /**
     * @param  list<string>  $left
     * @param  list<string>  $right
     */
    private function jaccard(array $left, array $right): float
    {
        if ($left === [] || $right === []) {
            return 0.0;
        }

        $intersection = count(array_intersect($left, $right));
        $union = count(array_unique([...$left, ...$right]));

        return $union === 0 ? 0.0 : $intersection / $union;
    }
}
