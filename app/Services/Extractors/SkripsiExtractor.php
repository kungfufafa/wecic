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
 * Thesis hub data: SiMASITA requirements and SOP (F42, `act=simasita`) plus
 * the e-Skripsi status (F43, `act=skripsi`), read-only.
 *
 * Thresholds (minimum SKS per level, minimum IPK, D and E limits) are read
 * from the requirement text; any that cannot be read falls back to
 * `portal.thesis.default_thresholds` and is marked `default`, never guessed.
 *
 * @phpstan-type ThesisThresholds array{min_sks: array{D3: int, S1: int}, min_ipk: float, max_d: int, allow_e: bool}
 * @phpstan-type ThesisStatus array{
 *     state: 'not_enrolled'|'active_unparsed'|'unknown',
 *     message: string|null,
 *     raw_sections: list<array{title: string|null, fields: list<array{label: string, value: string}>, links: list<array{label: string, path: string|null}>, form_fields: list<string>}>,
 *     available: bool
 * }
 */
class SkripsiExtractor extends PortalScrapingService
{
    public const STATE_NOT_ENROLLED = 'not_enrolled';

    public const STATE_ACTIVE_UNPARSED = 'active_unparsed';

    public const STATE_UNKNOWN = 'unknown';

    private const IDENTITY_KEYS = ['fakultas', 'programstudi', 'prodi', 'nim', 'nama', 'namamahasiswa'];

    /**
     * Requirements, thresholds, SOP documents and the e-Skripsi status
     *
     * The status page is secondary: when it fails (other than an expired
     * session) the requirements are still returned with an `unknown` status.
     *
     * @return array{
     *     found: bool,
     *     title: string|null,
     *     student: array{faculty: string|null, program: string|null, program_raw: string|null, jenjang: 'D3'|'S1'|null, nim: string|null, name: string|null},
     *     requirements: array{thesis: list<string>, exam: list<string>},
     *     thresholds: ThesisThresholds,
     *     threshold_sources: array{min_sks_d3: 'portal'|'default', min_sks_s1: 'portal'|'default', min_ipk: 'portal'|'default', max_d: 'portal'|'default', allow_e: 'portal'|'default'},
     *     threshold_source: 'portal'|'partial'|'default',
     *     documents: list<array{label: string, path: string, filename: string}>,
     *     status: ThesisStatus,
     *     extracted_at: string,
     *     _sync?: array<string, mixed>
     * }
     */
    public function extractSkripsiData(string $sessionToken): array
    {
        try {
            $this->setSession($sessionToken);
            $this->logScraping('Extracting thesis requirements and status', ['session_token' => $sessionToken]);

            return $this->rememberResource(
                'thesis',
                [],
                function (): array {
                    $url = PortalEndpointRegistry::url('student_simasita');
                    $html = $this->get($url);

                    $this->ensureNotLoginPage($html, $url);

                    $data = [
                        ...$this->parseSimasitaPage($html),
                        'status' => $this->fetchStatus(),
                        'extracted_at' => now()->toISOString(),
                    ];

                    $this->logScraping('Thesis data extracted successfully', [
                        'found' => $data['found'],
                        'threshold_source' => $data['threshold_source'],
                        'status' => $data['status']['state'],
                    ]);

                    return $data;
                },
                static fn (array $data): bool => ($data['found'] ?? false) === true
            );
        } catch (PortalScrapingException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logScrapingError('Extracting thesis data', $e, ['session_token' => $sessionToken]);
            throw PortalScrapingException::dataExtractionFailed('thesis', $e->getMessage());
        }
    }

    /**
     * Identity, requirement lists, thresholds and SOP links of `act=simasita`
     *
     * @return array<string, mixed>
     */
    public function parseSimasitaPage(string $html): array
    {
        $content = $this->mainContent($this->createCrawler($html));
        $student = $this->extractStudent($content);
        $requirements = $this->extractRequirements($content);
        [$thresholds, $sources] = $this->extractThresholds([...$requirements['thesis'], ...$requirements['exam']]);
        $portalSources = count(array_filter($sources, static fn (string $source): bool => $source === 'portal'));

        return [
            'found' => $requirements['thesis'] !== [] || $student['nim'] !== null,
            'title' => $this->pageTitle($content),
            'student' => $student,
            'requirements' => $requirements,
            'thresholds' => $thresholds,
            'threshold_sources' => $sources,
            'threshold_source' => match (true) {
                $portalSources === count($sources) => 'portal',
                $portalSources === 0 => 'default',
                default => 'partial',
            },
            'documents' => $this->extractDocuments($content),
        ];
    }

    /**
     * e-Skripsi status of `act=skripsi` (F43)
     *
     * The status sits in a separate `table.table-hover` outside the cards:
     * `<center><h3>…tidak mengambil…</h3></center>` for a student without a
     * thesis. Anything else on the page is captured generically, without
     * values of form fields and without portal action links (K13).
     *
     * @return ThesisStatus
     */
    public function parseSkripsiStatusPage(string $html): array
    {
        $content = $this->mainContent($this->createCrawler($html));
        $heading = $content->filterXPath("descendant::table[contains(concat(' ', normalize-space(@class), ' '), ' table-hover ')]//h3");
        $message = $heading->count() > 0 ? $this->cellText($heading->first()) : null;
        $message = $message === '' ? null : $message;
        $sections = $this->rawSections($content);

        $state = match (true) {
            $message !== null && preg_match('/tidak\s+mengambil/iu', $message) === 1 => self::STATE_NOT_ENROLLED,
            $message !== null || $sections !== [] => self::STATE_ACTIVE_UNPARSED,
            default => self::STATE_UNKNOWN,
        };

        return [
            'state' => $state,
            'message' => $message,
            'raw_sections' => $state === self::STATE_NOT_ENROLLED ? [] : $sections,
            'available' => true,
        ];
    }

    /**
     * @return ThesisStatus
     */
    private function fetchStatus(): array
    {
        try {
            $url = PortalEndpointRegistry::url('student_skripsi');
            $html = $this->get($url);

            $this->ensureNotLoginPage($html, $url);

            return $this->parseSkripsiStatusPage($html);
        } catch (PortalException $e) {
            if (PortalException::indicatesExpiredSession($e)) {
                throw $e;
            }

            Log::warning('Thesis status page unavailable, requirements kept', ['error' => $e->getMessage()]);

            return ['state' => self::STATE_UNKNOWN, 'message' => null, 'raw_sections' => [], 'available' => false];
        }
    }

    private function pageTitle(Crawler $content): ?string
    {
        $title = $content->filter('.card .header h4.title, .card .header .title');

        return $title->count() > 0 ? ($this->cellText($title->first()) ?: null) : null;
    }

    /**
     * "label | : | value" rows: Fakultas, Program Studi ("<PRODI> - Strata S1"), "N I M", Nama
     *
     * @return array{faculty: string|null, program: string|null, program_raw: string|null, jenjang: 'D3'|'S1'|null, nim: string|null, name: string|null}
     */
    private function extractStudent(Crawler $content): array
    {
        $values = [];

        foreach ($this->labelledRows($content) as $row) {
            $values[$row['key']] ??= $row['value'];
        }

        $programRaw = $values['programstudi'] ?? $values['prodi'] ?? null;
        [$program, $jenjang] = $this->splitProgram($programRaw);

        return [
            'faculty' => $values['fakultas'] ?? null,
            'program' => $program,
            'program_raw' => $programRaw,
            'jenjang' => $jenjang,
            'nim' => $values['nim'] ?? null,
            'name' => $values['nama'] ?? $values['namamahasiswa'] ?? null,
        ];
    }

    /**
     * @return list<array{key: string, label: string, value: string}>
     */
    private function labelledRows(Crawler $scope): array
    {
        $rows = [];

        foreach ($scope->filter('tr') as $rowNode) {
            $cells = $this->rowCells(new Crawler($rowNode));

            if (count($cells) !== 3 || $this->cellText($cells[1]) !== ':') {
                continue;
            }

            $label = $this->cellText($cells[0]);
            $key = mb_strtolower((string) preg_replace('/[^\p{L}\p{N}]+/u', '', $label));
            $value = $this->cellText($cells[2]);

            if ($key !== '' && $value !== '' && $value !== '-') {
                $rows[] = ['key' => $key, 'label' => $label, 'value' => $value];
            }
        }

        return $rows;
    }

    /**
     * "SISTEM INFORMASI - Strata S1" → ["SISTEM INFORMASI", "S1"]
     *
     * @return array{0: string|null, 1: 'D3'|'S1'|null}
     */
    private function splitProgram(?string $raw): array
    {
        if ($raw === null) {
            return [null, null];
        }

        $jenjang = match (true) {
            preg_match('/\b(?:strata\s*-?\s*(?:1|satu|i)\b|S\s*-?\s*1\b)/iu', $raw) === 1 => 'S1',
            preg_match('/\b(?:diploma\s*-?\s*(?:3|iii|tiga)\b|D\s*-?\s*(?:3|III)\b)/iu', $raw) === 1 => 'D3',
            default => null,
        };
        $program = trim((string) preg_replace('/\s*[-–]\s*(?:strata|diploma|S\s*-?\s*1\b|D\s*-?\s*3\b|D\s*-?\s*III\b).*$/iu', '', $raw));

        return [$program !== '' ? $program : $raw, $jenjang];
    }

    /**
     * The two lists of the "Persyaratan dan Prosedure Tahapan Skripsi" card:
     * a list headed with "ujian" holds the exam requirements, the other the
     * thesis requirements (first list → thesis, second → exam when unheaded)
     *
     * @return array{thesis: list<string>, exam: list<string>}
     */
    private function extractRequirements(Crawler $content): array
    {
        $lists = [];

        $content->filter('ul')->each(function (Crawler $list) use (&$lists): void {
            if ($list->closest('.sidebar, .navbar, .dropdown-menu, .nav') !== null) {
                return;
            }

            $items = array_values(array_filter(
                $list->filter('li')->each(fn (Crawler $item): string => $this->cellText($item)),
                static fn (string $text): bool => $text !== ''
            ));

            if ($items !== []) {
                $lists[] = ['heading' => $this->listHeading($list), 'items' => $items];
            }
        });

        $requirements = ['thesis' => [], 'exam' => []];

        foreach ($lists as $index => $list) {
            $kind = match (true) {
                preg_match('/ujian|sidang/iu', $list['heading']) === 1 => 'exam',
                $list['heading'] !== '' => 'thesis',
                default => $index === 0 ? 'thesis' : 'exam',
            };

            array_push($requirements[$kind], ...$list['items']);
        }

        return $requirements;
    }

    /**
     * Text written before the list inside its cell (the bold "Syarat …:" line)
     */
    private function listHeading(Crawler $list): string
    {
        $node = $list->getNode(0);
        $text = '';

        for ($sibling = $node?->previousSibling; $sibling !== null; $sibling = $sibling->previousSibling) {
            if ($sibling instanceof \DOMElement && in_array(strtolower($sibling->nodeName), ['ul', 'ol', 'table'], true)) {
                break;
            }

            $text = $sibling->textContent.' '.$text;
        }

        return $this->normalizePortalText($text);
    }

    /**
     * @param  list<string>  $items
     * @return array{0: array{min_sks: array{D3: int, S1: int}, min_ipk: float, max_d: int, allow_e: bool}, 1: array{min_sks_d3: 'portal'|'default', min_sks_s1: 'portal'|'default', min_ipk: 'portal'|'default', max_d: 'portal'|'default', allow_e: 'portal'|'default'}}
     */
    private function extractThresholds(array $items): array
    {
        $text = implode("\n", $items);
        $defaults = self::defaultThresholds();
        $sources = [];

        $minSksD3 = $this->matchInt('/minimal\s+(\d+)\s+sks\s+untuk\s+D\s*-?\s*(?:3|III)\b/iu', $text);
        $minSksS1 = $this->matchInt('/minimal\s+(\d+)\s+sks\s+untuk\s+S\s*-?\s*1\b/iu', $text);
        $minIpk = preg_match('/IPK\s+minimal\s+(\d+(?:[.,]\d+)?)/iu', $text, $ipk) === 1 ? (float) str_replace(',', '.', $ipk[1]) : null;
        $maxD = $this->matchInt('/Maksimal\s+(\d+)\s+Nilai.*?\bD\b/iu', $text);
        $noE = preg_match('/tidak\s+(?:boleh\s+)?memiliki\s+nilai\s+E\b/iu', $text) === 1;

        $sources['min_sks_d3'] = $minSksD3 === null ? 'default' : 'portal';
        $sources['min_sks_s1'] = $minSksS1 === null ? 'default' : 'portal';
        $sources['min_ipk'] = $minIpk === null ? 'default' : 'portal';
        $sources['max_d'] = $maxD === null ? 'default' : 'portal';
        $sources['allow_e'] = $noE ? 'portal' : 'default';

        return [[
            'min_sks' => [
                'D3' => $minSksD3 ?? $defaults['min_sks']['D3'],
                'S1' => $minSksS1 ?? $defaults['min_sks']['S1'],
            ],
            'min_ipk' => $minIpk ?? $defaults['min_ipk'],
            'max_d' => $maxD ?? $defaults['max_d'],
            'allow_e' => $noE ? false : $defaults['allow_e'],
        ], $sources];
    }

    /**
     * `portal.thesis.default_thresholds`, used for every value the portal does not state
     *
     * @return array{min_sks: array{D3: int, S1: int}, min_ipk: float, max_d: int, allow_e: bool}
     */
    public static function defaultThresholds(): array
    {
        $config = (array) config('portal.thesis.default_thresholds', []);
        $minSks = is_array($config['min_sks'] ?? null) ? $config['min_sks'] : [];

        return [
            'min_sks' => [
                'D3' => (int) ($minSks['D3'] ?? 90),
                'S1' => (int) ($minSks['S1'] ?? 120),
            ],
            'min_ipk' => (float) ($config['min_ipk'] ?? 2.0),
            'max_d' => (int) ($config['max_d'] ?? 2),
            'allow_e' => (bool) ($config['allow_e'] ?? false),
        ];
    }

    private function matchInt(string $pattern, string $text): ?int
    {
        return preg_match($pattern, $text, $matches) === 1 ? (int) $matches[1] : null;
    }

    /**
     * SOP and guide PDFs (`a[href$=.pdf]`, label from the inner `button[title]`);
     * the empty `<a href=''>` the portal writes before them is skipped
     *
     * @return list<array{label: string, path: string, filename: string}>
     */
    private function extractDocuments(Crawler $content): array
    {
        $documents = [];

        $content->filter('a[href]')->each(function (Crawler $link) use (&$documents): void {
            $href = trim((string) $link->attr('href'));

            if ($href === '' || preg_match('/\.pdf$/i', $href) !== 1) {
                return;
            }

            $path = $this->campusFilePath($href);

            if ($path === null || isset($documents[$path])) {
                return;
            }

            $button = $link->filter('button[title]');
            $label = $button->count() > 0 ? $this->normalizePortalText((string) $button->first()->attr('title')) : '';
            $label = $label !== '' ? $label : $this->cellText($link);
            $filename = basename($path);

            $documents[$path] = [
                'label' => $label !== '' ? $label : $this->labelFromFilename($filename),
                'path' => $path,
                'filename' => $filename,
            ];
        });

        return array_values($documents);
    }

    private function labelFromFilename(string $filename): string
    {
        return trim((string) preg_replace('/[_\s]+/', ' ', pathinfo($filename, PATHINFO_FILENAME)));
    }

    /**
     * Cards and tables of an active thesis page, captured without guessing
     * their meaning: titles, "label | value" rows (identity rows left out),
     * file links and the names of form fields, never their values
     *
     * @return list<array{title: string|null, fields: list<array{label: string, value: string}>, links: list<array{label: string, path: string|null}>, form_fields: list<string>}>
     */
    private function rawSections(Crawler $content): array
    {
        $sections = [];

        $content->filter('.card, table')->each(function (Crawler $node) use (&$sections): void {
            $element = $node->getNode(0);

            // A table inside a card belongs to the card's section.
            if ($element instanceof \DOMElement && strtolower($element->nodeName) === 'table' && $node->closest('.card') !== null) {
                return;
            }

            $titleNode = $node->filter('.header .title, h3, h4, h5');
            $title = $titleNode->count() > 0 ? ($this->cellText($titleNode->first()) ?: null) : null;
            $fields = [];

            foreach ($node->filter('tr') as $rowNode) {
                $cells = array_values(array_filter(
                    $this->rowCells(new Crawler($rowNode)),
                    fn (Crawler $cell): bool => $this->cellText($cell) !== ':'
                ));

                if (count($cells) !== 2) {
                    continue;
                }

                $label = $this->cellText($cells[0]);
                $key = mb_strtolower((string) preg_replace('/[^\p{L}\p{N}]+/u', '', $label));
                $value = $this->cellText($cells[1]);

                if ($label !== '' && $value !== '' && ! in_array($key, self::IDENTITY_KEYS, true)) {
                    $fields[] = ['label' => $label, 'value' => $value];
                }
            }

            $links = $node->filter('a[href]')->each(fn (Crawler $link): array => [
                'label' => $this->cellText($link) ?: $this->normalizePortalText((string) ($link->filter('[title]')->count() > 0 ? $link->filter('[title]')->first()->attr('title') : '')),
                'path' => $this->campusFilePath(trim((string) $link->attr('href'))),
            ]);
            $links = array_values(array_filter($links, static fn (array $link): bool => $link['label'] !== '' || $link['path'] !== null));
            $formFields = array_values(array_unique(array_filter(
                $node->filter('input[name], select[name], textarea[name]')->each(static fn (Crawler $field): string => (string) $field->attr('name')),
                static fn (string $name): bool => $name !== ''
            )));

            if ($fields !== [] || $links !== [] || $formFields !== []) {
                $sections[] = ['title' => $title, 'fields' => $fields, 'links' => $links, 'form_fields' => $formFields];
            }
        });

        return $sections;
    }
}
