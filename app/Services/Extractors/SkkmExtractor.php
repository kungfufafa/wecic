<?php

declare(strict_types=1);

namespace App\Services\Extractors;

use App\Exceptions\PortalScrapingException;
use App\Services\PortalEndpointRegistry;
use App\Services\PortalScrapingService;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Read-only SIREKA (SKKM) summary from portal/mahasiswa/?mod=student&act=skkm (F37, F40).
 *
 * Action links (add, edit, delete) are only inspected, never followed, and
 * their hrefs never leave the server (K13).
 */
class SkkmExtractor extends PortalScrapingService
{
    public const STATUS_VALID = 'valid';

    public const STATUS_INVALID = 'invalid';

    public const STATUS_PENDING = 'pending';

    public const STATUS_UNKNOWN = 'unknown';

    private const UNCATEGORIZED_ACTIVITY = 'Tanpa kategori';

    private const NUMBER_PATTERN = '(-?\d+(?:[.,]\d+)?)';

    private const ACTION_ID_PARAMS = ['id', 'skkm_id', 'id_skkm', 'idskkm'];

    private const HEADER_SCAN_ROWS = 3;

    /**
     * Extract SKKM statistics, entries, graduation status and the guide link.
     *
     * @return array{
     *     found: bool,
     *     stats: array{
     *         stat_counts: array{valid: int|null, invalid: int|null, pending: int|null},
     *         total_points: array{valid: int|float|null, invalid: int|float|null, pending: int|float|null},
     *         required_points: int|float|null,
     *         required_points_source: string|null
     *     },
     *     graduation_status: array{text: string|null, is_met: bool|null},
     *     entries: list<array{
     *         no: int|null,
     *         skkm_id: string|null,
     *         name: string,
     *         activity: string|null,
     *         level: string|null,
     *         type: string|null,
     *         points: int|float|null,
     *         points_label: string|null,
     *         status: string,
     *         status_label: string|null,
     *         has_attachment: bool,
     *         attachment_path: string|null,
     *         has_actions: bool,
     *         action_types: list<string>
     *     }>,
     *     summary: array{
     *         valid_points: int|float|null,
     *         pending_points: int|float|null,
     *         invalid_points: int|float|null,
     *         potential_points: int|float|null,
     *         points_source: string|null,
     *         remaining_points: int|float|null,
     *         progress_percent: float|null,
     *         entry_counts: array{total: int, valid: int, invalid: int, pending: int, unknown: int},
     *         stat_counts_match_entries: bool|null,
     *         points_by_activity: list<array{activity: string, entries: int, valid_points: int|float, pending_points: int|float, invalid_points: int|float}>
     *     },
     *     notice: array{text: string, dates: list<string>, is_future: bool, kind: string}|null,
     *     message: string|null,
     *     guide_path: string|null,
     *     guide_year: int|null,
     *     add_available: bool,
     *     extracted_at: string,
     *     _sync?: array<string, mixed>
     * }
     */
    public function extractSkkmData(string $sessionToken): array
    {
        try {
            $this->setSession($sessionToken);
            $this->logScraping('Extracting SKKM data', ['session_token' => $sessionToken]);

            return $this->rememberResource(
                'skkm',
                [],
                function (): array {
                    $url = PortalEndpointRegistry::url('student_skkm');
                    $html = $this->get($url);

                    $this->ensureNotLoginPage($html, $url);

                    $data = $this->parseSkkmPage($this->createCrawler($html));

                    $this->logScraping('SKKM data extracted successfully', [
                        'found' => $data['found'],
                        'entry_count' => count($data['entries']),
                        'stat_counts_match_entries' => $data['summary']['stat_counts_match_entries'],
                    ]);

                    return $data;
                },
                static fn (array $data): bool => ($data['found'] ?? false) === true
            );
        } catch (PortalScrapingException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logScrapingError('Extracting SKKM data', $e, ['session_token' => $sessionToken]);
            throw PortalScrapingException::dataExtractionFailed('SKKM', $e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function parseSkkmPage(Crawler $crawler): array
    {
        $content = $this->mainContent($crawler);
        $paragraphs = $this->paragraphTexts($content);
        $entriesTable = $this->findEntriesTable($content);
        $entries = $entriesTable !== null ? $this->extractEntries($entriesTable['rows'], $entriesTable['headers']) : [];
        $statCounts = $this->extractStatCounts($content);
        $totalPoints = $this->extractTotalPoints($paragraphs);
        [$requiredPoints, $requiredPointsSource] = $this->resolveRequiredPoints($paragraphs);
        $guidePath = $this->extractGuidePath($content);

        if ($entriesTable !== null && $entries !== []) {
            $this->logScrapingDebug('SKKM entry table structure', [
                'headers' => array_values($entriesTable['headers']),
                'row_count' => count($entries),
            ]);
        }

        return [
            'found' => $entriesTable !== null && $this->hasListHeading($content),
            'stats' => [
                'stat_counts' => $statCounts,
                'total_points' => $totalPoints,
                'required_points' => $requiredPoints,
                'required_points_source' => $requiredPointsSource,
            ],
            'graduation_status' => $this->extractGraduationStatus($paragraphs),
            'entries' => $entries,
            'summary' => $this->summarize($entries, $statCounts, $totalPoints, $requiredPoints),
            'notice' => $this->extractPortalNotice($content, 'skkm'),
            'message' => $this->extractMessage($content),
            'guide_path' => $guidePath,
            'guide_year' => $this->guideYear($guidePath),
            'add_available' => $content->filterXPath("descendant-or-self::a[contains(@href, 'act=addskkm') and not(ancestor::table)]")->count() > 0,
            'extracted_at' => now()->toISOString(),
        ];
    }

    /**
     * Texts of the paragraphs outside any table (totals, graduation status, requirements)
     *
     * @return list<string>
     */
    private function paragraphTexts(Crawler $content): array
    {
        $texts = $content->filterXPath('descendant-or-self::p[not(ancestor::table)]')->each(
            fn (Crawler $paragraph): string => $this->normalizePortalText($paragraph->text(''))
        );

        return array_values(array_filter($texts, static fn (string $text): bool => $text !== ''));
    }

    private function hasListHeading(Crawler $content): bool
    {
        $headings = $content->filter('h1, h2, h3, h4')->each(
            fn (Crawler $heading): string => mb_strtolower($this->normalizePortalText($heading->text('')))
        );

        foreach ($headings as $heading) {
            if (str_contains($heading, 'daftar data skkm')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{valid: int|null, invalid: int|null, pending: int|null}
     */
    private function extractStatCounts(Crawler $content): array
    {
        $counts = [self::STATUS_VALID => null, self::STATUS_INVALID => null, self::STATUS_PENDING => null];

        $content->filter('.small-box .inner')->each(function (Crawler $box) use (&$counts): void {
            $label = $box->filter('p');
            $value = $box->filter('h2, h3');

            if ($label->count() === 0 || $value->count() === 0) {
                return;
            }

            $status = $this->normalizeStatus($label->first()->text(''));

            if ($status === self::STATUS_UNKNOWN || $counts[$status] !== null) {
                return;
            }

            if (preg_match('/\d+/', $this->normalizePortalText($value->first()->text('')), $matches) === 1) {
                $counts[$status] = (int) $matches[0];
            }
        });

        return $counts;
    }

    /**
     * @param  list<string>  $paragraphs
     * @return array{valid: int|float|null, invalid: int|float|null, pending: int|float|null}
     */
    private function extractTotalPoints(array $paragraphs): array
    {
        $prefix = 'total\s+poin(?:t)?\s+(?:yang\s+)?';
        $patterns = [
            self::STATUS_PENDING => '/'.$prefix.'belum\s+(?:di)?validasi\s*:?\s*'.self::NUMBER_PATTERN.'/iu',
            self::STATUS_INVALID => '/'.$prefix.'tidak\s+valid\s*:?\s*'.self::NUMBER_PATTERN.'/iu',
            self::STATUS_VALID => '/'.$prefix.'(?:sudah\s+)?valid\s*:?\s*'.self::NUMBER_PATTERN.'/iu',
        ];
        $totals = [self::STATUS_VALID => null, self::STATUS_INVALID => null, self::STATUS_PENDING => null];

        foreach ($paragraphs as $paragraph) {
            foreach ($patterns as $status => $pattern) {
                if ($totals[$status] === null && preg_match($pattern, $paragraph, $matches) === 1) {
                    $totals[$status] = $this->parsePoints($matches[1]);
                }
            }
        }

        return $totals;
    }

    /**
     * Points needed to graduate: stated on the page, else curated in config
     *
     * @param  list<string>  $paragraphs
     * @return array{0: int|float|null, 1: string|null}
     */
    private function resolveRequiredPoints(array $paragraphs): array
    {
        $keywords = '(?:minimal|minimum|sekurang-kurangnya|paling\s+sedikit|target|syarat|wajib|dibutuhkan|diperlukan)';
        $patterns = [
            '/'.$keywords.'\s+(?:poin(?:t)?|nilai)(?:\s+skkm)?\s*:?\s*'.self::NUMBER_PATTERN.'/iu',
            '/'.$keywords.'\D{0,60}?'.self::NUMBER_PATTERN.'\s*(?:poin(?:t)?|skkm)\b/iu',
        ];

        foreach ($paragraphs as $paragraph) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $paragraph, $matches) === 1) {
                    $points = $this->parsePoints($matches[1]);

                    if ($points !== null && $points > 0) {
                        return [$points, 'portal'];
                    }
                }
            }
        }

        $configured = config('portal.skkm.required_points');

        if (is_numeric($configured) && (float) $configured > 0) {
            return [$this->parsePoints((string) $configured), 'config'];
        }

        return [null, null];
    }

    /**
     * "Belum Memenuhi ..." also contains "Memenuhi", so a negation wins
     *
     * @param  list<string>  $paragraphs
     * @return array{text: string|null, is_met: bool|null}
     */
    private function extractGraduationStatus(array $paragraphs): array
    {
        foreach ($paragraphs as $paragraph) {
            if (preg_match('/status\s+kelulusan(?:\s+skkm)?\s*:?\s*(.*)$/iu', $paragraph, $matches) !== 1) {
                continue;
            }

            $text = trim($matches[1]);

            if ($text === '') {
                return ['text' => null, 'is_met' => null];
            }

            return ['text' => $text, 'is_met' => $this->graduationStatusIsMet($text)];
        }

        return ['text' => null, 'is_met' => null];
    }

    private function graduationStatusIsMet(string $text): ?bool
    {
        if (preg_match('/memenuhi/iu', $text) !== 1) {
            return null;
        }

        return preg_match('/belum|tidak/iu', $text) !== 1;
    }

    /**
     * The entry table, its column map and the rows below its header row
     *
     * The header row is looked up by label among the first rows that have
     * cells, so it is found in raw server HTML too: rows straight under the
     * table (no thead/tbody), header cells written as td, or cells wrapped
     * in b/font/form tags. Rows and cells of nested tables never count.
     *
     * @return array{headers: array<int, string>, rows: list<Crawler>}|null
     */
    private function findEntriesTable(Crawler $content): ?array
    {
        foreach ($content->filter('table') as $node) {
            $rows = $this->tableRows(new Crawler($node));
            $checkedRows = 0;

            foreach ($rows as $index => $row) {
                $cells = $this->rowCells($row);

                if ($cells === []) {
                    continue;
                }

                $headers = $this->tableHeaders($cells);

                if (in_array('name', $headers, true) && (in_array('status', $headers, true) || in_array('points', $headers, true))) {
                    return ['headers' => $headers, 'rows' => array_slice($rows, $index + 1)];
                }

                if (++$checkedRows === self::HEADER_SCAN_ROWS) {
                    break;
                }
            }
        }

        return null;
    }

    /**
     * Column index => field key, mapped from the header labels
     *
     * @param  list<Crawler>  $headerCells
     * @return array<int, string>
     */
    private function tableHeaders(array $headerCells): array
    {
        $headers = [];

        foreach ($headerCells as $index => $cell) {
            $key = $this->columnKey($this->cellText($cell));

            if ($key !== null && ! in_array($key, $headers, true)) {
                $headers[$index] = $key;
            }
        }

        return $headers;
    }

    private function columnKey(string $label): ?string
    {
        $normalized = trim((string) preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower($this->normalizePortalText($label))));

        return match (true) {
            $normalized === 'no' || $normalized === 'nomor' => 'no',
            str_contains($normalized, 'nama') => 'name',
            str_contains($normalized, 'tingkat') => 'level',
            str_contains($normalized, 'jenis') => 'type',
            str_contains($normalized, 'kegiatan') || str_contains($normalized, 'kategori') => 'activity',
            str_contains($normalized, 'nilai') || str_contains($normalized, 'poin') || str_contains($normalized, 'point') => 'points',
            str_contains($normalized, 'status') => 'status',
            str_contains($normalized, 'lampiran') || str_contains($normalized, 'bukti') => 'attachment',
            str_contains($normalized, 'aksi') || str_contains($normalized, 'action') => 'actions',
            default => null,
        };
    }

    /**
     * @param  list<Crawler>  $rows
     * @param  array<int, string>  $headers
     * @return list<array<string, mixed>>
     */
    private function extractEntries(array $rows, array $headers): array
    {
        $entries = [];

        foreach ($rows as $row) {
            $entry = $this->extractEntry($row, $headers);

            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * A data row's fields; rows with fewer than two cells (placeholders)
     * and rows made only of th cells (a repeated header) are not entries
     *
     * @param  array<int, string>  $headers
     * @return array<string, mixed>|null
     */
    private function extractEntry(Crawler $row, array $headers): ?array
    {
        $cells = $this->rowCells($row);

        if (count($cells) < 2 || $this->isHeaderOnlyRow($cells)) {
            return null;
        }

        $byKey = [];

        foreach ($headers as $index => $key) {
            if (isset($cells[$index])) {
                $byKey[$key] = $cells[$index];
            }
        }

        $text = function (string $key) use ($byKey): ?string {
            if (! isset($byKey[$key])) {
                return null;
            }

            $value = $this->cellText($byKey[$key]);

            return $value === '' ? null : $value;
        };

        $name = $text('name');

        if ($name === null) {
            return null;
        }

        $pointsLabel = $text('points');
        $statusLabel = $text('status');
        $attachmentLink = isset($byKey['attachment']) ? $byKey['attachment']->filter('a[href]') : null;
        $actionElements = isset($byKey['actions']) ? $this->actionElements($byKey['actions']) : [];
        $number = $text('no');

        return [
            'no' => $number !== null && preg_match('/^\d+/', $number, $matches) === 1 ? (int) $matches[0] : null,
            'skkm_id' => $this->actionRecordId($actionElements),
            'name' => $name,
            'activity' => $text('activity'),
            'level' => $text('level'),
            'type' => $text('type'),
            'points' => $pointsLabel !== null ? $this->parsePoints($pointsLabel) : null,
            'points_label' => $pointsLabel,
            'status' => $statusLabel !== null ? $this->normalizeStatus($statusLabel) : self::STATUS_UNKNOWN,
            'status_label' => $statusLabel,
            'has_attachment' => $attachmentLink !== null && $attachmentLink->count() > 0,
            'attachment_path' => $attachmentLink !== null && $attachmentLink->count() > 0
                ? $this->campusFilePath((string) $attachmentLink->first()->attr('href'))
                : null,
            'has_actions' => $actionElements !== [],
            'action_types' => $this->actionTypes($actionElements),
        ];
    }

    /**
     * @param  list<Crawler>  $cells
     */
    private function isHeaderOnlyRow(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (strtolower($cell->nodeName()) !== 'th') {
                return false;
            }
        }

        return true;
    }

    /**
     * Action controls in the cell; a form counts once, with the buttons inside it
     *
     * @return list<Crawler>
     */
    private function actionElements(Crawler $cell): array
    {
        $boundary = $cell->getNode(0);
        $elements = [];

        if ($boundary === null) {
            return [];
        }

        foreach ($cell->filter('a, button, input[type="submit"], input[type="button"], form') as $node) {
            if ($node->nodeName === 'form' || ! $this->isInsideFormWithin($node, $boundary)) {
                $elements[] = new Crawler($node);
            }
        }

        return $elements;
    }

    private function isInsideFormWithin(\DOMNode $node, \DOMNode $boundary): bool
    {
        for ($parent = $node->parentNode; $parent !== null && ! $parent->isSameNode($boundary); $parent = $parent->parentNode) {
            if ($parent->nodeName === 'form') {
                return true;
            }
        }

        return false;
    }

    /**
     * Kinds of row actions the portal offers, without their hrefs (K13)
     *
     * @param  list<Crawler>  $elements
     * @return list<string>
     */
    private function actionTypes(array $elements): array
    {
        $types = [];

        foreach ($elements as $element) {
            $signal = mb_strtolower(html_entity_decode($element->outerHtml(), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            $type = match (true) {
                preg_match('/hapus|delete|trash|remove|act=del/u', $signal) === 1 => 'delete',
                preg_match('/ubah|edit|update|pencil|act=upd/u', $signal) === 1 => 'edit',
                preg_match('/lihat|view|detail|preview|fa-eye/u', $signal) === 1 => 'view',
                default => 'unknown',
            };

            $types[$type] = true;
        }

        return array_keys($types);
    }

    /**
     * Portal id of the entry, read from its action controls (only the id, never the href)
     *
     * @param  list<Crawler>  $elements
     */
    private function actionRecordId(array $elements): ?string
    {
        $params = implode('|', self::ACTION_ID_PARAMS);

        foreach ($elements as $element) {
            $hiddenId = $element->filter('input[type="hidden"]')->reduce(
                static fn (Crawler $input): bool => in_array(strtolower((string) $input->attr('name')), self::ACTION_ID_PARAMS, true)
            );

            if ($hiddenId->count() > 0 && preg_match('/^[A-Za-z0-9_-]+$/', trim((string) $hiddenId->first()->attr('value'))) === 1) {
                return trim((string) $hiddenId->first()->attr('value'));
            }

            $html = html_entity_decode($element->outerHtml(), ENT_QUOTES | ENT_HTML5, 'UTF-8');

            if (preg_match('/[?&](?:'.$params.')=([A-Za-z0-9_-]+)/i', $html, $matches) === 1) {
                return $matches[1];
            }
        }

        return null;
    }

    /**
     * A negated approval ("Tidak Divalidasi", "Tidak Disetujui", "Tidak
     * Diterima") is checked before the approving words it contains, the way
     * KrsPageExtractor::negatesValidation() reads the KRS status.
     */
    private function normalizeStatus(string $label): string
    {
        $normalized = mb_strtolower($this->normalizePortalText($label));

        return match (true) {
            $normalized === '' => self::STATUS_UNKNOWN,
            preg_match('/\b(?:tidak|bukan)\s+(?:(?:sudah|telah|lolos)\s+)?(?:di|ter)?(?:valid(?:asi)?|setujui|terima|sah)\b|\btidak\s+lolos\b|tidak\s*valid|ditolak|invalid|gagal/u', $normalized) === 1 => self::STATUS_INVALID,
            preg_match('/belum|menunggu|proses|pending|diajukan/u', $normalized) === 1 => self::STATUS_PENDING,
            preg_match('/valid|diterima|disetujui|sah\b/u', $normalized) === 1 => self::STATUS_VALID,
            default => self::STATUS_UNKNOWN,
        };
    }

    /**
     * The "Pedoman SKKM" PDF next to the list (F40), outside the entry table
     */
    private function extractGuidePath(Crawler $content): ?string
    {
        $links = $content->filterXPath("descendant-or-self::a[contains(@href, 'files/skkm/') and not(ancestor::table)]");
        $fallback = null;

        foreach ($links as $node) {
            $link = new Crawler($node);
            $path = $this->campusFilePath((string) $link->attr('href'));

            if ($path === null) {
                continue;
            }

            if (str_contains(mb_strtolower($link->text('').' '.$path), 'pedoman')) {
                return $path;
            }

            $fallback ??= $path;
        }

        return $fallback;
    }

    private function guideYear(?string $guidePath): ?int
    {
        if ($guidePath === null || preg_match('/(?<!\d)(20\d{2})(?!\d)/', basename($guidePath), $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    private function extractMessage(Crawler $content): ?string
    {
        $message = $content->filter('#message');

        if ($message->count() === 0) {
            return null;
        }

        $text = $this->normalizePortalText($message->first()->text(''));

        return $text === '' ? null : $text;
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @param  array{valid: int|null, invalid: int|null, pending: int|null}  $statCounts
     * @param  array{valid: int|float|null, invalid: int|float|null, pending: int|float|null}  $totalPoints
     * @return array<string, mixed>
     */
    private function summarize(array $entries, array $statCounts, array $totalPoints, int|float|null $requiredPoints): array
    {
        $entryCounts = ['total' => count($entries), 'valid' => 0, 'invalid' => 0, 'pending' => 0, 'unknown' => 0];
        $entryPoints = [self::STATUS_VALID => 0, self::STATUS_INVALID => 0, self::STATUS_PENDING => 0];
        $hasEntryPoints = false;
        $byActivity = [];

        foreach ($entries as $entry) {
            $status = $entry['status'];
            $points = $entry['points'] ?? null;
            $entryCounts[$status]++;

            $activity = $entry['activity'] ?? self::UNCATEGORIZED_ACTIVITY;
            $byActivity[$activity] ??= [
                'activity' => $activity,
                'entries' => 0,
                'valid_points' => 0,
                'pending_points' => 0,
                'invalid_points' => 0,
            ];
            $byActivity[$activity]['entries']++;

            if ($points === null || ! isset($entryPoints[$status])) {
                continue;
            }

            $hasEntryPoints = true;
            $entryPoints[$status] += $points;
            $byActivity[$activity][$status.'_points'] += $points;
        }

        $hasTotals = array_filter($totalPoints, static fn (int|float|null $value): bool => $value !== null) !== [];
        $points = match (true) {
            $hasTotals => $totalPoints,
            $hasEntryPoints => $entryPoints,
            default => [self::STATUS_VALID => null, self::STATUS_INVALID => null, self::STATUS_PENDING => null],
        };
        $validPoints = $points[self::STATUS_VALID];
        $pendingPoints = $points[self::STATUS_PENDING];

        return [
            'valid_points' => $validPoints,
            'pending_points' => $pendingPoints,
            'invalid_points' => $points[self::STATUS_INVALID],
            'potential_points' => $validPoints !== null ? $validPoints + ($pendingPoints ?? 0) : null,
            'points_source' => match (true) {
                $hasTotals => 'portal_totals',
                $hasEntryPoints => 'entries',
                default => null,
            },
            'remaining_points' => $requiredPoints !== null && $validPoints !== null ? max(0, $requiredPoints - $validPoints) : null,
            'progress_percent' => $requiredPoints !== null && $requiredPoints > 0 && $validPoints !== null
                ? round(min(100, max(0, $validPoints / $requiredPoints * 100)), 1)
                : null,
            'entry_counts' => $entryCounts,
            'stat_counts_match_entries' => $this->statCountsMatchEntries($statCounts, $entryCounts),
            'points_by_activity' => array_values($byActivity),
        ];
    }

    /**
     * Whether the small-box numbers are entry counts (§8); unknown until the list has rows
     *
     * @param  array{valid: int|null, invalid: int|null, pending: int|null}  $statCounts
     * @param  array{total: int, valid: int, invalid: int, pending: int, unknown: int}  $entryCounts
     */
    private function statCountsMatchEntries(array $statCounts, array $entryCounts): ?bool
    {
        if ($entryCounts['total'] === 0 || in_array(null, $statCounts, true)) {
            return null;
        }

        foreach ($statCounts as $status => $count) {
            if ($entryCounts[$status] !== $count) {
                return false;
            }
        }

        return true;
    }

    private function parsePoints(string $text): int|float|null
    {
        if (preg_match('/-?\d+(?:[.,]\d+)*/', $this->normalizePortalText($text), $matches) !== 1) {
            return null;
        }

        $number = $matches[0];

        if (preg_match('/^-?\d{1,3}(?:\.\d{3})+$/', $number) === 1) {
            $number = str_replace('.', '', $number);
        }

        $number = str_replace(',', '.', $number);

        if (substr_count($number, '.') > 1 || ! is_numeric($number)) {
            return null;
        }

        $value = (float) $number;

        return floor($value) === $value && abs($value) < PHP_INT_MAX ? (int) $value : $value;
    }
}
