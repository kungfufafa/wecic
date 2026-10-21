<?php

declare(strict_types=1);

namespace App\Services\Extractors;

use App\Exceptions\PortalScrapingException;
use App\Services\PortalEndpointRegistry;
use Symfony\Component\DomCrawler\Crawler;

class KrsDetailExtractor extends KrsPageExtractor
{
    /**
     * One semester's KRS from portal/mahasiswa/?mod=student&act=viewkrs&id=<krs_id> (F14)
     *
     * The same page also lists the KHS of every semester; that part belongs
     * to the grades parser, which re-reads the memoized page.
     *
     * @return array{
     *     found: bool,
     *     krs_id: int,
     *     header: array{academic_year: string|null, semester: int|string|null, semester_is_regular: bool, semester_label: string|null, date: string|null, nim: string|null, name: string|null},
     *     counts_as_study_load: bool,
     *     status: array{code: 'pending_dpa'|'validated'|'not_submitted'|'unknown', label: string, raw_text: string|null},
     *     courses: list<array{code: string, name: string|null, sks: int|null, semester: int|null, status: string|null, is_retake: bool}>,
     *     totals: array{courses: int, sks: int, source: 'portal'|'computed', consistent: bool},
     *     print_available: bool,
     *     krs_id_confirmed: bool|null,
     *     extracted_at: string,
     *     _sync?: array<string, mixed>
     * }
     */
    public function extractKrsDetailData(string $sessionToken, int $krsId): array
    {
        try {
            $this->setSession($sessionToken);
            $this->logScraping('Extracting KRS detail', ['session_token' => $sessionToken, 'krs_id' => $krsId]);

            return $this->rememberResource('krs_detail', ['id' => $krsId], function () use ($krsId): array {
                $url = PortalEndpointRegistry::url('student_krs_detail', ['id' => $krsId]);
                $html = $this->get($url);
                $this->ensureNotLoginPage($html, $url);

                $detail = $this->parseKrsDetailPage($this->createCrawler($html), $krsId);

                $this->logScraping('KRS detail extracted successfully', [
                    'krs_id' => $krsId,
                    'found' => $detail['found'],
                    'course_count' => count($detail['courses']),
                    'krs_id_confirmed' => $detail['krs_id_confirmed'],
                ]);

                return $detail;
            }, static fn (array $detail): bool => ($detail['found'] ?? false) === true);
        } catch (PortalScrapingException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logScrapingError('Extracting KRS detail', $e, ['session_token' => $sessionToken, 'krs_id' => $krsId]);
            throw PortalScrapingException::dataExtractionFailed('KRS detail', $e->getMessage());
        }
    }

    /**
     * KRS part of an act=viewkrs page: every table before the first KHS
     * grade table (header, planned courses, totals). Pure, no request.
     *
     * @return array{
     *     found: bool,
     *     krs_id: int,
     *     header: array{academic_year: string|null, semester: int|string|null, semester_is_regular: bool, semester_label: string|null, date: string|null, nim: string|null, name: string|null},
     *     counts_as_study_load: bool,
     *     status: array{code: 'pending_dpa'|'validated'|'not_submitted'|'unknown', label: string, raw_text: string|null},
     *     courses: list<array{code: string, name: string|null, sks: int|null, semester: int|null, status: string|null, is_retake: bool}>,
     *     totals: array{courses: int, sks: int, source: 'portal'|'computed', consistent: bool},
     *     print_available: bool,
     *     krs_id_confirmed: bool|null,
     *     extracted_at: string
     * }
     */
    public function parseKrsDetailPage(Crawler $crawler, int $krsId): array
    {
        $tables = $this->krsTables($this->mainContent($crawler));
        $rowsByKey = [];
        $courseTable = null;
        $printAvailable = false;

        foreach ($tables as $table) {
            foreach ($this->labelledRows($table) as $row) {
                $rowsByKey[$row['key']] ??= $row['cell'];
            }

            $courseTable ??= $this->parseCourseTable($table);
            $printAvailable = $printAvailable || $table->filter('a[href*="cetakkrs/print.php"]')->count() > 0;
        }

        $value = fn (string $key): ?string => isset($rowsByKey[$key])
            ? $this->nullablePortalValue($this->cellText($rowsByKey[$key]))
            : null;

        $semester = $this->semesterValue((string) $value('krssemester'));
        $courses = array_map(fn (array $course): array => [
            ...$course,
            'is_retake' => $course['status'] !== null && preg_match('/^ulang/iu', $course['status']) === 1,
        ], $courseTable['courses'] ?? []);
        $creditIds = array_values(array_unique($courseTable['credit_ids'] ?? []));
        $hasHeader = $value('tahunakademik') !== null || $value('krssemester') !== null;

        return [
            'found' => $hasHeader || $courses !== [],
            'krs_id' => $krsId,
            'header' => [
                'academic_year' => $this->academicYear((string) $value('tahunakademik')),
                ...$semester,
                'date' => $this->isoDate((string) $value('tanggalkrs')),
                'nim' => $value('nim'),
                'name' => $value('nama'),
            ],
            'counts_as_study_load' => $semester['semester_is_regular'],
            'status' => $this->classifyKrsStatus($value('statuskrs')),
            'courses' => $courses,
            'totals' => $this->courseTotals(
                $courses,
                $this->firstInteger((string) $value('totalmatakuliah')),
                $this->firstInteger((string) $value('totalsks'))
            ),
            'print_available' => $printAvailable,
            'krs_id_confirmed' => $creditIds === [] ? null : $creditIds === [$krsId],
            'extracted_at' => now()->toISOString(),
        ];
    }

    /**
     * Tables of the KRS card, in document order, up to the first KHS table
     *
     * @return list<Crawler>
     */
    private function krsTables(Crawler $content): array
    {
        $tables = [];

        foreach ($content->filter('table') as $tableNode) {
            $table = new Crawler($tableNode);

            if ($this->isGradeTable($table)) {
                break;
            }

            $tables[] = $table;
        }

        return $tables;
    }
}
