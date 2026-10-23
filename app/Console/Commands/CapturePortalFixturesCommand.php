<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\PortalException;
use App\Exceptions\PortalOfflineException;
use App\Services\PortalEndpointRegistry;
use App\Services\PortalReadOnlyFetcher;
use App\Services\PortalResponseRecorder;
use App\Services\PortalSessionManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Captures real portal pages as test fixtures, read-only: with the campus
 * session of a MyCIC login it reads the meeting pages (act=discuss), their
 * assignment upload pages (act=send), the exam list (act=ujian) and exam
 * detail pages (act=sendujian), sanitizes them like PortalResponseRecorder
 * does (lecturers' names included) and writes them to
 * tests/Fixtures/portal/real/, listing each file
 * and its capture time in that folder's README.md.
 *
 * Only GET requests are sent (an endpoint registered with another method is
 * refused, and PortalReadOnlyFetcher refuses any other request), nothing is
 * submitted, the command does not run in production, and neither the
 * session token nor cookies are ever printed. A session that does not name
 * the student and their NIM captures nothing, since its pages could not be
 * sanitized of them.
 */
class CapturePortalFixturesCommand extends Command
{
    public const README_START = '<!-- captures:start -->';

    public const README_END = '<!-- captures:end -->';

    private const SESSION_UNUSABLE = 'The campus session is not usable (expired, logged out or unknown). Log in to MyCIC again and pass the new session token with --session.';

    private const SESSION_EXPIRED = 'The portal answered with its login page: the campus session has expired. Log in to MyCIC again and pass the new session token with --session.';

    private const README_HEADER = <<<'MARKDOWN'
# Real portal fixtures

Sanitized copies of real CIC portal pages, captured read-only with
`php artisan portal:capture-fixtures` so the heuristics that read the
portal's pages can be tested against real markup instead of hand-written
HTML.

```
php artisan portal:capture-fixtures --session=<campus session token> \
    --meeting=<meeting_id>:<courses_id>[:<pertemuan>] --exam=<exam id>
```

The command only sends GET requests with the given session, never submits
anything and refuses to run in production. Before a page is written, the
student's name, NIM/username, email addresses, phone numbers, cookies and
session ids, CSRF and hidden tokens, uploaded file names, every other
value of the session's `user_info` and the identifying fields of the
student's stored profile (name, NIM, email, phone, home address, place and
date of birth, NISN, school) are replaced by the placeholders of
`PortalResponseRecorder` (`MAHASISWA UJI`, `20990000001`,
`mahasiswa@example.test`, `surel1@example.test`, `081200000001`,
`SANITIZED_COOKIE`, `SANITIZED_TOKEN`, `berkas-unggahan.pdf`,
`DATA_MAHASISWA_<KEY>`); a word of the name standing alone becomes
`Mahasiswa` (first word) or `Uji`. Lecturers' names become `Dosen Uji A`,
`Dosen Uji B`, ... (titles such as `M.Kom.` stay): those the page shows in
its lecturer fields (`Nama Dosen`, `Dosen`, a `Dosen` column, the page's
author) and those of the student's stored class list, wherever they
appear. A login whose session and stored profile do not name the student
and their NIM captures nothing (open the Profil page once with that
login).

Write actions (attendance, assignment upload and delete, exam upload)
cannot be captured here: only the student may perform them. With
`PORTAL_RECORD_ACTION_RESPONSES=true` (in production only together with
`PORTAL_DEBUG_ALLOW_IN_PRODUCTION=true`) MyCIC keeps sanitized copies of
the portal's answers to them in `storage/app/portal-captures/{action}/`
(`attendance_submit`, `assignment_upload`, `assignment_delete`,
`exam_upload`) and of the pages read to verify them (`{action}_verify`);
copy the ones a test needs into this folder and list them below. Without
a campus session naming the student and their NIM nothing is recorded.

## Before committing a capture

The placeholders cover what the session knows about the student. Read
every new file and look for what they cannot cover:

- the student's name written another way: a nickname, initials, a
  misspelling, or a name word that is also a page word (month and day
  names, words such as `Tugas` or `Hadir`), which stays so dates and the
  heuristics' keywords read as the portal wrote them;
- other people: a lecturer named outside a lecturer field and not in the
  stored class list, a nickname of one, and classmates' names, NIMs,
  emails and photos;
- anything else personal in free text, comments or image URLs.

## Files

The table between the markers is rewritten by the command; add hand-copied
captures as rows of the same shape.

MARKDOWN;

    protected $signature = 'portal:capture-fixtures
        {--session= : Campus session token of an active MyCIC login}
        {--meeting=* : A meeting as meeting_id:courses_id, or meeting_id:courses_id:pertemuan to give the assignment\'s meeting number}
        {--exam=* : An exam id whose detail page (act=sendujian) to capture}
        {--output= : Directory to write to (defaults to tests/Fixtures/portal/real)}';

    protected $description = 'Capture sanitized, read-only copies of portal meeting, assignment and exam pages as test fixtures';

    /**
     * @var array<string, array{description: string, captured_at: string}>
     */
    private array $captured = [];

    private int $failures = 0;

    public function handle(PortalSessionManager $sessionManager, PortalReadOnlyFetcher $fetcher, PortalResponseRecorder $recorder): int
    {
        if ($this->laravel->environment('production')) {
            $this->error('portal:capture-fixtures does not run in production: fixtures are captured from a development checkout.');

            return self::FAILURE;
        }

        $sessionToken = trim((string) $this->option('session'));

        if ($sessionToken === '') {
            $this->error('Pass --session=<campus session token> of an active MyCIC login.');

            return self::FAILURE;
        }

        try {
            $meetings = $this->meetingOptions();
            $examIds = $this->examOptions();
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $session = $sessionManager->isSessionUsable($sessionToken) ? $sessionManager->getSession($sessionToken) : null;

        if ($session === null) {
            $this->error(self::SESSION_UNUSABLE);

            return self::FAILURE;
        }

        $context = $recorder->contextFromSession($session);
        $missing = $recorder->missingIdentity($context);

        if ($missing !== []) {
            $this->error(sprintf(
                'Nothing was captured: the campus session holds no %s to sanitize the pages with, so they would keep it. Open the Profil page in MyCIC with this login so the student\'s profile is stored, then run the command again.',
                implode(' and ', array_map(static fn (string $part): string => $part === 'name' ? 'student name' : 'NIM/username', $missing))
            ));

            return self::FAILURE;
        }

        try {
            $fetcher->setSession($sessionToken);
        } catch (PortalOfflineException) {
            $this->error('The campus session is an offline (archive) session and holds no portal cookies. Log in to MyCIC while the portal is reachable and pass that session token with --session.');

            return self::FAILURE;
        } catch (\Throwable) {
            $this->error(self::SESSION_UNUSABLE);

            return self::FAILURE;
        }

        $outputDirectory = $this->outputDirectory();
        File::ensureDirectoryExists($outputDirectory);

        try {
            $this->captureAll($fetcher, $recorder, $context, $outputDirectory, $meetings, $examIds);
        } catch (LogicException|RuntimeException $exception) {
            $this->error($exception->getMessage());
            $this->writeReadme($outputDirectory);

            return self::FAILURE;
        }

        $this->writeReadme($outputDirectory);
        $this->info(sprintf('Captured %d page(s) into %s.', count($this->captured), $this->displayPath($outputDirectory)));

        if ($this->failures > 0) {
            $this->warn(sprintf('%d page(s) could not be captured.', $this->failures));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array{names: list<string>, nims: list<string>, emails: list<string>, phones: list<string>, user_info: array<string, string>, uploaded_file_names: list<string>, secrets: list<string>, lecturers: list<string>}  $context
     * @param  list<array{meeting_id: string, courses_id: string, pertemuan: string|null}>  $meetings
     * @param  list<string>  $examIds
     */
    private function captureAll(PortalReadOnlyFetcher $fetcher, PortalResponseRecorder $recorder, array $context, string $outputDirectory, array $meetings, array $examIds): void
    {
        $capture = fn (string $endpoint, array $query, string $file, string $description): ?string => $this->capture($fetcher, $recorder, $context, $outputDirectory, $endpoint, $query, $file, $description);

        foreach ($meetings as $meeting) {
            $meetingHtml = $capture(
                'discussion',
                ['id' => $meeting['meeting_id'], 'courses_id' => $meeting['courses_id']],
                sprintf('meeting_%s_%s.html', $meeting['meeting_id'], $meeting['courses_id']),
                sprintf('Meeting page (act=discuss), meeting %s of course %s', $meeting['meeting_id'], $meeting['courses_id'])
            );

            $meetingNumber = $meeting['pertemuan'] ?? ($meetingHtml !== null ? $this->assignmentMeetingNumber($meetingHtml, $meeting['meeting_id']) : null);

            if ($meetingNumber === null) {
                if ($meetingHtml !== null) {
                    $this->line(sprintf('Meeting %s links no assignment upload page; pass %s:%s:<pertemuan> to capture its act=send page.', $meeting['meeting_id'], $meeting['meeting_id'], $meeting['courses_id']));
                }

                continue;
            }

            $capture(
                'assignment_form',
                ['courses_id' => $meeting['courses_id'], 'id' => $meeting['meeting_id'], 'pertemuan' => $meetingNumber],
                sprintf('assignment_form_%s_%s_%s.html', $meeting['meeting_id'], $meeting['courses_id'], $meetingNumber),
                sprintf('Assignment upload page (act=send), meeting %s of course %s, pertemuan %s', $meeting['meeting_id'], $meeting['courses_id'], $meetingNumber)
            );
        }

        $capture('exam_list', [], 'exam_list.html', 'Exam list (act=ujian)');

        foreach ($examIds as $examId) {
            $capture(
                'exam_upload_form',
                ['id' => $examId],
                sprintf('exam_detail_%s.html', $examId),
                sprintf('Exam detail and upload page (act=sendujian), exam %s', $examId)
            );
        }
    }

    /**
     * Read one page, sanitize it and write it. A page that cannot be read or
     * sanitized is reported and skipped; an expired session stops the run.
     *
     * @param  array{names: list<string>, nims: list<string>, emails: list<string>, phones: list<string>, user_info: array<string, string>, uploaded_file_names: list<string>, secrets: list<string>, lecturers: list<string>}  $context
     * @param  array<string, string>  $query
     * @return string|null The page as the portal sent it, or null when it was not captured.
     *
     * @throws LogicException When the endpoint is not read with GET.
     * @throws RuntimeException When the campus session has expired.
     */
    private function capture(
        PortalReadOnlyFetcher $fetcher,
        PortalResponseRecorder $recorder,
        array $context,
        string $outputDirectory,
        string $endpoint,
        array $query,
        string $file,
        string $description
    ): ?string {
        $url = PortalEndpointRegistry::url($endpoint, $query);

        try {
            $html = $fetcher->fetch($url, PortalEndpointRegistry::method($endpoint));
        } catch (LogicException $exception) {
            throw $exception;
        } catch (\Throwable $throwable) {
            if (PortalException::indicatesExpiredSession($throwable)) {
                throw new RuntimeException(self::SESSION_EXPIRED, 0, $throwable);
            }

            $this->failures++;
            $this->warn(sprintf('Could not read %s: %s', $file, $this->sanitizedMessage($throwable, $recorder, $context)));

            return null;
        }

        try {
            $sanitized = $recorder->sanitize($html, $context);
        } catch (\Throwable $throwable) {
            $this->failures++;
            $this->warn(sprintf('Could not sanitize %s, so it was not written: %s', $file, $throwable->getMessage()));

            return null;
        }

        File::put($outputDirectory.DIRECTORY_SEPARATOR.$file, $sanitized);
        $this->captured[$file] = ['description' => $description, 'captured_at' => now()->toIso8601String()];
        $this->line(sprintf('Saved %s: %s', $file, $description));

        return $html;
    }

    /**
     * An HTTP error's message quotes the start of the portal's reply, which
     * may name the student: it is printed sanitized, or not at all.
     *
     * @param  array{names: list<string>, nims: list<string>, emails: list<string>, phones: list<string>, user_info: array<string, string>, uploaded_file_names: list<string>, secrets: list<string>, lecturers: list<string>}  $context
     */
    private function sanitizedMessage(\Throwable $throwable, PortalResponseRecorder $recorder, array $context): string
    {
        try {
            return $recorder->sanitize($throwable->getMessage(), $context);
        } catch (\Throwable) {
            return class_basename($throwable);
        }
    }

    /**
     * The meeting number of the meeting's own assignment upload link
     * (act=send&id={meeting}&pertemuan=N), if the page has one.
     */
    private function assignmentMeetingNumber(string $html, string $meetingId): ?string
    {
        $meetingNumber = null;

        (new Crawler($html))->filter('a[href]')->each(function (Crawler $link) use (&$meetingNumber, $meetingId): void {
            if ($meetingNumber !== null) {
                return;
            }

            parse_str((string) parse_url(html_entity_decode((string) $link->attr('href'), ENT_QUOTES, 'UTF-8'), PHP_URL_QUERY), $query);

            if (($query['act'] ?? null) !== 'send' || (string) ($query['id'] ?? '') !== $meetingId) {
                return;
            }

            $pertemuan = $query['pertemuan'] ?? null;

            if (is_string($pertemuan) && ctype_digit($pertemuan)) {
                $meetingNumber = (string) (int) $pertemuan;
            }
        });

        return $meetingNumber;
    }

    /**
     * @return list<array{meeting_id: string, courses_id: string, pertemuan: string|null}>
     *
     * @throws InvalidArgumentException
     */
    private function meetingOptions(): array
    {
        $meetings = [];

        foreach ((array) $this->option('meeting') as $option) {
            $parts = explode(':', trim((string) $option));

            if (count($parts) < 2 || count($parts) > 3 || array_filter($parts, static fn (string $part): bool => ! ctype_digit($part)) !== []) {
                throw new InvalidArgumentException(sprintf('--meeting=%s is not meeting_id:courses_id or meeting_id:courses_id:pertemuan (digits only).', $option));
            }

            $meetings[] = [
                'meeting_id' => (string) (int) $parts[0],
                'courses_id' => (string) (int) $parts[1],
                'pertemuan' => isset($parts[2]) ? (string) (int) $parts[2] : null,
            ];
        }

        return $meetings;
    }

    /**
     * @return list<string>
     *
     * @throws InvalidArgumentException
     */
    private function examOptions(): array
    {
        $examIds = [];

        foreach ((array) $this->option('exam') as $option) {
            $examId = trim((string) $option);

            if (! ctype_digit($examId)) {
                throw new InvalidArgumentException(sprintf('--exam=%s is not an exam id (digits only).', $option));
            }

            $examIds[] = (string) (int) $examId;
        }

        return array_values(array_unique($examIds));
    }

    private function outputDirectory(): string
    {
        $output = trim((string) $this->option('output'));

        return rtrim($output !== '' ? $output : base_path('tests/Fixtures/portal/real'), '/\\');
    }

    /**
     * Rewrite the README's file table with the pages captured now and the
     * rows already listed.
     */
    private function writeReadme(string $outputDirectory): void
    {
        if ($this->captured === []) {
            return;
        }

        $path = $outputDirectory.DIRECTORY_SEPARATOR.'README.md';
        $readme = File::exists($path) ? File::get($path) : self::README_HEADER;
        $start = strpos($readme, self::README_START);
        $end = strpos($readme, self::README_END);

        if ($start === false || $end === false || $end < $start) {
            $before = rtrim($readme)."\n\n";
            $after = "\n";
            $rows = [];
        } else {
            $before = substr($readme, 0, $start);
            $after = substr($readme, $end + strlen(self::README_END));
            $rows = $this->readmeRows(substr($readme, $start, $end - $start));
        }

        $rows = [...$rows, ...$this->captured];
        ksort($rows);

        $table = ['| File | What it is | Captured at |', '| --- | --- | --- |'];

        foreach ($rows as $file => $row) {
            $table[] = sprintf('| `%s` | %s | %s |', $file, $row['description'], $row['captured_at']);
        }

        File::put($path, $before.self::README_START."\n".implode("\n", $table)."\n".self::README_END.$after);
    }

    /**
     * @return array<string, array{description: string, captured_at: string}>
     */
    private function readmeRows(string $section): array
    {
        preg_match_all('/^\|\s*`([^`]+)`\s*\|\s*(.*?)\s*\|\s*(.*?)\s*\|\s*$/m', $section, $matches, PREG_SET_ORDER);

        $rows = [];

        foreach ($matches as $match) {
            $rows[$match[1]] = ['description' => $match[2], 'captured_at' => $match[3]];
        }

        return $rows;
    }

    private function displayPath(string $path): string
    {
        $basePath = rtrim(base_path(), '/\\').DIRECTORY_SEPARATOR;

        return str_starts_with($path, $basePath) ? substr($path, strlen($basePath)) : $path;
    }
}
