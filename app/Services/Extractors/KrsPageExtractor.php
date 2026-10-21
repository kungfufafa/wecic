<?php

declare(strict_types=1);

namespace App\Services\Extractors;

use App\Services\PortalScrapingService;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Parsing shared by the Portal Mahasiswa KRS pages: act=credit, act=perwalian
 * and act=viewkrs (label rows, KRS status, DPA contact and course tables)
 */
abstract class KrsPageExtractor extends PortalScrapingService
{
    /**
     * @var array<string, string>
     */
    protected const STATUS_LABELS = [
        'pending_dpa' => 'Menunggu validasi DPA',
        'validated' => 'Sudah divalidasi DPA',
        'not_submitted' => 'KRS belum diajukan',
        'unknown' => 'Status KRS tidak dikenali',
    ];

    protected const IRREGULAR_SEMESTER_NOTE = 'non-reguler';

    /**
     * Semester `T` lists the courses converted from an earlier study
     * (a D3 → S1 continuation); see K16
     */
    protected const CONVERSION_SEMESTER_NOTE = 'konversi';

    /**
     * Rows written as "label | : | value", in document order
     *
     * The key is the label without spaces or punctuation, lowercased, so
     * "N I M" and "NIM" both become "nim". Cells come from rowCells(), so a
     * row whose cells sit inside stray b/font tags (raw server HTML) still
     * counts, and a nested table never adds cells to the row around it.
     *
     * @return list<array{key: string, label: string, cell: Crawler}>
     */
    protected function labelledRows(Crawler $scope): array
    {
        if ($scope->count() === 0) {
            return [];
        }

        $rows = [];

        foreach ($scope->filter('tr') as $rowNode) {
            $cells = $this->rowCells(new Crawler($rowNode));

            if (count($cells) !== 3 || $this->cellText($cells[1]) !== ':') {
                continue;
            }

            $label = $this->cellText($cells[0]);
            $key = $this->labelKey($label);

            if ($key !== '') {
                $rows[] = ['key' => $key, 'label' => $label, 'cell' => $cells[2]];
            }
        }

        return $rows;
    }

    protected function labelKey(string $label): string
    {
        return mb_strtolower((string) preg_replace('/[^\p{L}\p{N}]+/u', '', $this->normalizePortalText($label)));
    }

    /**
     * Null for empty cells and the portal's "-" placeholder
     */
    protected function nullablePortalValue(string $value): ?string
    {
        $value = $this->normalizePortalText($value);

        return preg_match('/^[\s\-–—.]*$/u', $value) === 1 ? null : $value;
    }

    protected function firstInteger(string $text): ?int
    {
        return preg_match('/\d+/', $text, $matches) === 1 ? (int) $matches[0] : null;
    }

    protected function academicYear(string $text): ?string
    {
        return preg_match('/(\d{4})\s*\/\s*(\d{4})/', $text, $matches) === 1
            ? $matches[1].'/'.$matches[2]
            : null;
    }

    protected function isoDate(string $text): ?string
    {
        return $this->parseIndonesianDate($text)?->toDateString();
    }

    /**
     * KRS status from the portal's free text (F09)
     *
     * "Belum (di)validasi" and "Segera menghubungi Dosen DPA" wait for the DPA;
     * any other "belum" means the KRS was not submitted. "Belum" alone never
     * means pending. A validated wording is not trusted when the validation
     * itself is negated ("tidak tervalidasi") or rejected ("ditolak",
     * "gagal"); a negation of something else, as in act=viewkrs's "Status
     * sudah divalidasi ..., maka saudara tidak bisa merubah Data KRS", is.
     *
     * @return array{code: 'pending_dpa'|'validated'|'not_submitted'|'unknown', label: string, raw_text: string|null}
     */
    protected function classifyKrsStatus(?string $rawText): array
    {
        $text = $this->normalizePortalText((string) $rawText);

        $code = match (true) {
            $text === '' => 'unknown',
            preg_match('/belum\s+(?:di|ter)?validasi|segera\s+menghubungi\s+dosen\s+dpa/iu', $text) === 1 => 'pending_dpa',
            preg_match('/(?:sudah|telah)\s+(?:di)?validasi|tervalidasi/iu', $text) === 1
                && ! $this->negatesValidation($text) => 'validated',
            preg_match('/\bbelum\b/iu', $text) === 1 => 'not_submitted',
            default => 'unknown',
        };

        return [
            'code' => $code,
            'label' => $code === 'unknown' && $text !== '' ? $text : self::STATUS_LABELS[$code],
            'raw_text' => $text === '' ? null : $text,
        ];
    }

    private function negatesValidation(string $text): bool
    {
        return preg_match(
            '/\b(?:tidak|bukan|belum)\s+(?:(?:sudah|telah)\s+)?(?:di|ter)?validasi|\b(?:ditolak|gagal|batal|dibatalkan)\b/iu',
            $text
        ) === 1;
    }

    /**
     * DPA contact from label rows ("Nama Dosen DPA", "No Tlp/WA DPA", "Email DPA")
     *
     * @param  list<array{key: string, label: string, cell: Crawler}>  $rows
     * @return array{name: string|null, nidn: string|null, phone: string|null, whatsapp: string|null, email: string|null}|null
     */
    protected function dpaFromRows(array $rows): ?array
    {
        $dpa = ['name' => null, 'nidn' => null, 'phone' => null, 'whatsapp' => null, 'email' => null];

        foreach ($rows as $row) {
            $field = $this->dpaField($row['key']);

            if ($field === null || $dpa[$field] !== null) {
                continue;
            }

            $dpa[$field] = match ($field) {
                'email' => $this->emailFromCell($row['cell']),
                default => $this->nullablePortalValue($this->cellText($row['cell'])),
            };
        }

        $dpa['whatsapp'] = $this->whatsappNumber($dpa['phone']);

        return array_filter($dpa, static fn (?string $value): bool => $value !== null) === [] ? null : $dpa;
    }

    private function dpaField(string $key): ?string
    {
        $isDpa = str_contains($key, 'dpa') || str_contains($key, 'pembimbingakademik');

        return match (true) {
            ! $isDpa => null,
            str_contains($key, 'email') => 'email',
            preg_match('/tlp|telp|telepon|hp|wa|whatsapp|kontak/u', $key) === 1 => 'phone',
            str_contains($key, 'nidn') || str_contains($key, 'nip') => 'nidn',
            str_contains($key, 'nama') || in_array($key, ['dpa', 'dosendpa'], true) => 'name',
            default => null,
        };
    }

    private function emailFromCell(Crawler $cell): ?string
    {
        $email = $this->extractCloudflareEmail($cell);

        if ($email !== null) {
            return $email;
        }

        $text = $this->cellText($cell);

        return filter_var($text, FILTER_VALIDATE_EMAIL) !== false ? $text : null;
    }

    /**
     * Digits for a wa.me link ("0812..." becomes "62812..."), or null
     */
    protected function whatsappNumber(?string $phone): ?string
    {
        if ($phone === null || preg_match('/\+?\d[\d\s.\-]{7,}\d/', $phone, $matches) !== 1) {
            return null;
        }

        $digits = (string) preg_replace('/\D/', '', $matches[0]);
        $digits = str_starts_with($digits, '0') ? '62'.substr($digits, 1) : $digits;

        return preg_match('/^62\d{8,13}$/', $digits) === 1 ? $digits : null;
    }

    /**
     * A student's semester as the portal writes it: a number, or a letter
     * such as "T" for the conversion KRS (K16)
     *
     * @return array{semester: int|string|null, semester_is_regular: bool, semester_label: string|null}
     */
    protected function semesterValue(string $text): array
    {
        $text = $this->normalizePortalText($text);

        if (preg_match('/^(?:semester\.?\s*)?(\d+)$/iu', $text, $matches) === 1 && (int) $matches[1] > 0) {
            return [
                'semester' => (int) $matches[1],
                'semester_is_regular' => true,
                'semester_label' => 'Semester '.(int) $matches[1],
            ];
        }

        if (preg_match('/^(?:semester\.?\s*)?(\p{L}{1,3})$/iu', $text, $matches) === 1) {
            $semester = mb_strtoupper($matches[1]);

            return [
                'semester' => $semester,
                'semester_is_regular' => false,
                'semester_label' => 'Semester '.$semester.' ('.($semester === 'T' ? self::CONVERSION_SEMESTER_NOTE : self::IRREGULAR_SEMESTER_NOTE).')',
            ];
        }

        return ['semester' => null, 'semester_is_regular' => false, 'semester_label' => null];
    }

    /**
     * Whether the table is a KHS grade table (its header, the first row with
     * cells, names BOBOT or MUTU)
     */
    protected function isGradeTable(Crawler $table): bool
    {
        foreach ($this->tableRows($table) as $row) {
            $cells = $this->rowCells($row);

            if ($cells !== []) {
                $labels = array_map(fn (Crawler $cell): string => $this->labelKey($this->cellText($cell)), $cells);

                return array_intersect($labels, ['bobot', 'mutu']) !== [];
            }
        }

        return false;
    }

    /**
     * Planned courses of a KRS table, mapped by header label, plus its
     * "Total ..." footer rows
     *
     * Rows and cells come from tableRows()/rowCells(), so raw server HTML
     * (no tbody, cells wrapped in stray tags) parses like browser-normalized
     * HTML, and rows of a nested table stay out. The code cell of
     * act=viewkrs carries hidden inputs (`txt_id`, `txt_creditid`) before the
     * code; they hold no text, and their krs ids are returned separately so
     * the caller can confirm which KRS was served.
     *
     * @return array{
     *     courses: list<array{code: string, name: string|null, sks: int|null, semester: int|null, status: string|null}>,
     *     footer: array<string, string>,
     *     credit_ids: list<int>
     * }|null
     */
    protected function parseCourseTable(Crawler $table): ?array
    {
        $columns = null;
        $result = ['courses' => [], 'footer' => [], 'credit_ids' => []];

        foreach ($this->tableRows($table) as $row) {
            $cells = $this->rowCells($row);

            if ($cells === []) {
                continue;
            }

            if ($columns === null) {
                $columns = $this->courseColumns($cells);

                continue;
            }

            if ($this->hasSpanningCell($cells)) {
                $label = $this->labelKey($this->cellText($cells[0]));

                if ($label !== '' && count($cells) > 1) {
                    $result['footer'][$label] = $this->cellText($cells[1]);
                }

                continue;
            }

            $course = $this->courseRow($cells, $columns);

            if ($course !== null) {
                $result['courses'][] = $course;
            }

            foreach ($cells as $cell) {
                foreach ($cell->filter('input[name="txt_creditid"]') as $input) {
                    $value = $input instanceof \DOMElement ? trim($input->getAttribute('value')) : '';

                    if (ctype_digit($value)) {
                        $result['credit_ids'][] = (int) $value;
                    }
                }
            }
        }

        return $columns === null ? null : $result;
    }

    /**
     * Column index of each known header label, or null when the row is not a
     * KRS course header
     *
     * @param  list<Crawler>  $cells
     * @return array<string, int>|null
     */
    private function courseColumns(array $cells): ?array
    {
        $columns = [];

        foreach ($cells as $index => $cell) {
            $field = match ($this->labelKey($this->cellText($cell))) {
                'kode', 'kodemk', 'kodematakuliah' => 'code',
                'namamatakuliah', 'matakuliah', 'namamk' => 'name',
                'sks' => 'sks',
                'semester', 'smt' => 'semester',
                'statusmk', 'status', 'keterangan' => 'status',
                default => null,
            };

            if ($field !== null) {
                $columns[$field] ??= $index;
            }
        }

        return isset($columns['code'], $columns['sks']) ? $columns : null;
    }

    /**
     * @param  list<Crawler>  $cells
     */
    private function hasSpanningCell(array $cells): bool
    {
        foreach ($cells as $cell) {
            if ((int) $cell->attr('colspan') > 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<Crawler>  $cells
     * @param  array<string, int>  $columns
     * @return array{code: string, name: string|null, sks: int|null, semester: int|null, status: string|null}|null
     */
    private function courseRow(array $cells, array $columns): ?array
    {
        $value = fn (string $field): string => isset($columns[$field], $cells[$columns[$field]])
            ? $this->cellText($cells[$columns[$field]])
            : '';

        $code = $value('code');

        if ($code === '' || $this->labelKey($code) === 'kode') {
            return null;
        }

        return [
            'code' => $code,
            'name' => $this->nullablePortalValue($value('name')),
            'sks' => $this->firstInteger($value('sks')),
            'semester' => $this->firstInteger($value('semester')),
            'status' => $this->nullablePortalValue($value('status')),
        ];
    }

    /**
     * Totals printed by the portal, falling back to the parsed rows
     *
     * @param  list<array<string, mixed>>  $courses
     * @return array{courses: int, sks: int, source: 'portal'|'computed', consistent: bool}
     */
    protected function courseTotals(array $courses, ?int $portalCourses, ?int $portalSks): array
    {
        $computedCourses = count($courses);
        $computedSks = array_sum(array_map(static fn (array $course): int => (int) ($course['sks'] ?? 0), $courses));

        if ($portalCourses === null && $portalSks === null) {
            return ['courses' => $computedCourses, 'sks' => $computedSks, 'source' => 'computed', 'consistent' => true];
        }

        $totalCourses = $portalCourses ?? $computedCourses;
        $totalSks = $portalSks ?? $computedSks;

        return [
            'courses' => $totalCourses,
            'sks' => $totalSks,
            'source' => 'portal',
            'consistent' => $totalCourses === $computedCourses && $totalSks === $computedSks,
        ];
    }

    /**
     * Relative path ("files/fotostudent/<file>") for /api/support/download,
     * or null for the "nopic" placeholder and anything outside the allowlist
     */
    protected function studentPhotoPath(?string $src): ?string
    {
        $src = trim((string) $src);

        if ($src === '' || preg_match('/no[-_ ]?pic|no[-_ ]?photo|no[-_ ]?foto|no[-_ ]?image|default|blank/i', basename($src)) === 1) {
            return null;
        }

        return $this->campusFilePath($src);
    }
}
