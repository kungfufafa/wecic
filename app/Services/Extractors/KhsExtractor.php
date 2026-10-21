<?php

declare(strict_types=1);

namespace App\Services\Extractors;

use App\Exceptions\PortalScrapingException;
use App\Services\PortalEndpointRegistry;
use App\Services\PortalScrapingService;
use DOMElement;
use DOMNode;
use DOMXPath;
use InvalidArgumentException;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Kartu Hasil Studi (KHS) parser for both portal sources (F15/F16)
 *
 * SIPANDAI `act=score` is the canonical source of grade rows (it adds the
 * ABSEN and TUGAS columns); Portal Mahasiswa `act=viewkrs` carries the same
 * KHS without those columns plus the official cumulative summary. Both pages
 * are parsed by the same generic code: KHS tables are recognised by their
 * header row and columns are mapped by header label, never by position.
 *
 * @phpstan-type KhsCourse array{
 *     code: string,
 *     name: string,
 *     sks: int,
 *     components: array<string, float|null>,
 *     score: float|null,
 *     letter: string|null,
 *     weight: float|null,
 *     quality_points: float|null,
 *     is_graded: bool
 * }
 * @phpstan-type KhsSemester array{
 *     label: string,
 *     semester: int|string|null,
 *     is_regular: bool,
 *     status: 'graded'|'partial'|'pending'|'empty',
 *     columns: list<string>,
 *     courses: list<KhsCourse>,
 *     course_count: int,
 *     graded_count: int,
 *     total_sks: int|null,
 *     total_quality_points: float|null,
 *     ips: float|null
 * }
 * @phpstan-type KhsCumulative array{
 *     ipk: float|null,
 *     sks_total: int|null,
 *     quality_points_total: float|null,
 *     sks_remaining: int|null,
 *     target_sks: int|null,
 *     letter_counts: array<string, int>,
 *     fields: array<string, float|int>
 * }
 */
class KhsExtractor extends PortalScrapingService
{
    public const SOURCE_SCORE = 'sipandai_score';

    public const SOURCE_KRS_DETAIL = 'student_krs_detail';

    /**
     * Letter grades the campus uses; anything else ("T", "K", "-") is not a grade yet.
     */
    private const GRADE_LETTER_PATTERN = '/^(?:A|A-|AB|B\+|B|B-|BC|C\+|C|C-|CD|D\+|D|DE|E)$/';

    /**
     * Fallback weights, used only when a page has a letter but no BOBOT column.
     *
     * @var array<string, float>
     */
    private const DEFAULT_LETTER_WEIGHTS = ['A' => 4.0, 'B' => 3.0, 'C' => 2.0, 'D' => 1.0, 'E' => 0.0];

    /**
     * KHS of every semester from SIPANDAI `act=score`
     *
     * @return array{
     *     source: 'sipandai_score',
     *     found: bool,
     *     semesters: list<KhsSemester>,
     *     cumulative: KhsCumulative|null,
     *     transcript_print_url: string|null,
     *     extracted_at: string,
     *     _sync?: array<string, mixed>
     * }
     */
    public function extractKhsData(string $sessionToken): array
    {
        try {
            $this->setSession($sessionToken);
            $this->logScraping('Extracting KHS from SIPANDAI score page', ['session_token' => $sessionToken]);

            return $this->rememberResource('khs', [], function (): array {
                $url = PortalEndpointRegistry::url(self::SOURCE_SCORE);
                $html = $this->get($url);

                $this->ensureNotLoginPage($html, $url);

                $parsed = $this->parseKhsHtml($html);

                $this->logScraping('KHS extracted from SIPANDAI score page', $this->parsedLogContext($parsed));

                return [
                    'source' => self::SOURCE_SCORE,
                    ...$parsed,
                    'extracted_at' => now()->toISOString(),
                ];
            }, static fn (array $data): bool => ($data['found'] ?? false) === true);
        } catch (PortalScrapingException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logScrapingError('Extracting KHS from SIPANDAI score page', $e, ['session_token' => $sessionToken]);
            throw PortalScrapingException::dataExtractionFailed('KHS', $e->getMessage());
        }
    }

    /**
     * KHS of every semester plus the official cumulative summary from `act=viewkrs`
     *
     * The page shows the same KHS for every KRS id; the caller passes the
     * latest one from the student's own KRS history. The page is memoized per
     * scope, so the KRS detail extractor reusing it costs no extra request.
     *
     * @return array{
     *     source: 'student_krs_detail',
     *     krs_id: string,
     *     found: bool,
     *     semesters: list<KhsSemester>,
     *     cumulative: KhsCumulative|null,
     *     transcript_print_url: string|null,
     *     extracted_at: string,
     *     _sync?: array<string, mixed>
     * }
     */
    public function extractKrsGradesData(string $sessionToken, string $krsId): array
    {
        if (preg_match('/^\d+$/', $krsId) !== 1) {
            throw new InvalidArgumentException('Id KRS harus berupa angka.');
        }

        try {
            $this->setSession($sessionToken);
            $this->logScraping('Extracting KHS from KRS detail page', ['session_token' => $sessionToken]);

            return $this->rememberResource('khs', ['source' => self::SOURCE_KRS_DETAIL, 'id' => $krsId], function () use ($krsId): array {
                $url = PortalEndpointRegistry::url(self::SOURCE_KRS_DETAIL, ['id' => $krsId]);
                $html = $this->get($url);

                $this->ensureNotLoginPage($html, $url);

                $parsed = $this->parseKhsHtml($html);

                $this->logScraping('KHS extracted from KRS detail page', $this->parsedLogContext($parsed));

                return [
                    'source' => self::SOURCE_KRS_DETAIL,
                    'krs_id' => $krsId,
                    ...$parsed,
                    'extracted_at' => now()->toISOString(),
                ];
            }, static fn (array $data): bool => ($data['found'] ?? false) === true);
        } catch (PortalScrapingException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logScrapingError('Extracting KHS from KRS detail page', $e, ['session_token' => $sessionToken]);
            throw PortalScrapingException::dataExtractionFailed('KHS (KRS detail)', $e->getMessage());
        }
    }

    /**
     * Parse a full `act=score` or `act=viewkrs` page
     *
     * @return array{found: bool, semesters: list<KhsSemester>, cumulative: KhsCumulative|null, transcript_print_url: string|null}
     */
    public function parseKhsHtml(string $html): array
    {
        $crawler = $this->createCrawler($html);
        $semesters = $this->extractKhsSemesters($crawler);

        return [
            'found' => $semesters !== [],
            'semesters' => $semesters,
            'cumulative' => $this->extractCumulativeSummary($crawler),
            'transcript_print_url' => $this->extractTranscriptPrintUrl($crawler),
        ];
    }

    /**
     * Every KHS card in page order (the portal lists them newest first, `T` last)
     *
     * Rows and cells are read with the DOM-walking table helpers, so the raw
     * server markup (no tbody, cells wrapped in b/font, rows inside a form)
     * parses like the browser-normalized one and a nested table never leaks
     * its rows into the outer one.
     *
     * @return list<KhsSemester>
     */
    public function extractKhsSemesters(Crawler $crawler): array
    {
        $scope = $this->mainContent($crawler);
        $semesters = [];

        foreach ($scope->filter('table') as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $semester = $this->parseKhsTable(new Crawler($node, $scope->getUri(), $scope->getBaseHref()));

            if ($semester !== null) {
                $semesters[] = $semester;
            }
        }

        return $semesters;
    }

    /**
     * Official cumulative summary: the untitled card whose cell holds
     * "Label : value" lines separated by `<br>` (F16)
     *
     * @return KhsCumulative|null
     */
    public function extractCumulativeSummary(Crawler $crawler): ?array
    {
        $cell = $this->findCumulativeCell($this->mainContent($crawler));

        if ($cell === null) {
            return null;
        }

        $fields = $this->cumulativeFields($cell);

        if ($fields === []) {
            return null;
        }

        $summary = [
            'ipk' => null,
            'sks_total' => null,
            'quality_points_total' => null,
            'sks_remaining' => null,
            'target_sks' => null,
            'letter_counts' => [],
            'fields' => [],
        ];

        foreach ($fields as $label => $value) {
            $key = $this->snakeLabel($label);
            $summary['fields'][$key] = $value;

            if (preg_match('/^nilai\s+([a-e][+-]?)$/i', $label, $matches) === 1) {
                $summary['letter_counts'][strtoupper($matches[1])] = (int) $value;

                continue;
            }

            match (true) {
                preg_match('/kumulatif|^ipk$/i', $label) === 1 => $summary['ipk'] = (float) $value,
                preg_match('/sks\s+terkumpul/i', $label) === 1 => $summary['sks_total'] = (int) $value,
                preg_match('/sisa\s+sks/i', $label) === 1 => $summary['sks_remaining'] = (int) $value,
                preg_match('/nilai\s+mutu|jumlah\s+mutu/i', $label) === 1 => $summary['quality_points_total'] = (float) $value,
                default => null,
            };
        }

        if ($summary['ipk'] === null) {
            return null;
        }

        if ($summary['ipk'] == 0.0 && ($summary['sks_total'] ?? 0) === 0) {
            $summary['ipk'] = null;
        }

        if ($summary['sks_total'] !== null && $summary['sks_remaining'] !== null) {
            $summary['target_sks'] = $summary['sks_total'] + $summary['sks_remaining'];
        }

        return $summary;
    }

    /**
     * @return KhsSemester|null
     */
    private function parseKhsTable(Crawler $table): ?array
    {
        $rows = $this->tableRows($table);
        $header = $this->khsHeader($rows);

        if ($header === null) {
            return null;
        }

        $headerKeys = $header['keys'];
        $label = $this->semesterLabel($table);
        $semester = $this->semesterValue($label);
        $courses = [];
        $totalSks = null;
        $totalQualityPoints = null;
        $ips = null;

        foreach (array_slice($rows, $header['index'] + 1) as $row) {
            $cells = $this->rowCells($row);

            if ($cells === []) {
                continue;
            }

            if ($this->isFooterRow($cells)) {
                $footer = $this->parseFooterRow($cells);
                $totalSks = $footer['total_sks'] ?? $totalSks;
                $totalQualityPoints = $footer['total_quality_points'] ?? $totalQualityPoints;
                $ips = array_key_exists('ips', $footer) ? $footer['ips'] : $ips;

                continue;
            }

            if (count($cells) !== count($headerKeys)) {
                continue;
            }

            $course = $this->parseCourseRow($cells, $headerKeys);

            if ($course !== null) {
                $courses[] = $course;
            }
        }

        $gradedCount = count(array_filter($courses, static fn (array $course): bool => $course['is_graded']));

        if ($ips !== null && $ips == 0.0 && $gradedCount === 0) {
            $ips = null;
        }

        return [
            'label' => $label,
            'semester' => $semester,
            'is_regular' => is_int($semester),
            'status' => $this->semesterStatus(count($courses), $gradedCount),
            'columns' => array_values(array_filter(
                array_map(static fn (string $key): ?string => str_starts_with($key, 'component:') ? substr($key, 10) : null, $headerKeys)
            )),
            'courses' => $courses,
            'course_count' => count($courses),
            'graded_count' => $gradedCount,
            'total_sks' => $totalSks,
            'total_quality_points' => $totalQualityPoints,
            'ips' => $ips,
        ];
    }

    /**
     * Header row of a KHS table: the first of its first three rows with cells
     * that has both a KODE and a BOBOT column
     *
     * @param  list<Crawler>  $rows
     * @return array{index: int, keys: list<string>}|null
     */
    private function khsHeader(array $rows): ?array
    {
        $checkedRows = 0;

        foreach ($rows as $index => $row) {
            $cells = $this->rowCells($row);

            if ($cells === []) {
                continue;
            }

            $keys = array_map(fn (Crawler $cell): string => $this->columnKey($this->cellText($cell)), $cells);

            if (in_array('weight', $keys, true) && in_array('code', $keys, true)) {
                return ['index' => $index, 'keys' => $keys];
            }

            if (++$checkedRows === 3) {
                break;
            }
        }

        return null;
    }

    /**
     * @param  list<Crawler>  $cells
     * @param  list<string>  $headerKeys
     * @return KhsCourse|null
     */
    private function parseCourseRow(array $cells, array $headerKeys): ?array
    {
        $course = [
            'code' => '',
            'name' => '',
            'sks' => 0,
            'components' => [],
            'score' => null,
            'letter' => null,
            'weight' => null,
            'quality_points' => null,
            'is_graded' => false,
        ];

        foreach ($cells as $index => $cell) {
            $key = $headerKeys[$index];
            $text = $this->cellText($cell);

            if (str_starts_with($key, 'component:')) {
                $course['components'][substr($key, 10)] = $this->parseDecimal($text);

                continue;
            }

            match ($key) {
                'code' => $course['code'] = strtoupper($text),
                'name' => $course['name'] = $text,
                'sks' => $course['sks'] = (int) round($this->parseDecimal($text) ?? 0.0),
                'grade' => $this->applyGradeCell($course, $text),
                'letter' => $course['letter'] = $this->parseLetter($text),
                'score' => $course['score'] = $this->parseDecimal($text),
                'weight' => $course['weight'] = $this->parseDecimal($text),
                'quality_points' => $course['quality_points'] = $this->parseDecimal($text),
                default => null,
            };
        }

        if ($course['code'] === '' && $course['name'] === '') {
            return null;
        }

        if ($course['weight'] === null && $course['letter'] !== null) {
            $course['weight'] = self::DEFAULT_LETTER_WEIGHTS[$course['letter']] ?? null;
        }

        $course['is_graded'] = $course['letter'] !== null
            && preg_match(self::GRADE_LETTER_PATTERN, $course['letter']) === 1
            && $course['weight'] !== null;

        if ($course['quality_points'] === null && $course['is_graded']) {
            $course['quality_points'] = round($course['sks'] * (float) $course['weight'], 2);
        }

        return $course;
    }

    /**
     * NILAI holds the letter grade on both pages; a numeric value is a final score
     *
     * @param  array<string, mixed>  $course
     */
    private function applyGradeCell(array &$course, string $text): void
    {
        $number = $this->parseDecimal($text);

        if ($number !== null) {
            $course['score'] = $number;

            return;
        }

        $course['letter'] = $this->parseLetter($text);
    }

    /**
     * @param  list<Crawler>  $cells
     */
    private function isFooterRow(array $cells): bool
    {
        foreach ($cells as $cell) {
            if ((int) $cell->attr('colspan') > 1) {
                return true;
            }

            if (preg_match('/^(?:total\s+sks|jumlah\s+mutu)$|indeks\s+prestasi\s+semester\s*:/i', $this->cellText($cell)) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Footer cells are found by their text, not their colspan: the viewkrs
     * cumulative card also uses `colspan=7` (F15)
     *
     * @param  list<Crawler>  $cells
     * @return array{total_sks?: int|null, total_quality_points?: float|null, ips?: float|null}
     */
    private function parseFooterRow(array $cells): array
    {
        $footer = [];

        foreach ($cells as $index => $cell) {
            $text = $this->cellText($cell);
            $next = isset($cells[$index + 1]) ? $this->parseDecimal($this->cellText($cells[$index + 1])) : null;

            if (preg_match('/^total\s+sks$/i', $text) === 1) {
                $footer['total_sks'] = $next === null ? null : (int) round($next);
            } elseif (preg_match('/^jumlah\s+mutu$/i', $text) === 1) {
                $footer['total_quality_points'] = $next;
            } elseif (preg_match('/indeks\s+prestasi\s+semester\s*:\s*(\d+(?:[.,]\d+)?)?/i', $text, $matches) === 1) {
                $footer['ips'] = isset($matches[1]) ? $this->parseDecimal($matches[1]) : $next;
            }
        }

        return $footer;
    }

    /**
     * Title of the card the table sits in: the nearest preceding h4
     */
    private function semesterLabel(Crawler $table): string
    {
        $node = $table->getNode(0);

        if (! $node instanceof DOMElement || $node->ownerDocument === null) {
            return '';
        }

        $headings = (new DOMXPath($node->ownerDocument))->query('preceding::h4[1]', $node);
        $heading = $headings === false ? null : $headings->item(0);

        return $heading instanceof DOMNode ? $this->normalizePortalText($heading->textContent) : '';
    }

    private function semesterValue(string $label): int|string|null
    {
        if (preg_match('/Semester\s*:\s*([0-9]+|[A-Z])\b/i', $label, $matches) !== 1) {
            return null;
        }

        return ctype_digit($matches[1]) ? (int) $matches[1] : strtoupper($matches[1]);
    }

    /**
     * @return 'graded'|'partial'|'pending'|'empty'
     */
    private function semesterStatus(int $courseCount, int $gradedCount): string
    {
        return match (true) {
            $courseCount === 0 => 'empty',
            $gradedCount === 0 => 'pending',
            $gradedCount < $courseCount => 'partial',
            default => 'graded',
        };
    }

    /**
     * Header label to field key; unknown columns become score components
     */
    private function columnKey(string $header): string
    {
        $label = strtolower($this->normalizePortalText($header));
        $label = trim((string) preg_replace('/[^a-z0-9]+/', ' ', $label));

        return match (true) {
            $label === 'no' || $label === 'nomor' => 'number',
            in_array($label, ['kode', 'kode mk', 'kode mata kuliah', 'kd mk'], true) => 'code',
            str_contains($label, 'mata kuliah') || $label === 'nama mk' || $label === 'matakuliah' => 'name',
            $label === 'sks' => 'sks',
            in_array($label, ['absen', 'absensi', 'kehadiran', 'presensi'], true) => 'component:absen',
            in_array($label, ['tugas', 'tgs'], true) => 'component:tugas',
            $label === 'uts' => 'component:uts',
            $label === 'uas' => 'component:uas',
            in_array($label, ['huruf', 'nilai huruf'], true) => 'letter',
            in_array($label, ['nilai akhir', 'nilai angka', 'angka', 'na'], true) => 'score',
            $label === 'nilai' => 'grade',
            $label === 'bobot' => 'weight',
            $label === 'mutu' || $label === 'nilai mutu' => 'quality_points',
            $label === '' => 'ignored',
            default => 'component:'.str_replace(' ', '_', $label),
        };
    }

    private function parseLetter(string $text): ?string
    {
        $letter = strtoupper(str_replace(' ', '', $this->normalizePortalText($text)));

        return $letter === '' || $letter === '-' ? null : $letter;
    }

    private function parseDecimal(string $text): ?float
    {
        $value = str_replace(' ', '', $this->normalizePortalText($text));

        if ($value === '' || $value === '-') {
            return null;
        }

        if (str_contains($value, '.') && str_contains($value, ',')) {
            $value = str_replace('.', '', $value);
        }

        $value = str_replace(',', '.', $value);

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * Innermost cell mentioning "I.P. Kumulatif" (the summary is not inside a KHS table)
     */
    private function findCumulativeCell(Crawler $scope): ?DOMElement
    {
        $match = null;

        foreach ($scope->filter('td, th') as $cell) {
            if (! $cell instanceof DOMElement) {
                continue;
            }

            $text = $this->normalizePortalText($cell->textContent);

            if (preg_match('/I\.?\s*P\.?\s*Kumulatif|Indeks\s+Prestasi\s+Kumulatif/i', $text) !== 1) {
                continue;
            }

            if ($match === null || $this->contains($match, $cell)) {
                $match = $cell;
            }
        }

        return $match;
    }

    private function contains(DOMNode $ancestor, DOMNode $node): bool
    {
        for ($parent = $node->parentNode; $parent !== null; $parent = $parent->parentNode) {
            if ($parent->isSameNode($ancestor)) {
                return true;
            }
        }

        return false;
    }

    /**
     * "Label : value" pairs of the summary cell, in order
     *
     * @return array<string, float>
     */
    private function cumulativeFields(DOMElement $cell): array
    {
        $html = '';

        foreach ($cell->childNodes as $child) {
            $html .= $cell->ownerDocument?->saveHTML($child) ?: '';
        }

        $lines = preg_split('/<br\s*\/?>/i', $html) ?: [];
        $fields = [];

        foreach ($lines as $line) {
            $text = $this->normalizePortalText(strip_tags($line));

            if (preg_match('/^(.+?)\s*:\s*([\d.,]+)$/u', $text, $matches) === 1) {
                $value = $this->parseDecimal($matches[2]);

                if ($value !== null) {
                    $fields[$this->normalizePortalText($matches[1])] = $value;
                }
            }
        }

        if ($fields !== []) {
            return $fields;
        }

        preg_match_all('/([^:\d]+?)\s*:\s*(\d+(?:[.,]\d+)?)/u', $this->normalizePortalText($cell->textContent), $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $value = $this->parseDecimal($match[2]);

            if ($value !== null) {
                $fields[$this->normalizePortalText($match[1])] = $value;
            }
        }

        return $fields;
    }

    private function snakeLabel(string $label): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($label)), '_');
    }

    private function extractTranscriptPrintUrl(Crawler $crawler): ?string
    {
        if ($this->mainContent($crawler)->filter('a[href*="transcripts/print.php"]')->count() === 0) {
            return null;
        }

        return PortalEndpointRegistry::url('sipandai_transcript_print');
    }

    /**
     * Counts only: grades are personal data and never go to the log
     *
     * @param  array{semesters: list<KhsSemester>, cumulative: KhsCumulative|null}  $parsed
     * @return array<string, int|bool>
     */
    private function parsedLogContext(array $parsed): array
    {
        return [
            'semester_count' => count($parsed['semesters']),
            'graded_course_count' => array_sum(array_map(static fn (array $semester): int => $semester['graded_count'], $parsed['semesters'])),
            'has_cumulative_summary' => $parsed['cumulative'] !== null,
        ];
    }
}
