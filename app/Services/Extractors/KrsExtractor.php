<?php

declare(strict_types=1);

namespace App\Services\Extractors;

use App\Exceptions\PortalScrapingException;
use App\Services\PortalEndpointRegistry;
use Carbon\CarbonImmutable;
use Symfony\Component\DomCrawler\Crawler;

class KrsExtractor extends KrsPageExtractor
{
    /**
     * Forms that live on every portal page and never mean the KRS can be filled:
     * the SiPANDAI single sign-on and the VMTS questionnaire popup.
     *
     * @var list<string>
     */
    private const IGNORED_FORM_ACTIONS = ['loginact.php', 'kuesionerform'];

    /**
     * @var array<string, string>
     */
    private const PERIOD_LABELS = [
        'her_registrasi' => 'Her-registrasi & pengisian KRS',
        'perwalian_perbaikan' => 'Perwalian & perbaikan KRS',
        'krs' => 'Pengisian KRS',
    ];

    /**
     * Current KRS from Reg & KRS Online (portal/mahasiswa/?mod=student&act=credit, F09)
     *
     * @return array{
     *     found: bool,
     *     academic_period: array{label: string, academic_year: string, term: 'ganjil'|'genap'|null, source: 'credit'}|null,
     *     student: array{faculty: string|null, program: string|null, academic_year: string|null, nim: string|null, name: string|null},
     *     period: array{start: string, end: string}|null,
     *     periods: list<array{kind: 'her_registrasi'|'perwalian_perbaikan'|'krs', start: string, end: string, label: string, source: 'credit', source_ref: 'table_row'|'title'|'notice'}>,
     *     notice: array{text: string, dates: list<string>, is_future: bool, kind: string}|null,
     *     notice_text: string|null,
     *     status: array{code: 'pending_dpa'|'validated'|'not_submitted'|'unknown', label: string, raw_text: string|null},
     *     dpa: array{name: string|null, nidn: string|null, phone: string|null, whatsapp: string|null, email: string|null}|null,
     *     courses: list<array{code: string, name: string|null, sks: int|null, semester: int|null}>,
     *     totals: array{courses: int, sks: int, source: 'portal'|'computed', consistent: bool},
     *     print_available: bool,
     *     registration_form_detected: bool,
     *     registration_form: array{forms: list<array{action: string|null, method: string, field_names: list<string>, option_count: int}>, control_count: int}|null,
     *     extracted_at: string,
     *     _sync?: array<string, mixed>
     * }
     */
    public function extractKrsData(string $sessionToken): array
    {
        try {
            $this->setSession($sessionToken);
            $this->logScraping('Extracting current KRS', ['session_token' => $sessionToken]);

            return $this->rememberResource('krs', [], function (): array {
                $url = PortalEndpointRegistry::url('student_credit');
                $html = $this->get($url);
                $this->ensureNotLoginPage($html, $url);

                $krs = $this->parseCreditPage($this->createCrawler($html));

                $this->logScraping('Current KRS extracted successfully', [
                    'found' => $krs['found'],
                    'status' => $krs['status']['code'],
                    'course_count' => count($krs['courses']),
                    'period_count' => count($krs['periods']),
                    'registration_form_detected' => $krs['registration_form_detected'],
                ]);

                return $krs;
            }, static fn (array $krs): bool => ($krs['found'] ?? false) === true);
        } catch (PortalScrapingException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logScrapingError('Extracting current KRS', $e, ['session_token' => $sessionToken]);
            throw PortalScrapingException::dataExtractionFailed('KRS', $e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function parseCreditPage(Crawler $crawler): array
    {
        $content = $this->mainContent($crawler);
        $rows = $this->labelledRows($content);
        $rowsByKey = [];

        foreach ($rows as $row) {
            $rowsByKey[$row['key']] ??= $row['cell'];
        }

        $title = $this->creditTitle($content);
        $dpa = $this->dpaFromRows($rows);
        $statusCell = $rowsByKey['statuskrsonline'] ?? $rowsByKey['statuskrs'] ?? null;
        $courseTable = $this->plannedCourseTable($content);
        $courses = array_map(static fn (array $course): array => [
            'code' => $course['code'],
            'name' => $course['name'],
            'sks' => $course['sks'],
            'semester' => $course['semester'],
        ], $courseTable['courses'] ?? []);
        $footer = $courseTable['footer'] ?? [];
        $periods = $this->extractPeriods($content, $rows);
        $registrationForm = $this->detectRegistrationForm($content);

        return [
            'found' => $title !== null || $statusCell !== null || $courseTable !== null,
            'academic_period' => $this->academicPeriod($title, $rowsByKey),
            'student' => [
                'faculty' => $this->rowValue($rowsByKey, ['fakultas']),
                'program' => $this->rowValue($rowsByKey, ['programstudi', 'prodi']),
                'academic_year' => $this->academicYear((string) $this->rowValue($rowsByKey, ['thakademik', 'tahunakademik'])),
                'nim' => $this->rowValue($rowsByKey, ['nim']),
                'name' => $this->rowValue($rowsByKey, ['nama', 'namamahasiswa']),
            ],
            'period' => $this->primaryPeriod($periods),
            'periods' => $periods,
            'notice' => $this->extractPortalNotice($content, 'krs_period'),
            'notice_text' => $this->noticeText($content),
            'status' => $this->classifyKrsStatus($statusCell === null ? null : $this->statusText($statusCell, $dpa['name'] ?? null)),
            'dpa' => $dpa,
            'courses' => $courses,
            'totals' => $this->courseTotals(
                $courses,
                $this->footerNumber($footer, 'totalmatakuliah'),
                $this->footerNumber($footer, 'totalsks')
            ),
            'print_available' => $content->filter('a[href*="cetakkrs/print.php"]')->count() > 0,
            'registration_form_detected' => $registrationForm !== null,
            'registration_form' => $registrationForm,
            'extracted_at' => now()->toISOString(),
        ];
    }

    private function creditTitle(Crawler $content): ?string
    {
        foreach ($content->filter('h4') as $heading) {
            $text = $this->normalizePortalText($heading->textContent);

            if (preg_match('/kartu\s+rencana\s+studi|krs\s+online/iu', $text) === 1 && preg_match('/^periode\b/iu', $text) !== 1) {
                return $text;
            }
        }

        return null;
    }

    /**
     * T.A and term from the page title, else the "Th Akademik" row (K1 fallback)
     *
     * @param  array<string, Crawler>  $rowsByKey
     * @return array{label: string, academic_year: string, term: 'ganjil'|'genap'|null, source: 'credit'}|null
     */
    private function academicPeriod(?string $title, array $rowsByKey): ?array
    {
        $period = $title !== null ? $this->parseAcademicPeriod($title) : null;

        if ($period === null) {
            $academicYear = $this->academicYear((string) $this->rowValue($rowsByKey, ['thakademik', 'tahunakademik']));
            $period = $academicYear === null ? null : $this->parseAcademicPeriod('T.A '.$academicYear.' '.$title);
        }

        return $period === null ? null : [...$period, 'source' => 'credit'];
    }

    /**
     * @param  array<string, Crawler>  $rowsByKey
     * @param  list<string>  $keys
     */
    private function rowValue(array $rowsByKey, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($rowsByKey[$key])) {
                return $this->nullablePortalValue($this->cellText($rowsByKey[$key]));
            }
        }

        return null;
    }

    /**
     * Status text from the first `b > i > font` only: the second one names the DPA
     */
    private function statusText(Crawler $cell, ?string $dpaName): ?string
    {
        foreach (['span.blink b > i > font', 'b > i > font'] as $selector) {
            $font = $cell->filter($selector);

            if ($font->count() > 0) {
                $text = $this->normalizePortalText($font->first()->text(''));

                if ($text !== '') {
                    return $text;
                }
            }
        }

        $blink = $cell->filter('span.blink');
        $text = $this->normalizePortalText(($blink->count() > 0 ? $blink->first() : $cell)->text(''));

        foreach ($cell->filter('a, button') as $control) {
            $text = str_replace($this->normalizePortalText($control->textContent), '', $text);
        }

        $text = (string) preg_replace('/^\s*status\s+krs(?:\s+online)?\s*:?\s*/iu', '', explode('=>', $text)[0]);

        if ($dpaName !== null) {
            $text = str_ireplace($dpaName, '', $text);
        }

        $text = $this->normalizePortalText($text);

        return $text === '' ? null : $text;
    }

    /**
     * The "DAFTAR MATA KULIAH YANG DIRENCANAKAN" table: the first course
     * table that is not a KHS grade table
     *
     * @return array{courses: list<array{code: string, name: string|null, sks: int|null, semester: int|null, status: string|null}>, footer: array<string, string>, credit_ids: list<int>}|null
     */
    private function plannedCourseTable(Crawler $content): ?array
    {
        foreach ($content->filter('table') as $tableNode) {
            $table = new Crawler($tableNode);

            if ($this->isGradeTable($table)) {
                continue;
            }

            $parsed = $this->parseCourseTable($table);

            if ($parsed !== null) {
                return $parsed;
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $footer
     */
    private function footerNumber(array $footer, string $prefix): ?int
    {
        foreach ($footer as $key => $value) {
            if (str_starts_with($key, $prefix)) {
                return $this->firstInteger($value);
            }
        }

        return null;
    }

    private function noticeText(Crawler $content): ?string
    {
        $notice = $content->filter('td[colspan="3"] > center');

        return $notice->count() === 0 ? null : $this->nullablePortalValue($notice->first()->text(''));
    }

    /**
     * Every period written on the page: the "Periode ..." row, the period
     * heading and the marquee (K3, K5), deduplicated by kind and dates
     *
     * @param  list<array{key: string, label: string, cell: Crawler}>  $rows
     * @return list<array{kind: 'her_registrasi'|'perwalian_perbaikan'|'krs', start: string, end: string, label: string, source: 'credit', source_ref: 'table_row'|'title'|'notice'}>
     */
    private function extractPeriods(Crawler $content, array $rows): array
    {
        $sources = [];

        foreach ($rows as $row) {
            if (str_starts_with($row['key'], 'periode')) {
                $sources[] = ['table_row', $row['label'], $this->cellText($row['cell'])];
            }
        }

        foreach ($content->filter('h4') as $heading) {
            $text = $this->normalizePortalText($heading->textContent);

            if (preg_match('/^periode\b/iu', $text) === 1) {
                $sources[] = ['title', '', $text];
            }
        }

        foreach ($content->filter('marquee') as $marquee) {
            $text = $this->normalizePortalText($marquee->textContent);

            if ($text !== '') {
                $sources[] = ['notice', '', $text];
            }
        }

        $periods = [];

        foreach ($sources as [$sourceRef, $label, $text]) {
            foreach ($this->rangesWithContext($text) as ['range' => $range, 'context' => $context]) {
                $kind = $this->periodKind($label.' '.$context);
                $start = $range['start']->toDateString();
                $end = $range['end']->toDateString();

                $periods[$kind.'|'.$start.'|'.$end] ??= [
                    'kind' => $kind,
                    'start' => $start,
                    'end' => $end,
                    'label' => self::PERIOD_LABELS[$kind],
                    'source' => 'credit',
                    'source_ref' => $sourceRef,
                ];
            }
        }

        return array_values($periods);
    }

    /**
     * Each date range with the words written before it (after the previous
     * range), so "Her-registrasi" mentioned after a perwalian range does not
     * relabel it
     *
     * @return list<array{range: array{start: CarbonImmutable, end: CarbonImmutable}, context: string}>
     */
    private function rangesWithContext(string $text): array
    {
        $text = $this->normalizePortalText($text);
        $ranges = $this->extractIndonesianDateRanges($text);
        $length = mb_strlen($text);
        $previousEnd = 0;
        $result = [];

        foreach ($ranges as $index => $range) {
            $low = $previousEnd + 1;
            $high = $length;

            while ($low < $high) {
                $middle = intdiv($low + $high, 2);

                if (count($this->extractIndonesianDateRanges(mb_substr($text, 0, $middle))) > $index) {
                    $high = $middle;
                } else {
                    $low = $middle + 1;
                }
            }

            $result[] = ['range' => $range, 'context' => mb_substr($text, $previousEnd, $low - $previousEnd)];
            $previousEnd = $low;
        }

        return $result;
    }

    /**
     * @return 'her_registrasi'|'perwalian_perbaikan'|'krs'
     */
    private function periodKind(string $context): string
    {
        return match (true) {
            preg_match('/her.?regis/iu', $context) === 1 && preg_match('/perbaikan/iu', $context) !== 1 => 'her_registrasi',
            preg_match('/perbaikan|perwalian/iu', $context) === 1 => 'perwalian_perbaikan',
            default => 'krs',
        };
    }

    /**
     * Legacy `period` alias: the perwalian & perbaikan window (F09)
     *
     * @param  list<array{kind: string, start: string, end: string}>  $periods
     * @return array{start: string, end: string}|null
     */
    private function primaryPeriod(array $periods): ?array
    {
        $candidates = array_values(array_filter($periods, static fn (array $period): bool => $period['kind'] === 'perwalian_perbaikan'));
        $period = $candidates[0] ?? $periods[0] ?? null;

        return $period === null ? null : ['start' => $period['start'], 'end' => $period['end']];
    }

    /**
     * F11 stage 1 detector: a form, choice or submit control inside the page
     * content, ignoring the SSO and questionnaire forms; values are never kept
     *
     * @return array{forms: list<array{action: string|null, method: string, field_names: list<string>, option_count: int}>, control_count: int}|null
     */
    private function detectRegistrationForm(Crawler $content): ?array
    {
        if ($content->count() === 0) {
            return null;
        }

        $forms = [];

        $content->filter('form')->each(function (Crawler $form) use (&$forms): void {
            if (! $this->isIgnoredForm($form)) {
                $forms[] = $this->describeForm($form);
            }
        });

        $controlCount = 0;

        $content->filter('select, textarea, input, button')->each(function (Crawler $control) use (&$controlCount): void {
            $form = self::formOf($control);

            if ($this->isChoiceOrSubmitControl($control) && ($form === null || ! $this->isIgnoredForm($form))) {
                $controlCount++;
            }
        });

        return $forms === [] && $controlCount === 0
            ? null
            : ['forms' => $forms, 'control_count' => $controlCount];
    }

    private function isIgnoredForm(Crawler $form): bool
    {
        $action = strtolower((string) $form->attr('action'));

        foreach (self::IGNORED_FORM_ACTIONS as $ignored) {
            if (str_contains($action, $ignored)) {
                return true;
            }
        }

        return false;
    }

    private function isChoiceOrSubmitControl(Crawler $control): bool
    {
        $type = strtolower(trim((string) $control->attr('type')));

        return match ($control->nodeName()) {
            'select', 'textarea' => true,
            'input' => in_array($type, ['checkbox', 'radio', 'submit', 'image'], true),
            'button' => $type === 'submit',
            default => false,
        };
    }

    /**
     * @return array{action: string|null, method: string, field_names: list<string>, option_count: int}
     */
    private function describeForm(Crawler $form): array
    {
        $fieldNames = [];

        $controls = self::formScope($form);

        $controls->filter('input[name], select[name], textarea[name], button[name]')->each(function (Crawler $field) use (&$fieldNames): void {
            $name = trim((string) $field->attr('name'));

            if ($name !== '' && ! in_array($name, $fieldNames, true)) {
                $fieldNames[] = $name;
            }
        });

        $choices = $controls->filter('input')->reduce(
            static fn (Crawler $input): bool => in_array(strtolower((string) $input->attr('type')), ['checkbox', 'radio'], true)
        );

        return [
            'action' => $this->redactedFormAction((string) $form->attr('action')),
            'method' => strtoupper(trim((string) $form->attr('method'))) ?: 'GET',
            'field_names' => $fieldNames,
            'option_count' => $controls->filter('option')->count() + $choices->count(),
        ];
    }

    /**
     * Form action with only its path and the `mod`/`act` query values
     */
    private function redactedFormAction(string $action): ?string
    {
        $action = trim(html_entity_decode($action, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($action === '') {
            return null;
        }

        parse_str((string) parse_url($action, PHP_URL_QUERY), $query);
        $kept = array_intersect_key($query, array_flip(['mod', 'act']));
        $path = (string) parse_url($action, PHP_URL_PATH);

        return $path.($kept === [] ? '' : '?'.http_build_query($kept));
    }
}
