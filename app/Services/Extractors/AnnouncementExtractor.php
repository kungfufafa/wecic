<?php

declare(strict_types=1);

namespace App\Services\Extractors;

use App\Exceptions\PortalScrapingException;
use App\Services\PortalEndpointRegistry;
use App\Services\PortalScrapingService;
use App\Services\PortalUrlGuard;
use Carbon\CarbonImmutable;
use DOMElement;
use DOMNode;
use DOMText;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Announcements from portal/mahasiswa/?mod=student&act=pengumuman (F03)
 *
 * @phpstan-type AnnouncementLink array{label: string, url: string, is_file: bool, is_campus_file: bool, kind: 'form'|'file'|'link'}
 * @phpstan-type AnnouncementDeadline array{date: string, start: string|null, type: 'deadline'|'period'|'date', context: string, is_future: bool}
 * @phpstan-type Announcement array{
 *     id: string,
 *     title: string,
 *     published_at: string|null,
 *     published_date: string|null,
 *     published_label: string|null,
 *     publisher: string|null,
 *     publisher_unit: string|null,
 *     publisher_unit_short: string|null,
 *     category: 'krs'|'pembayaran'|'semester_pendek'|'surat'|'akademik'|'umum',
 *     category_label: string,
 *     excerpt: string,
 *     body_text: string,
 *     body_html: string,
 *     links: list<AnnouncementLink>,
 *     emails: list<string>,
 *     deadlines: list<AnnouncementDeadline>,
 *     steps: list<array{number: int, text: string}>
 * }
 * @phpstan-type AnnouncementPaging array{
 *     page: int,
 *     per_page: int,
 *     from: int|null,
 *     to: int|null,
 *     total: int|null,
 *     last_page: int|null,
 *     has_previous: bool,
 *     has_next: bool,
 *     previous_page: int|null,
 *     next_page: int|null,
 *     previous_url: string|null,
 *     next_url: string|null,
 *     out_of_range: bool
 * }
 */
class AnnouncementExtractor extends PortalScrapingService
{
    /**
     * The portal always lists three announcements per page.
     */
    public const PER_PAGE = 3;

    /**
     * @var array<string, string>
     */
    public const CATEGORY_LABELS = [
        'krs' => 'KRS & Her-Registrasi',
        'pembayaran' => 'Keuangan',
        'semester_pendek' => 'Semester Pendek',
        'surat' => 'Surat',
        'akademik' => 'Akademik',
        'umum' => 'Umum',
    ];

    /**
     * Keyword rules per category, checked in order (first match wins).
     *
     * @var array<string, string>
     */
    private const CATEGORY_PATTERNS = [
        'semester_pendek' => '/(?i:semester\s+(?:pendek|antara))|\bSP\b/u',
        'krs' => '/\bkrs\b|her[\s-]?registrasi|perwalian|mata\s?kuliah\s+tawar/iu',
        'pembayaran' => '/pembayaran|\bbayar\b|tagihan|\bukt\b|\bspp\b|keuangan|biaya|kwitansi|kuitansi|pelunasan|\blunas\b|beasiswa/iu',
        'surat' => '/\bsurat\b|legalisir/iu',
        'akademik' => '/akademik|kuliah|ujian|\buts\b|\buas\b|jadwal|nilai|\bkhs\b|transkrip|pddikti|data\s+mahasiswa|wisuda|yudisium|skripsi|kolokium|sidang|\bedom\b|\bcuti\b|kelulusan|kalender/iu',
    ];

    /**
     * Elements removed together with everything inside them.
     *
     * @var list<string>
     */
    private const DROPPED_TAGS = [
        'script', 'style', 'noscript', 'template', 'iframe', 'object', 'embed', 'applet', 'form', 'input', 'button',
        'select', 'option', 'textarea', 'svg', 'math', 'head', 'title', 'meta', 'link', 'base', 'frame', 'frameset',
        'canvas', 'audio', 'video', 'source', 'track', 'map', 'area', 'hr',
    ];

    /**
     * Elements kept as-is (without attributes) in the sanitized body.
     *
     * @var list<string>
     */
    private const ALLOWED_TAGS = ['p', 'b', 'strong', 'i', 'em', 'ul', 'ol', 'li', 'table', 'tr', 'td', 'th'];

    /**
     * Elements that start a new line in the plain-text body.
     *
     * @var list<string>
     */
    private const BLOCK_TAGS = [
        'p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'table', 'tbody', 'thead', 'tfoot', 'tr',
        'blockquote', 'section', 'article', 'center', 'pre', 'dl', 'dt', 'dd', 'address', 'figure', 'header', 'footer',
        'main', 'aside', 'nav', 'marquee',
    ];

    /**
     * Portal actions with side effects that must never reach the browser (K13).
     */
    private const PORTAL_ACTION_PATTERN = '/(?:^|&)(?:act=(?:kesesuaian|jobdel\w*|\w*del\w*|\w*hapus\w*)|mod=logout)(?:&|$)/i';

    private const FILE_EXTENSION_PATTERN = '/\.(?:pdf|docx?|xlsx?|pptx?|odt|ods|zip|rar|7z|csv|txt|jpe?g|png|gif|webp)$/i';

    private const DEADLINE_WORDS_PATTERN = '/paling\s+lambat|selambat|batas\s+(?:akhir|waktu|pengisian|pembayaran)|terakhir|deadline|tenggat|ditutup/iu';

    /**
     * One page of announcements (three per page, newest first)
     *
     * @return array{items: list<Announcement>, paging: AnnouncementPaging, notice: array{text: string, dates: list<string>, is_future: bool, kind: string}|null, extracted_at: string, _sync?: array<string, mixed>}
     */
    public function extractAnnouncementsData(string $sessionToken, int $page = 1): array
    {
        $page = max(1, $page);

        try {
            $this->setSession($sessionToken);
            $this->logScraping('Extracting announcements', ['session_token' => $sessionToken, 'page' => $page]);

            return $this->rememberResource('announcements', ['page' => $page], function () use ($page): array {
                $url = $this->announcementPageUrl($page);
                $html = $this->get($url);

                $this->ensureNotLoginPage($html, $url);

                $data = $this->parseAnnouncementPage($this->createCrawler($html), $page);

                $this->logScraping('Announcements extracted successfully', [
                    'page' => $page,
                    'items_count' => count($data['items']),
                    'total' => $data['paging']['total'],
                ]);

                return $data;
            });
        } catch (PortalScrapingException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logScrapingError('Extracting announcements', $e, ['session_token' => $sessionToken, 'page' => $page]);
            throw PortalScrapingException::dataExtractionFailed('announcements', $e->getMessage());
        }
    }

    /**
     * Portal URL of an announcement page; the portal's own paging links
     * wrongly point to act=dashboard, so they are always rebuilt here.
     */
    public function announcementPageUrl(int $page): string
    {
        return PortalEndpointRegistry::url('student_announcement', $page > 1 ? ['page' => $page] : []);
    }

    /**
     * @return array{items: list<Announcement>, paging: AnnouncementPaging, notice: array{text: string, dates: list<string>, is_future: bool, kind: string}|null, extracted_at: string}
     */
    private function parseAnnouncementPage(Crawler $crawler, int $page): array
    {
        $content = $this->mainContent($crawler);
        $cells = $this->announcementCells($content);
        $pagingNode = $content->filter('div.paging');

        if ($cells === [] && $pagingNode->count() === 0 && $content->filter('div.table-full-width')->count() === 0) {
            throw PortalScrapingException::dataExtractionFailed('announcements', 'announcement list not found on the page');
        }

        $items = [];

        foreach ($cells as $cell) {
            $item = $this->parseAnnouncementCell($cell);

            if ($item !== null) {
                $items[] = $item;
            }
        }

        $paging = $this->buildPaging($pagingNode, $page, count($items));

        if ($paging['out_of_range']) {
            $items = [];
        }

        $header = $content->filter('.card .header');

        return [
            'items' => $items,
            'paging' => $paging,
            'notice' => $this->extractPortalNotice($header->count() > 0 ? $header->first() : $content, 'auto'),
            'extracted_at' => now()->toISOString(),
        ];
    }

    /**
     * Announcement cells (one per announcement) in document order
     *
     * These are the td cells of the list table's own rows: the list table may
     * have no tbody, and its rows may be wrapped in a form or a formatting tag.
     * Cells of a table nested in an announcement body are never included.
     * Live pages are parsed from raw HTML without a browser's error recovery.
     * A stray </table> or </div> in an announcement body therefore closes the
     * list table, and the rows after it stay as bare tr/td elements outside
     * any table (a browser would ignore the </div>). Those cells are
     * announcements too, so no announcement is dropped.
     *
     * @return list<DOMElement>
     */
    private function announcementCells(Crawler $content): array
    {
        $root = $content->getNode(0);
        $cells = [];

        if ($root instanceof DOMElement) {
            $this->collectAnnouncementCells($root, $this->isListContainer($root), $cells);
        }

        return $cells;
    }

    /**
     * @param  list<DOMElement>  $cells
     */
    private function collectAnnouncementCells(DOMElement $parent, bool $insideList, array &$cells): void
    {
        foreach ($parent->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->nodeName);

            if ($tag === 'table') {
                if ($insideList) {
                    array_push($cells, ...$this->listTableCells($child));
                }

                continue;
            }

            if ($tag === 'td') {
                $cells[] = $child;

                continue;
            }

            $this->collectAnnouncementCells($child, $insideList || $this->isListContainer($child), $cells);
        }
    }

    /**
     * @return list<DOMElement>
     */
    private function listTableCells(DOMElement $table): array
    {
        $cells = [];

        foreach ($this->tableRows(new Crawler($table)) as $row) {
            foreach ($this->rowCells($row) as $cell) {
                $node = $cell->getNode(0);

                if ($node instanceof DOMElement && strtolower($node->nodeName) === 'td') {
                    $cells[] = $node;
                }
            }
        }

        return $cells;
    }

    private function isListContainer(DOMElement $element): bool
    {
        return strtolower($element->nodeName) === 'div'
            && in_array('table-full-width', preg_split('/\s+/', $element->getAttribute('class'), -1, PREG_SPLIT_NO_EMPTY) ?: [], true);
    }

    /**
     * One announcement cell: "<b><h5>title</h5></b> <date>, Dipublish oleh : <name> (<unit>)<br><hr><body>"
     *
     * @return Announcement|null
     */
    private function parseAnnouncementCell(DOMElement $cell): ?array
    {
        [$headerNodes, $bodyNodes] = $this->splitAnnouncementCell($cell);

        $title = $this->announcementTitle($headerNodes === [] ? [$cell] : $headerNodes);
        $meta = $this->announcementMeta($headerNodes, $title);
        $found = ['links' => [], 'emails' => []];
        $bodyHtml = $this->cleanSanitizedHtml($this->sanitizeNodes($bodyNodes, $found));
        $bodyText = $this->plainText($bodyNodes);

        if ($title === '' && $bodyText === '') {
            return null;
        }

        $publishedLabel = $this->publishedLabel($meta);
        $publishedDate = $publishedLabel === null ? null : $this->parseIndonesianDate($publishedLabel);
        [$publisher, $publisherUnit] = $this->publisher($meta);
        $category = $this->categorize($title, $bodyText);

        return [
            'id' => sha1(implode('|', [$title, $publishedDate?->toDateString() ?? '', $publisherUnit ?? '', $bodyText])),
            'title' => $title,
            'published_at' => $publishedDate?->toIso8601String(),
            'published_date' => $publishedDate?->toDateString(),
            'published_label' => $publishedLabel,
            'publisher' => $publisher,
            'publisher_unit' => $publisherUnit,
            'publisher_unit_short' => $this->unitAbbreviation($publisherUnit),
            'category' => $category,
            'category_label' => self::CATEGORY_LABELS[$category],
            'excerpt' => Str::limit(str_replace("\n", ' ', $bodyText), 180, preserveWords: true),
            'body_text' => $bodyText,
            'body_html' => $bodyHtml,
            'links' => array_values($found['links']),
            'emails' => array_values($found['emails']),
            'deadlines' => $this->extractDeadlines($title, $bodyText),
            'steps' => $this->extractSteps($bodyText),
        ];
    }

    /**
     * Header nodes (title and meta line) and body nodes, split at the cell's first direct <hr>
     *
     * Without an <hr> the header ends at the first <br>; without either the
     * whole cell is body.
     *
     * @return array{0: list<DOMNode>, 1: list<DOMNode>}
     */
    private function splitAnnouncementCell(DOMElement $cell): array
    {
        $children = [];

        foreach ($cell->childNodes as $child) {
            $children[] = $child;
        }

        foreach (['hr', 'br'] as $separator) {
            foreach ($children as $index => $child) {
                if ($child instanceof DOMElement && strtolower($child->nodeName) === $separator) {
                    return [array_slice($children, 0, $index), array_slice($children, $index + 1)];
                }
            }
        }

        return [[], $children];
    }

    /**
     * @param  list<DOMNode>  $nodes
     */
    private function announcementTitle(array $nodes): string
    {
        foreach (['h5', 'h4', 'h3', 'h6', 'h2', 'h1', 'b', 'strong'] as $tag) {
            foreach ($nodes as $node) {
                if (! $node instanceof DOMElement) {
                    continue;
                }

                $element = strtolower($node->nodeName) === $tag ? $node : $node->getElementsByTagName($tag)->item(0);
                $text = $element === null ? '' : $this->normalizePortalText($element->textContent);

                if ($text !== '') {
                    return $text;
                }
            }
        }

        return '';
    }

    /**
     * Header text without the title: "<Hari>, <dd> <Bulan> <yyyy>, Dipublish oleh : <name> (<unit>)"
     *
     * @param  list<DOMNode>  $headerNodes
     */
    private function announcementMeta(array $headerNodes, string $title): string
    {
        $text = $this->normalizePortalText(implode(' ', array_map(
            static fn (DOMNode $node): string => $node->textContent,
            $headerNodes
        )));

        if ($title !== '') {
            $text = Str::startsWith($text, $title) ? substr($text, strlen($title)) : Str::replaceFirst($title, '', $text);
        }

        return trim($text, " ,:-\t\n");
    }

    private function publishedLabel(string $meta): ?string
    {
        $label = preg_match('/^(.*?)[\s,]*Dipublish\b/iu', $meta, $match) === 1 ? $match[1] : $meta;
        $label = trim($label, ' ,');

        return $label === '' ? null : $label;
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function publisher(string $meta): array
    {
        if (preg_match('/Dipublish\s+oleh\s*:?\s*(?<who>.+)$/iu', $meta, $match) !== 1) {
            return [null, null];
        }

        $who = trim($match['who']);

        if (preg_match('/^(?<name>.*?)\s*\((?<unit>[^()]*)\)\s*$/u', $who, $parts) === 1) {
            $name = trim($parts['name']);
            $unit = trim($parts['unit']);

            return [$name === '' ? null : $name, $unit === '' ? null : $unit];
        }

        return [$who === '' ? null : $who, null];
    }

    /**
     * "Biro Administrasi Akademik dan Kemahasiswaan" becomes "BAAK"; a unit
     * that is already a single word is returned unchanged.
     */
    private function unitAbbreviation(?string $unit): ?string
    {
        if ($unit === null) {
            return null;
        }

        $words = preg_split('/[\s\/&,.\-]+/u', $unit, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_values(array_filter(
            $words,
            static fn (string $word): bool => ! in_array(mb_strtolower($word), ['dan', 'serta', 'and', 'of', 'the', 'untuk', 'di'], true)
        ));

        if (count($words) < 2) {
            return $unit;
        }

        return implode('', array_map(static fn (string $word): string => mb_strtoupper(mb_substr($word, 0, 1)), $words));
    }

    /**
     * @return 'krs'|'pembayaran'|'semester_pendek'|'surat'|'akademik'|'umum'
     */
    private function categorize(string $title, string $bodyText): string
    {
        foreach ([$title, $bodyText] as $text) {
            foreach (self::CATEGORY_PATTERNS as $category => $pattern) {
                if ($text !== '' && preg_match($pattern, $text) === 1) {
                    return $category;
                }
            }
        }

        return 'umum';
    }

    /**
     * Dates written in the title or body, one entry per date or range, earliest first
     *
     * `date` is the last day (the end of a range); `start` is only set for ranges.
     *
     * @return list<AnnouncementDeadline>
     */
    private function extractDeadlines(string $title, string $bodyText): array
    {
        $today = CarbonImmutable::instance(now())->setTimezone(self::PORTAL_TIMEZONE)->startOfDay();
        $deadlines = [];

        foreach (array_merge([$title], explode("\n", $bodyText)) as $line) {
            if (trim($line) === '') {
                continue;
            }

            $rangeDays = [];

            foreach ($this->extractIndonesianDateRanges($line) as $range) {
                $deadlines[] = $this->deadline($range['start'], $range['end'], $line, $today);
                $rangeDays[] = $range['start']->toDateString();
                $rangeDays[] = $range['end']->toDateString();
            }

            foreach ($this->extractIndonesianDates($line) as $date) {
                if (! in_array($date->toDateString(), $rangeDays, true)) {
                    $deadlines[] = $this->deadline(null, $date, $line, $today);
                }
            }
        }

        $unique = [];

        foreach ($deadlines as $deadline) {
            $unique[($deadline['start'] ?? '').'|'.$deadline['date']] ??= $deadline;
        }

        $unique = array_values($unique);
        usort($unique, static fn (array $left, array $right): int => [$left['date'], $left['start'] ?? ''] <=> [$right['date'], $right['start'] ?? '']);

        return $unique;
    }

    /**
     * @return AnnouncementDeadline
     */
    private function deadline(?CarbonImmutable $start, CarbonImmutable $end, string $line, CarbonImmutable $today): array
    {
        $type = match (true) {
            preg_match(self::DEADLINE_WORDS_PATTERN, $line) === 1 => 'deadline',
            $start !== null => 'period',
            default => 'date',
        };

        return [
            'date' => $end->toDateString(),
            'start' => $start?->toDateString(),
            'type' => $type,
            'context' => Str::limit($line, 200, preserveWords: true),
            'is_future' => $end->greaterThanOrEqualTo($today),
        ];
    }

    /**
     * Numbered lines ("1. ...", "2) ...") as a checklist; empty unless there are at least two
     *
     * @return list<array{number: int, text: string}>
     */
    private function extractSteps(string $bodyText): array
    {
        $steps = [];

        foreach (explode("\n", $bodyText) as $line) {
            if (preg_match('/^(?<number>\d{1,2})[.)]\s+(?<text>\S.*)$/u', $line, $match) === 1) {
                $steps[] = ['number' => (int) $match['number'], 'text' => trim($match['text'])];
            }
        }

        return count($steps) >= 2 ? $steps : [];
    }

    /**
     * div.paging reads "<< Previous | [ <from>-<to> of <total> ] | Next >>", where
     * total counts announcements and `to` always repeats `from` (portal bug)
     *
     * @return AnnouncementPaging
     */
    private function buildPaging(Crawler $pagingNode, int $page, int $itemCount): array
    {
        $label = $pagingNode->count() > 0 ? $this->normalizePortalText($pagingNode->first()->text('')) : '';
        $total = null;
        $portalFrom = null;

        if (preg_match('/\[\s*(\d+)\s*-\s*\d+\s*(?:of|dari)\s*(\d+)\s*\]/iu', $label, $match) === 1) {
            $portalFrom = (int) $match[1];
            $total = (int) $match[2];
        }

        $lastPage = $total === null ? null : max(1, (int) ceil($total / self::PER_PAGE));
        $outOfRange = $lastPage !== null && $page > $lastPage;
        $from = $itemCount > 0 && ! $outOfRange ? ($page - 1) * self::PER_PAGE + 1 : null;
        $hasNext = $lastPage !== null && $page < $lastPage;

        if ($from !== null && $portalFrom !== null && $portalFrom !== $from) {
            $this->logScrapingDebug('Announcement paging offset differs from the requested page', [
                'page' => $page,
                'portal_from' => $portalFrom,
                'expected_from' => $from,
            ]);
        }

        return [
            'page' => $page,
            'per_page' => self::PER_PAGE,
            'from' => $from,
            'to' => $from === null ? null : min($from + $itemCount - 1, $total ?? PHP_INT_MAX),
            'total' => $total,
            'last_page' => $lastPage,
            'has_previous' => $page > 1,
            'has_next' => $hasNext,
            'previous_page' => $page > 1 ? $page - 1 : null,
            'next_page' => $hasNext ? $page + 1 : null,
            'previous_url' => $page > 1 ? $this->announcementPageUrl($page - 1) : null,
            'next_url' => $hasNext ? $this->announcementPageUrl($page + 1) : null,
            'out_of_range' => $outOfRange,
        ];
    }

    /**
     * Plain text of the body: one line per paragraph, line break or table row
     *
     * @param  list<DOMNode>  $nodes
     */
    private function plainText(array $nodes): string
    {
        $buffer = '';

        foreach ($nodes as $node) {
            $this->appendPlainText($node, $buffer);
        }

        $lines = array_map(fn (string $line): string => $this->normalizePortalText($line), explode("\n", $buffer));

        return implode("\n", array_values(array_filter($lines, static fn (string $line): bool => $line !== '')));
    }

    private function appendPlainText(DOMNode $node, string &$buffer): void
    {
        if ($node instanceof DOMText) {
            $buffer .= $node->textContent;

            return;
        }

        if (! $node instanceof DOMElement) {
            return;
        }

        $tag = strtolower($node->nodeName);

        if ($tag === 'br' || $tag === 'hr') {
            $buffer .= "\n";

            return;
        }

        if (in_array($tag, self::DROPPED_TAGS, true)) {
            return;
        }

        $email = $this->cloudflareEmailOf($node);

        if ($email !== null) {
            $buffer .= $email;

            return;
        }

        $isBlock = in_array($tag, self::BLOCK_TAGS, true);
        $buffer .= match (true) {
            $tag === 'li' => "\n• ",
            $isBlock => "\n",
            $tag === 'td' || $tag === 'th' => ' ',
            default => '',
        };

        foreach ($node->childNodes as $child) {
            $this->appendPlainText($child, $buffer);
        }

        if ($isBlock) {
            $buffer .= "\n";
        }
    }

    /**
     * @param  iterable<DOMNode>  $nodes
     * @param  array{links: array<string, AnnouncementLink>, emails: array<string, string>}  $found
     */
    private function sanitizeNodes(iterable $nodes, array &$found, bool $insideLink = false): string
    {
        $html = '';

        foreach ($nodes as $node) {
            $html .= $this->sanitizeNode($node, $found, $insideLink);
        }

        return $html;
    }

    /**
     * Allowlist sanitizer: p, br, b, strong, i, em, ul, ol, li, a, table, tr, td, th
     * without attributes (except a safe href and td/th spans); headings become
     * bold paragraphs, other tags are unwrapped, scripts and forms are dropped.
     *
     * @param  array{links: array<string, AnnouncementLink>, emails: array<string, string>}  $found
     */
    private function sanitizeNode(DOMNode $node, array &$found, bool $insideLink): string
    {
        if ($node instanceof DOMText) {
            $text = $this->collapseWhitespace($node->textContent);

            return $insideLink ? e($text) : $this->linkifyText($text, $found);
        }

        if (! $node instanceof DOMElement) {
            return '';
        }

        $tag = strtolower($node->nodeName);

        if (in_array($tag, self::DROPPED_TAGS, true)) {
            return '';
        }

        $email = $this->cloudflareEmailOf($node);

        if ($email !== null) {
            $found['emails'][strtolower($email)] ??= $email;

            return $insideLink ? e($email) : '<a href="mailto:'.e($email).'">'.e($email).'</a>';
        }

        if ($tag === 'br') {
            return '<br>';
        }

        if ($tag === 'a') {
            return $this->sanitizeAnchor($node, $found, $insideLink);
        }

        if ($tag === 'img') {
            $this->rememberImage($node, $found);

            return '';
        }

        $inner = $this->sanitizeNodes($node->childNodes, $found, $insideLink);

        if (in_array($tag, self::ALLOWED_TAGS, true)) {
            return '<'.$tag.$this->cellSpanAttributes($node, $tag).'>'.$inner.'</'.$tag.'>';
        }

        if (preg_match('/^h[1-6]$/', $tag) === 1) {
            return preg_match('~^\s*<(strong|b)>.*</\1>\s*$~s', $inner) === 1 ? '<p>'.$inner.'</p>' : '<p><strong>'.$inner.'</strong></p>';
        }

        if (in_array($tag, self::BLOCK_TAGS, true) && ! in_array($tag, ['tbody', 'thead', 'tfoot', 'dl'], true)) {
            return $this->hasBlockChild($node) ? $inner : '<p>'.$inner.'</p>';
        }

        return $inner;
    }

    /**
     * @param  array{links: array<string, AnnouncementLink>, emails: array<string, string>}  $found
     */
    private function sanitizeAnchor(DOMElement $anchor, array &$found, bool $insideLink): string
    {
        $url = $insideLink ? null : $this->resolveLinkUrl($anchor->getAttribute('href'));
        $inner = $this->sanitizeNodes($anchor->childNodes, $found, $insideLink || $url !== null);

        if ($url === null) {
            return $inner;
        }

        if (trim(strip_tags($inner)) === '') {
            $inner = e(Str::after($url, 'mailto:'));
        }

        if (str_starts_with($url, 'mailto:')) {
            $email = Str::before(Str::after($url, 'mailto:'), '?');
            $found['emails'][strtolower($email)] ??= $email;

            return '<a href="'.e($url).'">'.$inner.'</a>';
        }

        if (! str_starts_with($url, 'tel:')) {
            $this->rememberLink($found, $url, $this->normalizePortalText($anchor->textContent));
        }

        $attributes = str_starts_with($url, 'tel:') ? '' : ' target="_blank" rel="noopener noreferrer"';

        return '<a href="'.e($url).'"'.$attributes.'>'.$inner.'</a>';
    }

    /**
     * Images are not kept in the body; they are listed as links instead.
     *
     * @param  array{links: array<string, AnnouncementLink>, emails: array<string, string>}  $found
     */
    private function rememberImage(DOMElement $image, array &$found): void
    {
        $url = $this->resolveLinkUrl($image->getAttribute('src'));

        if ($url !== null && preg_match('/^https?:/i', $url) === 1) {
            $alt = $this->normalizePortalText($image->getAttribute('alt'));
            $this->rememberLink($found, $url, $alt !== '' ? $alt : 'Gambar');
        }
    }

    /**
     * Wrap plain URLs and e-mail addresses written as text in links
     *
     * @param  array{links: array<string, AnnouncementLink>, emails: array<string, string>}  $found
     */
    private function linkifyText(string $text, array &$found): string
    {
        $pattern = '~(?<url>\b(?:https?://|www\.)[^\s<>"\']+)|(?<email>\b[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}\b)~iu';

        if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL) === 0) {
            return e($text);
        }

        $html = '';
        $cursor = 0;

        foreach ($matches as $match) {
            [$value, $offset] = $match[0];
            $html .= e(substr($text, $cursor, $offset - $cursor));
            $cursor = $offset + strlen($value);

            if (($match['email'][0] ?? null) !== null) {
                $found['emails'][strtolower($value)] ??= $value;
                $html .= '<a href="mailto:'.e($value).'">'.e($value).'</a>';

                continue;
            }

            $trimmed = $this->trimUrlPunctuation($value);
            $url = $this->resolveLinkUrl(str_starts_with(strtolower($trimmed), 'www.') ? 'https://'.$trimmed : $trimmed);

            if ($url === null || preg_match('/^https?:/i', $url) !== 1) {
                $html .= e($value);

                continue;
            }

            $this->rememberLink($found, $url, null);
            $html .= '<a href="'.e($url).'" target="_blank" rel="noopener noreferrer">'.e($trimmed).'</a>'.e(substr($value, strlen($trimmed)));
        }

        return $html.e(substr($text, $cursor));
    }

    private function trimUrlPunctuation(string $url): string
    {
        $url = rtrim($url, '.,;:!?\'"');

        while (str_ends_with($url, ')') && substr_count($url, '(') < substr_count($url, ')')) {
            $url = rtrim(substr($url, 0, -1), '.,;:!?\'"');
        }

        return $url;
    }

    /**
     * Absolute, safe URL for an href: http(s) (relative ones resolved against
     * the announcement page), mailto: or tel:; null for anything else
     */
    private function resolveLinkUrl(string $href): ?string
    {
        $href = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $href) ?? '');

        if ($href === '' || str_starts_with($href, '#')) {
            return null;
        }

        if (preg_match('/^mailto:(?<email>[^?]+)/i', $href, $match) === 1) {
            $email = rawurldecode(trim($match['email']));

            return filter_var($email, FILTER_VALIDATE_EMAIL) ? 'mailto:'.$email : null;
        }

        if (preg_match('/^tel:(?<number>.+)$/i', $href, $match) === 1) {
            $number = preg_replace('/[^\d+]/', '', $match['number']) ?? '';

            return $number === '' ? null : 'tel:'.$number;
        }

        if (str_starts_with($href, '//')) {
            $href = 'https:'.$href;
        }

        $url = str_replace(' ', '%20', preg_match('/^[a-z][a-z0-9+.\-]*:/i', $href) === 1 ? $href : $this->absolutePortalUrl($href));
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if (! in_array($scheme, ['http', 'https'], true) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return $this->isPortalActionUrl($url) ? null : $url;
    }

    private function absolutePortalUrl(string $reference): string
    {
        $base = $this->announcementPageUrl(1);
        $parts = parse_url($base) ?: [];
        $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');
        $basePath = $parts['path'] ?? '/';

        if (str_starts_with($reference, '?')) {
            return $origin.$basePath.$reference;
        }

        $path = str_starts_with($reference, '/')
            ? $reference
            : Str::beforeLast($basePath, '/').'/'.$reference;
        $suffix = '';

        if (preg_match('/[?#]/', $path, $match, PREG_OFFSET_CAPTURE) === 1) {
            $suffix = substr($path, $match[0][1]);
            $path = substr($path, 0, $match[0][1]);
        }

        return $origin.$this->removeDotSegments($path).$suffix;
    }

    private function removeDotSegments(string $path): string
    {
        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                array_pop($segments);
            } elseif ($segment !== '.' && $segment !== '') {
                $segments[] = $segment;
            }
        }

        return '/'.implode('/', $segments).(str_ends_with($path, '/') && $segments !== [] ? '/' : '');
    }

    private function isPortalActionUrl(string $url): bool
    {
        $portalHost = strtolower((string) parse_url((string) config('portal.base_url'), PHP_URL_HOST));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $query = (string) parse_url($url, PHP_URL_QUERY);

        return $portalHost !== '' && $host === $portalHost && preg_match(self::PORTAL_ACTION_PATTERN, $query) === 1;
    }

    /**
     * @param  array{links: array<string, AnnouncementLink>, emails: array<string, string>}  $found
     */
    private function rememberLink(array &$found, string $url, ?string $label): void
    {
        $hasLabel = $label !== null && $label !== '' && preg_match('~^(?:https?://|www\.)~i', $label) !== 1;
        $existing = $found['links'][$url] ?? null;

        if ($existing !== null) {
            if ($hasLabel && $existing['label'] === $this->defaultLinkLabel($url, $existing['kind'])) {
                $found['links'][$url]['label'] = $label;
            }

            return;
        }

        $isFile = $this->isFileUrl($url);
        $kind = match (true) {
            $this->isFormUrl($url) => 'form',
            $isFile => 'file',
            default => 'link',
        };

        $found['links'][$url] = [
            'label' => $hasLabel ? $label : $this->defaultLinkLabel($url, $kind),
            'url' => $url,
            'is_file' => $isFile,
            'is_campus_file' => PortalUrlGuard::campusFileUrl($url) !== null,
            'kind' => $kind,
        ];
    }

    private function defaultLinkLabel(string $url, string $kind): string
    {
        $host = Str::after(strtolower((string) parse_url($url, PHP_URL_HOST)), 'www.');
        $basename = rawurldecode(basename((string) parse_url($url, PHP_URL_PATH)));

        return match ($kind) {
            'form' => str_contains($host, 'google') || $host === 'forms.gle' ? 'Formulir Google' : 'Formulir',
            'file' => $basename !== '' ? $basename : 'Berkas',
            default => $host !== '' ? $host : 'Tautan',
        };
    }

    private function isFormUrl(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);

        return $host === 'forms.gle'
            || ($host === 'docs.google.com' && str_starts_with($path, '/forms'))
            || in_array($host, ['forms.office.com', 'forms.microsoft.com', 'form.jotform.com'], true)
            || str_ends_with($host, '.typeform.com');
    }

    private function isFileUrl(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);

        return preg_match(self::FILE_EXTENSION_PATTERN, $path) === 1
            || ($host === 'drive.google.com' && str_starts_with($path, '/file/'));
    }

    /**
     * Decoded Cloudflare e-mail carried by the element itself (K4): its
     * data-cfemail attribute, or the fragment of an email-protection link
     */
    private function cloudflareEmailOf(DOMElement $element): ?string
    {
        if ($element->hasAttribute('data-cfemail')) {
            return $this->decodeCloudflareEmail(trim($element->getAttribute('data-cfemail')));
        }

        $href = $element->getAttribute('href');

        if (strtolower($element->nodeName) !== 'a' || ! str_contains($href, '/cdn-cgi/l/email-protection')) {
            return null;
        }

        $fragment = parse_url($href, PHP_URL_FRAGMENT);
        $email = is_string($fragment) ? $this->decodeCloudflareEmail(trim($fragment)) : null;

        if ($email === null) {
            foreach ($element->getElementsByTagName('*') as $descendant) {
                if ($descendant instanceof DOMElement && $descendant->hasAttribute('data-cfemail')) {
                    return $this->decodeCloudflareEmail(trim($descendant->getAttribute('data-cfemail')));
                }
            }
        }

        return $email;
    }

    private function cellSpanAttributes(DOMElement $element, string $tag): string
    {
        if ($tag !== 'td' && $tag !== 'th') {
            return '';
        }

        $attributes = '';

        foreach (['colspan', 'rowspan'] as $attribute) {
            $value = trim($element->getAttribute($attribute));

            if (preg_match('/^[1-9]\d?$/', $value) === 1 && $value !== '1') {
                $attributes .= ' '.$attribute.'="'.$value.'"';
            }
        }

        return $attributes;
    }

    private function hasBlockChild(DOMElement $element): bool
    {
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement && in_array(strtolower($child->nodeName), self::BLOCK_TAGS, true)) {
                return true;
            }
        }

        return false;
    }

    private function collapseWhitespace(string $text): string
    {
        $text = str_replace(["\u{00A0}", "\u{2007}", "\u{202F}", "\u{200B}"], [' ', ' ', ' ', ''], mb_scrub($text, 'UTF-8'));

        return (string) preg_replace('/\s+/u', ' ', $text);
    }

    /**
     * Drop empty paragraphs and inline wrappers, leading/trailing line breaks and doubled spaces
     */
    private function cleanSanitizedHtml(string $html): string
    {
        $html = (string) preg_replace('/ {2,}/', ' ', $html);
        $html = (string) preg_replace('~>\s+<(/?(?:p|table|tr|td|th|ul|ol|li|br)\b)~', '><$1', $html);
        $html = (string) preg_replace('~<(/?(?:p|table|tr|ul|ol|li)|td|th)>\s+~', '<$1>', $html);

        do {
            $previous = $html;
            $html = (string) preg_replace('~<(p|strong|b|em|i|li)>(?:\s|<br>)*</\1>~u', '', $html);
            $html = (string) preg_replace('~<p>(?:\s|<br>)+~u', '<p>', $html);
            $html = (string) preg_replace('~(?:\s|<br>)+</p>~u', '</p>', $html);
        } while ($html !== $previous);

        return trim((string) preg_replace('~^(?:\s|<br>)+|(?:\s|<br>)+$~u', '', $html));
    }
}
