<?php

declare(strict_types=1);

namespace App\Services\Extractors;

use App\Exceptions\PortalScrapingException;
use App\Services\PortalEndpointRegistry;
use Symfony\Component\DomCrawler\Crawler;

class AdvisingExtractor extends KrsPageExtractor
{
    /**
     * Academic advisor (DPA) and KRS history from Perwalian
     * (portal/mahasiswa/?mod=student&act=perwalian, F13)
     *
     * `krs_history` keeps the portal's order (T.A/semester descending, with a
     * "T" row possibly last); `latest_krs_id` is the row with the highest
     * numeric semester, never the latest date.
     *
     * @return array{
     *     found: bool,
     *     student: array{photo_path: string|null, program: string|null, cohort: string|null, nim: string|null, name: string|null, status: string|null},
     *     dpa: array{name: string|null, nidn: string|null, phone: string|null, whatsapp: string|null, email: string|null}|null,
     *     krs_history: list<array{krs_id: int, academic_year: string|null, date: string|null, semester: int|string|null, semester_is_regular: bool, semester_label: string|null}>,
     *     latest_krs_id: int|null,
     *     latest_semester_number: int|null,
     *     krs_add_action_detected: bool,
     *     extracted_at: string,
     *     _sync?: array<string, mixed>
     * }
     */
    public function extractAdvisingData(string $sessionToken): array
    {
        try {
            $this->setSession($sessionToken);
            $this->logScraping('Extracting advising data', ['session_token' => $sessionToken]);

            return $this->rememberResource('advising', [], function (): array {
                $url = PortalEndpointRegistry::url('student_advising');
                $html = $this->get($url);
                $this->ensureNotLoginPage($html, $url);

                $advising = $this->parseAdvisingPage($this->createCrawler($html));

                $this->logScraping('Advising data extracted successfully', [
                    'found' => $advising['found'],
                    'history_count' => count($advising['krs_history']),
                    'has_dpa' => $advising['dpa'] !== null,
                ]);

                return $advising;
            }, static fn (array $advising): bool => ($advising['found'] ?? false) === true);
        } catch (PortalScrapingException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logScrapingError('Extracting advising data', $e, ['session_token' => $sessionToken]);
            throw PortalScrapingException::dataExtractionFailed('advising', $e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function parseAdvisingPage(Crawler $crawler): array
    {
        $content = $this->mainContent($crawler);
        $rows = $this->labelledRows($content);
        $rowsByKey = [];

        foreach ($rows as $row) {
            $rowsByKey[$row['key']] ??= $row['cell'];
        }

        $dpa = $this->dpaFromRows($rows);
        $history = $this->extractHistory($content);
        $latest = $this->latestRegularKrs($history);

        return [
            'found' => $dpa !== null || $history !== null,
            'student' => $this->studentBlock($content, $rowsByKey),
            'dpa' => $dpa,
            'krs_history' => $history ?? [],
            'latest_krs_id' => $latest['krs_id'] ?? null,
            'latest_semester_number' => is_int($latest['semester'] ?? null) ? $latest['semester'] : null,
            'krs_add_action_detected' => $content->filter('.linkadd a, .linkadd button, .linkadd form, .linkadd input')->count() > 0,
            'extracted_at' => now()->toISOString(),
        ];
    }

    /**
     * Only what the profile page does not already give: photo, program,
     * cohort, NIM/name and student status (TTL is deliberately skipped)
     *
     * @param  array<string, Crawler>  $rowsByKey
     * @return array{photo_path: string|null, program: string|null, cohort: string|null, nim: string|null, name: string|null, status: string|null}
     */
    private function studentBlock(Crawler $content, array $rowsByKey): array
    {
        $value = fn (string $key): ?string => isset($rowsByKey[$key])
            ? $this->nullablePortalValue($this->cellText($rowsByKey[$key]))
            : null;

        [$nim, $name] = $this->nimAndName($rowsByKey['nimnama'] ?? null);
        $photo = $content->filter('img[src*="fotostudent"]');

        return [
            'photo_path' => $photo->count() === 0 ? null : $this->studentPhotoPath($photo->first()->attr('src')),
            'program' => $value('programstudi') ?? $value('prodi'),
            'cohort' => $value('angkatan'),
            'nim' => $nim ?? $value('nim'),
            'name' => $name ?? $value('nama'),
            'status' => $value('statusmahasiswa') ?? $value('status'),
        ];
    }

    /**
     * "<b>NIM</b> / <b>Nama</b>"
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function nimAndName(?Crawler $cell): array
    {
        if ($cell === null) {
            return [null, null];
        }

        $bold = $cell->filter('b');

        if ($bold->count() >= 2) {
            return [
                $this->nullablePortalValue($this->cellText($bold->eq(0))),
                $this->nullablePortalValue($this->cellText($bold->eq(1))),
            ];
        }

        $parts = array_map('trim', explode('/', $this->cellText($cell), 2));

        return [$this->nullablePortalValue($parts[0]), $this->nullablePortalValue($parts[1] ?? '')];
    }

    /**
     * Rows of the "Histori KRS" table (header: T.A | Tanggal | Semester | Aksi),
     * or null when the page has no such table
     *
     * The raw page writes the header as `<tr><b><td>..</td></b></tr>` with no
     * tbody; tableRows()/rowCells() read it the same as a browser would.
     *
     * @return list<array{krs_id: int, academic_year: string|null, date: string|null, semester: int|string|null, semester_is_regular: bool, semester_label: string|null}>|null
     */
    private function extractHistory(Crawler $content): ?array
    {
        foreach ($content->filter('table') as $tableNode) {
            $history = $this->parseHistoryTable(new Crawler($tableNode));

            if ($history !== null) {
                return $history;
            }
        }

        return null;
    }

    /**
     * @return list<array{krs_id: int, academic_year: string|null, date: string|null, semester: int|string|null, semester_is_regular: bool, semester_label: string|null}>|null
     */
    private function parseHistoryTable(Crawler $table): ?array
    {
        $columns = null;
        $history = [];

        foreach ($this->tableRows($table) as $row) {
            $cells = $this->rowCells($row);

            if ($columns === null) {
                $columns = $this->historyColumns($cells);

                continue;
            }

            $entry = $this->historyRow($row, $cells, $columns);

            if ($entry !== null) {
                $history[] = $entry;
            }
        }

        return $columns === null ? null : $history;
    }

    /**
     * @param  list<Crawler>  $cells
     * @return array<string, int>|null
     */
    private function historyColumns(array $cells): ?array
    {
        $columns = [];

        foreach ($cells as $index => $cell) {
            $field = match ($this->labelKey($this->cellText($cell))) {
                'ta', 'tahunakademik', 'thakademik' => 'academic_year',
                'tanggal', 'tanggalkrs', 'tgl' => 'date',
                'semester', 'smt' => 'semester',
                'aksi' => 'action',
                default => null,
            };

            if ($field !== null) {
                $columns[$field] ??= $index;
            }
        }

        return isset($columns['academic_year'], $columns['semester']) ? $columns : null;
    }

    /**
     * @param  list<Crawler>  $cells
     * @param  array<string, int>  $columns
     * @return array{krs_id: int, academic_year: string|null, date: string|null, semester: int|string|null, semester_is_regular: bool, semester_label: string|null}|null
     */
    private function historyRow(Crawler $row, array $cells, array $columns): ?array
    {
        $krsId = $this->krsIdFromRow($row);

        if ($krsId === null) {
            return null;
        }

        $value = fn (string $field): string => isset($columns[$field], $cells[$columns[$field]])
            ? $this->cellText($cells[$columns[$field]])
            : '';

        return [
            'krs_id' => $krsId,
            'academic_year' => $this->academicYear($value('academic_year')),
            'date' => $this->isoDate($value('date')),
            ...$this->semesterValue($value('semester')),
        ];
    }

    private function krsIdFromRow(Crawler $row): ?int
    {
        foreach ($row->filter('a[href*="viewkrs"]') as $link) {
            $href = html_entity_decode((string) $link->getAttribute('href'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            parse_str((string) parse_url($href, PHP_URL_QUERY), $query);
            $id = $query['id'] ?? null;

            if (($query['act'] ?? null) === 'viewkrs' && is_string($id) && ctype_digit($id) && (int) $id > 0) {
                return (int) $id;
            }
        }

        return null;
    }

    /**
     * The KRS with the highest numeric semester (K2 source 5); "T" rows and
     * dates are ignored. Ties keep the portal's first row.
     *
     * @param  list<array{krs_id: int, semester: int|string|null, semester_is_regular: bool}>|null  $history
     * @return array{krs_id: int, semester: int|string|null, semester_is_regular: bool}|null
     */
    private function latestRegularKrs(?array $history): ?array
    {
        $latest = null;

        foreach ($history ?? [] as $entry) {
            if ($entry['semester_is_regular'] && ($latest === null || $entry['semester'] > $latest['semester'])) {
                $latest = $entry;
            }
        }

        return $latest;
    }
}
