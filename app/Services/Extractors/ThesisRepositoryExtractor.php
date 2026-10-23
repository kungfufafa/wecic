<?php

declare(strict_types=1);

namespace App\Services\Extractors;

use App\Exceptions\PortalException;
use App\Exceptions\PortalScrapingException;
use App\Services\PortalEndpointRegistry;
use App\Services\PortalScrapingService;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Thesis repository (SiMASITA Repositori) from portal/mahasiswa/?mod=student&act=repositori (F46)
 *
 * One record spans four rows: a main row that starts with an "<n>." cell
 * (T.A, program, "<nim><br><author>", full-text PDF), then three detail rows
 * labelled "Judul Skripsi", "Pembimbing 1" and "Pembimbing 2". Rows are
 * grouped by the "<n>." marker and detail rows are mapped by their label,
 * never by the `altrow` class, which only marks every second main row.
 *
 * The portal's search form is empty (F47), so MyCIC indexes every page and
 * searches the snapshot instead. The repository is the same for every
 * student, but it is still stored per owner like any other snapshot.
 *
 * @phpstan-type ThesisRecord array{
 *     record_key: string,
 *     no: int,
 *     academic_year: string|null,
 *     program: string|null,
 *     nim: string|null,
 *     author: string|null,
 *     title: string,
 *     advisor_1: string|null,
 *     advisor_2: string|null,
 *     file_path: string|null,
 *     page: int
 * }
 * @phpstan-type RepositoryPaging array{
 *     page: int,
 *     per_page: int,
 *     total: int|null,
 *     last_page: int|null,
 *     has_next: bool,
 *     next_page: int|null
 * }
 */
class ThesisRepositoryExtractor extends PortalScrapingService
{
    /**
     * The portal always lists 100 records per page.
     */
    public const PER_PAGE = 100;

    /**
     * Safety cap of one index walk (about 7 pages when crawled).
     */
    public const MAX_PAGES = 30;

    private const RECORD_MARKER_PATTERN = '/^(\d+)\.$/';

    private const ACADEMIC_YEAR_PATTERN = '/(\d{4})\s*\/\s*(\d{4})/';

    private const EMPTY_VALUES = ['', '-', '--', ':'];

    /**
     * Every page of the repository, merged and de-duplicated
     *
     * Page 1 must succeed; it gives the total and so the last page. The walk
     * stops at the last page, at a page without a "Next" link or without
     * records, or after MAX_PAGES. Without a readable total the "Next" links
     * alone lead the walk. A later page that fails, or a walk cut off at
     * MAX_PAGES while a "Next" link remains, marks the index incomplete; an
     * expired session is rethrown so the student logs in again.
     *
     * With `$previous` (the last complete index) the walk stops after page 1
     * when the total and the first record are unchanged: nothing was added,
     * so the previous records are reused.
     *
     * @param  array<string, mixed>|null  $previous
     * @return array{
     *     found: bool,
     *     records: list<ThesisRecord>,
     *     total: int|null,
     *     record_count: int,
     *     pages_loaded: int,
     *     last_page: int|null,
     *     complete: bool,
     *     reused_snapshot: bool,
     *     first_record_key: string|null,
     *     extracted_at: string,
     *     _sync: array{data_origin: string}
     * }
     */
    public function extractRepositoryIndex(string $sessionToken, ?array $previous = null): array
    {
        $first = $this->extractRepositoryPage($sessionToken, 1);

        if (($first['found'] ?? false) !== true) {
            // An empty shell must never replace an index the student already has.
            throw PortalScrapingException::dataExtractionFailed('thesis repository', 'Tabel repositori tidak ditemukan di halaman 1.');
        }

        $dataOrigins = [$first['_sync']['data_origin'] ?? 'upstream'];
        $firstKey = $first['records'][0]['record_key'] ?? null;

        if ($this->canReuse($previous, $first['paging']['total'], $firstKey)) {
            $this->logScraping('Thesis repository unchanged, previous index reused', [
                'total' => $first['paging']['total'],
            ]);

            return [
                ...$this->indexPayload($previous['records'], $first['paging'], (int) ($previous['pages_loaded'] ?? 1), true, true),
                '_sync' => ['data_origin' => $dataOrigins[0]],
            ];
        }

        $pages = [1 => $first];
        $complete = true;
        $lastPage = min(self::MAX_PAGES, $first['paging']['last_page'] ?? self::MAX_PAGES);

        $this->fetchPolicy()->withRequestDelay(
            (int) config('portal.sync.bulk_request_delay', 250),
            function () use ($sessionToken, $lastPage, &$pages, &$complete, &$dataOrigins): void {
                for ($page = 2; $page <= $lastPage; $page++) {
                    $previousPage = $pages[$page - 1];

                    if (! $previousPage['paging']['has_next'] || $previousPage['records'] === []) {
                        break;
                    }

                    try {
                        $result = $this->extractRepositoryPage($sessionToken, $page);
                    } catch (PortalException $e) {
                        if (PortalException::indicatesExpiredSession($e)) {
                            throw $e;
                        }

                        Log::warning('Thesis repository walk stopped early', [
                            'page' => $page,
                            'error' => $e->getMessage(),
                        ]);
                        $complete = false;

                        break;
                    }

                    // A page inside the range without the table is a broken page, not the end.
                    if (($result['found'] ?? false) !== true) {
                        Log::warning('Thesis repository page without its table, walk stopped', ['page' => $page]);
                        $complete = false;

                        break;
                    }

                    $pages[$page] = $result;
                    $dataOrigins[] = $result['_sync']['data_origin'] ?? 'upstream';
                }
            }
        );

        $records = [];

        foreach ($pages as $page) {
            foreach ($page['records'] as $record) {
                $records[$record['record_key']] ??= $record;
            }
        }

        $lastLoaded = $pages[array_key_last($pages)];
        $reachedEnd = ! $lastLoaded['paging']['has_next']
            || $lastLoaded['records'] === []
            || ($first['paging']['last_page'] !== null && $lastLoaded['paging']['page'] >= $first['paging']['last_page']);

        return [
            ...$this->indexPayload(array_values($records), $first['paging'], count($pages), $complete && $reachedEnd, false),
            '_sync' => [
                'data_origin' => in_array('upstream', $dataOrigins, true) ? 'upstream' : 'server_cache',
            ],
        ];
    }

    /**
     * One repository page (resource cache `thesis_repository`, per page)
     *
     * @return array{found: bool, records: list<ThesisRecord>, paging: RepositoryPaging, extracted_at: string, _sync?: array<string, mixed>}
     */
    public function extractRepositoryPage(string $sessionToken, int $page = 1): array
    {
        $page = max(1, $page);

        try {
            $this->setSession($sessionToken);
            $this->logScraping('Extracting thesis repository page', ['session_token' => $sessionToken, 'page' => $page]);

            return $this->rememberResource(
                'thesis_repository',
                ['page' => $page],
                function () use ($page): array {
                    $url = $this->repositoryPageUrl($page);
                    $html = $this->get($url);

                    $this->ensureNotLoginPage($html, $url);

                    $data = $this->parseRepositoryPage($html, $page);

                    $this->logScraping('Thesis repository page extracted', [
                        'page' => $page,
                        'record_count' => count($data['records']),
                        'total' => $data['paging']['total'],
                    ]);

                    return $data;
                },
                static fn (array $data): bool => ($data['found'] ?? false) === true
            );
        } catch (PortalScrapingException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logScrapingError('Extracting thesis repository page', $e, ['session_token' => $sessionToken, 'page' => $page]);
            throw PortalScrapingException::dataExtractionFailed('thesis repository', $e->getMessage());
        }
    }

    public function repositoryPageUrl(int $page): string
    {
        return PortalEndpointRegistry::url('student_repository', $page > 1 ? ['page' => $page] : []);
    }

    /**
     * Records and paging of one raw repository page
     *
     * @return array{found: bool, records: list<ThesisRecord>, paging: RepositoryPaging, extracted_at: string}
     */
    public function parseRepositoryPage(string $html, int $page = 1): array
    {
        $content = $this->mainContent($this->createCrawler($html));
        $table = $this->findTableByHeaders($content, ['No', 'T.a', 'Program Studi']);
        $records = $table === null ? [] : $this->extractRecords($table, $page);

        return [
            'found' => $table !== null,
            'records' => $records,
            'paging' => $this->buildPaging($content->filter('.paging'), $page),
            'extracted_at' => now()->toISOString(),
        ];
    }

    /**
     * Stable key of a record: its PDF when it has one, else NIM, T.A and title
     *
     * @param  array{file_path: string|null, nim: string|null, academic_year: string|null, title: string}  $record
     */
    public static function recordKey(array $record): string
    {
        return sha1($record['file_path'] ?? implode('|', [$record['nim'] ?? '', $record['academic_year'] ?? '', mb_strtolower($record['title'])]));
    }

    /**
     * @param  array<string, mixed>|null  $previous
     */
    private function canReuse(?array $previous, ?int $total, ?string $firstKey): bool
    {
        return is_array($previous)
            && ($previous['complete'] ?? false) === true
            && is_array($previous['records'] ?? null)
            && $previous['records'] !== []
            && $total !== null
            && ($previous['total'] ?? null) === $total
            && $firstKey !== null
            && ($previous['first_record_key'] ?? null) === $firstKey;
    }

    /**
     * @param  list<ThesisRecord>  $records
     * @param  RepositoryPaging  $firstPaging
     * @return array<string, mixed>
     */
    private function indexPayload(array $records, array $firstPaging, int $pagesLoaded, bool $complete, bool $reused): array
    {
        return [
            'found' => $records !== [] || $firstPaging['total'] !== null,
            'records' => $records,
            'total' => $firstPaging['total'],
            'record_count' => count($records),
            'pages_loaded' => $pagesLoaded,
            'last_page' => $firstPaging['last_page'],
            'complete' => $complete,
            'reused_snapshot' => $reused,
            'first_record_key' => $records[0]['record_key'] ?? null,
            'extracted_at' => now()->toISOString(),
        ];
    }

    /**
     * Group the table's rows into records: a row whose first cell reads
     * "<n>." opens a record, labelled rows until the next marker fill it
     *
     * @return list<ThesisRecord>
     */
    private function extractRecords(Crawler $table, int $page): array
    {
        $records = [];
        $current = null;

        foreach ($this->tableRows($table) as $row) {
            $cells = $this->rowCells($row);

            if ($cells === []) {
                continue;
            }

            $first = $this->cellText($cells[0]);

            if (preg_match(self::RECORD_MARKER_PATTERN, $first, $marker) === 1) {
                if ($current !== null) {
                    $records[] = $current;
                }

                $current = $this->mainRow($cells, (int) $marker[1], $page);

                continue;
            }

            if ($current !== null) {
                $this->applyDetailRow($current, $cells);
            }
        }

        if ($current !== null) {
            $records[] = $current;
        }

        $records = array_values(array_filter($records, static fn (array $record): bool => $record['title'] !== '' || $record['file_path'] !== null));

        return array_map(static fn (array $record): array => ['record_key' => self::recordKey($record), ...$record], $records);
    }

    /**
     * "<n>." | T.A | Program Studi | "<nim><br><author>" | File Full
     *
     * @param  list<Crawler>  $cells
     * @return array<string, mixed>
     */
    private function mainRow(array $cells, int $no, int $page): array
    {
        $rest = array_slice($cells, 1);
        $academicYear = null;
        $program = null;
        $nim = null;
        $author = null;
        $filePath = null;

        foreach ($rest as $cell) {
            $link = $cell->filterXPath("descendant::a[contains(@href, '.pdf') or contains(@href, '.PDF')]");

            if ($filePath === null && $link->count() > 0) {
                $filePath = $this->campusFilePath(trim((string) $link->first()->attr('href')));

                continue;
            }

            $text = $this->cellText($cell);

            if ($academicYear === null && preg_match(self::ACADEMIC_YEAR_PATTERN, $text, $year) === 1) {
                $academicYear = "{$year[1]}/{$year[2]}";

                continue;
            }

            $lines = $this->cellLines($cell);

            if ($nim === null && count($lines) >= 2 && preg_match('/^\d{5,}$/', $lines[0]) === 1) {
                $nim = $lines[0];
                $author = implode(' ', array_slice($lines, 1));

                continue;
            }

            if ($program === null && $text !== '' && preg_match('/^\d+$/', $text) !== 1) {
                $program = $text;
            }
        }

        return [
            'no' => $no,
            'academic_year' => $academicYear,
            'program' => $program,
            'nim' => $nim,
            'author' => $author,
            'title' => '',
            'advisor_1' => null,
            'advisor_2' => null,
            'file_path' => $filePath,
            'page' => $page,
        ];
    }

    /**
     * <empty> | label | value (colspan)
     *
     * @param  array<string, mixed>  $record
     * @param  list<Crawler>  $cells
     */
    private function applyDetailRow(array &$record, array $cells): void
    {
        $texts = array_map(fn (Crawler $cell): string => $this->cellText($cell), $cells);
        $labelIndex = null;
        $field = null;

        foreach ($texts as $index => $text) {
            $field = $this->detailField($text);

            if ($field !== null) {
                $labelIndex = $index;

                break;
            }
        }

        if ($field === null) {
            return;
        }

        $value = implode(' ', array_filter(array_slice($texts, $labelIndex + 1), static fn (string $text): bool => $text !== ''));
        $value = trim((string) preg_replace('/^:\s*/u', '', $value));

        if ($field === 'title') {
            $record['title'] = $value;

            return;
        }

        $record[$field] = in_array($value, self::EMPTY_VALUES, true) ? null : $value;
    }

    private function detailField(string $label): ?string
    {
        $key = mb_strtolower((string) preg_replace('/[^\p{L}\p{N}]+/u', '', $label));

        return match ($key) {
            'judulskripsi', 'judul', 'judultugasakhir' => 'title',
            'pembimbing1', 'pembimbingi' => 'advisor_1',
            'pembimbing2', 'pembimbingii' => 'advisor_2',
            default => null,
        };
    }

    /**
     * Text lines of a cell, split at `<br>`
     *
     * @return list<string>
     */
    private function cellLines(Crawler $cell): array
    {
        $html = (string) preg_replace('/<br\s*\/?>/i', "\n", $cell->html(''));
        $lines = array_map(fn (string $line): string => $this->normalizePortalText(strip_tags($line)), explode("\n", $html));

        return array_values(array_filter($lines, static fn (string $line): bool => $line !== ''));
    }

    /**
     * div.paging reads "<< Previous | [ <from>-<to> of <total> ] | Next >>";
     * Previous points to "#" on page 1 and Next is plain text on the last page
     *
     * @return RepositoryPaging
     */
    private function buildPaging(Crawler $pagingNode, int $page): array
    {
        $label = $pagingNode->count() > 0 ? $this->normalizePortalText($pagingNode->first()->text('')) : '';
        $total = preg_match('/\[\s*\d+\s*-\s*\d+\s*(?:of|dari)\s*(\d+)\s*\]/iu', $label, $match) === 1 ? (int) $match[1] : null;
        $lastPage = $total === null ? null : max(1, (int) ceil($total / self::PER_PAGE));
        $hasNextLink = false;

        if ($pagingNode->count() > 0) {
            $pagingNode->first()->filter('a[href]')->each(function (Crawler $link) use (&$hasNextLink, $page): void {
                $href = (string) $link->attr('href');

                if (preg_match('/[?&]page=(\d+)/', $href, $target) === 1 && (int) $target[1] === $page + 1) {
                    $hasNextLink = true;
                }
            });
        }

        $hasNext = $hasNextLink && ($lastPage === null || $page < $lastPage);

        return [
            'page' => $page,
            'per_page' => self::PER_PAGE,
            'total' => $total,
            'last_page' => $lastPage,
            'has_next' => $hasNext,
            'next_page' => $hasNext ? $page + 1 : null,
        ];
    }
}
