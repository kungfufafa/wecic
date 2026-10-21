<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\PortalException;
use App\Services\Extractors\AnnouncementExtractor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * The latest announcements across the first portal pages (F03), merged,
 * de-duplicated and sorted newest first, with every upcoming date they mention.
 *
 * Time-dependent flags (`is_future`, `days_left`, `is_ongoing`) are computed
 * on every call, so cached pages never serve stale countdowns.
 */
class PortalAnnouncementFeed
{
    public const DEFAULT_PAGES = 3;

    public const MAX_PAGES = 10;

    /**
     * Two announcements with the same title published this close together
     * are treated as the same announcement posted twice.
     */
    private const DUPLICATE_WINDOW_DAYS = 7;

    private const PORTAL_TIMEZONE = 'Asia/Jakarta';

    public function __construct(private readonly AnnouncementExtractor $announcementExtractor) {}

    /**
     * Fetch pages 1..$pages (bounded by the portal's last page) and merge them
     *
     * Page 1 must succeed; a later page that fails ends the walk and marks
     * the feed incomplete, except an expired session, which is rethrown so
     * the student logs in again. With `$knownIds` (ids from the previous snapshot)
     * the walk stops after the first page that contains one of them.
     *
     * @param  list<string>  $knownIds
     * @return array{
     *     items: list<array<string, mixed>>,
     *     paging: array{pages_loaded: int, last_page: int|null, total: int|null, has_more: bool, next_page: int|null, complete: bool, stopped_at_known: bool},
     *     notice: array{text: string, dates: list<string>, is_future: bool, kind: string}|null,
     *     upcoming_deadlines: list<array{announcement_id: string, title: string, category: string, category_label: string, date: string, start: string|null, type: string, context: string, days_left: int, is_ongoing: bool}>,
     *     generated_at: string,
     *     _sync: array{data_origin: string}
     * }
     */
    public function fetch(string $sessionToken, int $pages = self::DEFAULT_PAGES, array $knownIds = []): array
    {
        $pages = max(1, min($pages, self::MAX_PAGES));
        $first = $this->announcementExtractor->extractAnnouncementsData($sessionToken, 1);
        $lastPage = $first['paging']['last_page'] ?? null;
        $limit = $lastPage === null ? 1 : min($pages, (int) $lastPage);
        $known = array_fill_keys($knownIds, true);
        $results = [1 => $first];
        $complete = true;
        $stoppedAtKnown = false;

        for ($page = 2; $page <= $limit; $page++) {
            if ($known !== [] && $this->containsKnownId($results[$page - 1]['items'] ?? [], $known)) {
                $stoppedAtKnown = true;

                break;
            }

            try {
                $results[$page] = $this->announcementExtractor->extractAnnouncementsData($sessionToken, $page);
            } catch (PortalException $e) {
                if (PortalException::indicatesExpiredSession($e)) {
                    throw $e;
                }

                Log::warning('Announcement feed stopped early', [
                    'page' => $page,
                    'error' => $e->getMessage(),
                ]);
                $complete = false;

                break;
            }
        }

        $today = CarbonImmutable::instance(now())->setTimezone(self::PORTAL_TIMEZONE)->startOfDay();
        $items = $this->markDuplicates($this->sortNewestFirst($this->mergePages($results, $today)));
        $pagesLoaded = count($results);
        $hasMore = $lastPage !== null && $pagesLoaded < (int) $lastPage;

        return [
            'items' => $items,
            'paging' => [
                'pages_loaded' => $pagesLoaded,
                'last_page' => $lastPage,
                'total' => $first['paging']['total'] ?? null,
                'has_more' => $hasMore,
                'next_page' => $hasMore ? $pagesLoaded + 1 : null,
                'complete' => $complete,
                'stopped_at_known' => $stoppedAtKnown,
            ],
            'notice' => $this->refreshNotice($first['notice'] ?? null, $today),
            'upcoming_deadlines' => $this->upcomingDeadlines($items, $today),
            'generated_at' => now()->toISOString(),
            '_sync' => [
                'data_origin' => $this->dataOrigin($results),
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     * @return list<array<string, mixed>>
     */
    private function mergePages(array $results, CarbonImmutable $today): array
    {
        $items = [];

        foreach ($results as $page => $result) {
            foreach ($result['items'] ?? [] as $item) {
                $id = (string) ($item['id'] ?? '');

                if ($id === '' || isset($items[$id])) {
                    continue;
                }

                $item['page'] = $page;
                $item['deadlines'] = array_map(
                    fn (array $deadline): array => array_merge($deadline, ['is_future' => $this->isOnOrAfter($deadline['date'] ?? null, $today)]),
                    $item['deadlines'] ?? []
                );
                $items[$id] = $item;
            }
        }

        return array_values($items);
    }

    /**
     * Stable: announcements from the same day keep the portal's order.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function sortNewestFirst(array $items): array
    {
        usort($items, static fn (array $left, array $right): int => ($right['published_date'] ?? '') <=> ($left['published_date'] ?? ''));

        return $items;
    }

    /**
     * Set `duplicate_of` to the id of the newest announcement with the same
     * title published within a week (portal staff often post one twice).
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function markDuplicates(array $items): array
    {
        $firstByTitle = [];

        foreach ($items as $index => $item) {
            $title = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) ($item['title'] ?? ''))));
            $original = $title === '' ? null : ($firstByTitle[$title] ?? null);
            $items[$index]['duplicate_of'] = null;

            if ($original !== null && $this->publishedClose($original['published_date'], $item['published_date'] ?? null)) {
                $items[$index]['duplicate_of'] = $original['id'];

                continue;
            }

            if ($title !== '') {
                $firstByTitle[$title] = ['id' => $item['id'], 'published_date' => $item['published_date'] ?? null];
            }
        }

        return $items;
    }

    private function publishedClose(?string $left, ?string $right): bool
    {
        if ($left === null || $right === null) {
            return $left === $right;
        }

        $leftDate = $this->date($left);
        $rightDate = $this->date($right);

        return $leftDate !== null && $rightDate !== null
            && abs($leftDate->diffInDays($rightDate)) <= self::DUPLICATE_WINDOW_DAYS;
    }

    /**
     * Dates from today on, soonest first, skipping announcements marked as duplicates
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array{announcement_id: string, title: string, category: string, category_label: string, date: string, start: string|null, type: string, context: string, days_left: int, is_ongoing: bool}>
     */
    private function upcomingDeadlines(array $items, CarbonImmutable $today): array
    {
        $upcoming = [];

        foreach ($items as $item) {
            if (($item['duplicate_of'] ?? null) !== null) {
                continue;
            }

            foreach ($item['deadlines'] ?? [] as $deadline) {
                $date = $this->date($deadline['date'] ?? null);

                if ($date === null || $date->lessThan($today)) {
                    continue;
                }

                $start = $this->date($deadline['start'] ?? null);
                $upcoming[] = [
                    'announcement_id' => (string) $item['id'],
                    'title' => (string) ($item['title'] ?? ''),
                    'category' => (string) ($item['category'] ?? 'umum'),
                    'category_label' => (string) ($item['category_label'] ?? ''),
                    'date' => $date->toDateString(),
                    'start' => $start?->toDateString(),
                    'type' => (string) ($deadline['type'] ?? 'date'),
                    'context' => (string) ($deadline['context'] ?? ''),
                    'days_left' => (int) round($today->diffInDays($date)),
                    'is_ongoing' => $start !== null && $start->lessThanOrEqualTo($today),
                ];
            }
        }

        usort($upcoming, static fn (array $left, array $right): int => [$left['date'], $left['start'] ?? ''] <=> [$right['date'], $right['start'] ?? '']);

        return $upcoming;
    }

    /**
     * @param  array{text: string, dates: list<string>, is_future: bool, kind: string}|null  $notice
     * @return array{text: string, dates: list<string>, is_future: bool, kind: string}|null
     */
    private function refreshNotice(?array $notice, CarbonImmutable $today): ?array
    {
        if ($notice === null) {
            return null;
        }

        $notice['is_future'] = array_filter(
            $notice['dates'] ?? [],
            fn (string $date): bool => $this->isOnOrAfter($date, $today)
        ) !== [];

        return $notice;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, true>  $known
     */
    private function containsKnownId(array $items, array $known): bool
    {
        foreach ($items as $item) {
            if (isset($known[(string) ($item['id'] ?? '')])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     */
    private function dataOrigin(array $results): string
    {
        foreach ($results as $result) {
            if (($result['_sync']['data_origin'] ?? null) === 'server_cache') {
                return 'server_cache';
            }
        }

        return 'upstream';
    }

    private function isOnOrAfter(?string $date, CarbonImmutable $today): bool
    {
        $parsed = $this->date($date);

        return $parsed !== null && $parsed->greaterThanOrEqualTo($today);
    }

    private function date(?string $date): ?CarbonImmutable
    {
        if ($date === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return null;
        }

        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date, self::PORTAL_TIMEZONE);

        return $parsed instanceof CarbonImmutable ? $parsed : null;
    }
}
