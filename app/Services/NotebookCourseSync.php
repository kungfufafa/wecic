<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Notebook;
use App\Models\NotebookSource;
use App\Models\PortalArchivedFile;
use Illuminate\Support\Facades\Log;

/**
 * Keeps one course notebook per class the student takes, and fills it
 * meeting by meeting.
 *
 * Course notebooks are made from the LMS class list the moment it is
 * synced (and from its snapshot whenever the notebook list is opened), so
 * every course the student takes has a notebook without any setup. A
 * meeting joins its course notebook as a note (topic, outline, schedule,
 * assignment and deadline) plus the course files of that meeting from the
 * archive, on its own whenever its page is read from the portal; files
 * archived later attach themselves. Nothing here contacts the portal:
 * callers pass the dataset they already read.
 *
 * @phpstan-type ClassParams array{id?: string, dosen_id?: string, course_id?: string}
 * @phpstan-type MeetingParams array{meeting_id: string, courses_id: string}
 */
class NotebookCourseSync
{
    public function __construct(
        private readonly NotebookLibrary $library,
        private readonly PortalArchivedFileStore $archivedFiles,
        private readonly PortalSnapshotStore $snapshots,
        private readonly PortalDataArchive $archive,
        private readonly PortalDataOwner $owner
    ) {}

    public function enabled(): bool
    {
        return (bool) config('notebook.enabled', true) && (bool) config('notebook.course_notebooks.enabled', true);
    }

    /**
     * Course notebooks for the classes in the stored class list; never
     * waits on the portal. Returns how many notebooks were created.
     */
    public function provisionFromSnapshot(string $sessionToken): int
    {
        $classes = $this->classesFromSnapshot($sessionToken);

        return $classes === [] ? 0 : $this->provisionFromClasses($sessionToken, ['classes' => $classes]);
    }

    /**
     * Course notebooks for every class of a freshly built class list
     * (`lms_classes` or `classes` dataset). Never throws: a notebook must
     * not fail the sync that found the class.
     *
     * @param  array<string, mixed>  $classesData
     */
    public function provisionFromClasses(string $sessionToken, array $classesData): int
    {
        try {
            $ownerHash = $this->owner->key($sessionToken);

            if (! $this->enabled() || ! $this->isStudent($ownerHash)) {
                return 0;
            }

            $created = 0;

            foreach ($this->describeClasses($classesData) as $class) {
                $notebook = $this->courseNotebook($ownerHash, $class['course_key'], $class, $wasCreated);
                $created += $wasCreated ? 1 : 0;
                $this->attachCourseFiles($notebook);
            }

            return $created;
        } catch (\Throwable $throwable) {
            Log::warning('Course notebooks could not be provisioned', ['error' => $throwable->getMessage()]);

            return 0;
        }
    }

    /**
     * The course notebook of one class: found, or made with what is known
     * about the course. Known details (name, code, lecturer, class page)
     * are filled in whenever they are missing.
     *
     * @param  array{course_name?: string|null, course_code?: string|null, lecturer_name?: string|null, class_params?: ClassParams|null}  $attributes
     */
    public function courseNotebook(string $ownerHash, string $courseKey, array $attributes = [], ?bool &$created = null): Notebook
    {
        $notebook = Notebook::query()->forOwner($ownerHash)->course($courseKey)->first();
        $created = $notebook === null;

        if ($notebook === null) {
            $notebook = Notebook::query()->create([
                'owner_hash' => $ownerHash,
                'kind' => Notebook::KIND_COURSE,
                'course_key' => $courseKey,
                'title' => $this->title($attributes['course_name'] ?? null, $courseKey),
            ]);
        }

        $details = array_filter([
            'course_name' => isset($attributes['course_name']) ? mb_substr(trim((string) $attributes['course_name']), 0, 255) : null,
            'course_code' => isset($attributes['course_code']) ? mb_substr(trim((string) $attributes['course_code']), 0, 32) : null,
            'lecturer_name' => isset($attributes['lecturer_name']) ? mb_substr(trim((string) $attributes['lecturer_name']), 0, 255) : null,
            'class_params' => $attributes['class_params'] ?? null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);

        foreach ($details as $field => $value) {
            if ($notebook->{$field} === null || $notebook->{$field} === '' || $notebook->{$field} === []) {
                $notebook->{$field} = $value;
            }
        }

        if ($notebook->title === $this->title(null, $courseKey) && isset($details['course_name'])) {
            $notebook->title = $this->title($details['course_name'], $courseKey);
        }

        if ($notebook->isDirty()) {
            $notebook->save();
        }

        return $notebook;
    }

    public function findCourseNotebook(string $ownerHash, string $courseKey): ?Notebook
    {
        return Notebook::query()->forOwner($ownerHash)->course($courseKey)->first();
    }

    /**
     * What the stored class list says about one course, if it lists it.
     *
     * @return array{course_key: string, course_name: string|null, course_code: string|null, lecturer_name: string|null, class_params: ClassParams}|null
     */
    public function describeCourse(string $sessionToken, string $courseKey): ?array
    {
        foreach ($this->describeClasses(['classes' => $this->classesFromSnapshot($sessionToken)]) as $class) {
            if ($class['course_key'] === $courseKey) {
                return $class;
            }
        }

        return null;
    }

    /**
     * Add one meeting to its course notebook: the meeting note, the
     * meeting's archived files, and the course RPS. Files the archive does
     * not have yet are queued; they attach themselves once archived.
     *
     * @param  array<string, mixed>  $meetingDetail
     * @param  MeetingParams  $params
     * @return array{note: NotebookSource, files: list<NotebookSource>, meeting_number: int|null, queued_files: int}
     */
    public function addMeeting(string $sessionToken, Notebook $notebook, array $meetingDetail, array $params): array
    {
        $meetingId = (string) $params['meeting_id'];
        $meetingNumber = $this->meetingNumber($meetingDetail);
        $note = $this->library->addMeetingNote(
            $notebook,
            $meetingId,
            $meetingNumber,
            $this->meetingTitle($meetingDetail, $meetingNumber),
            $this->meetingText($meetingDetail, $notebook, $meetingNumber)
        );

        $files = PortalArchivedFile::query()
            ->forOwner($notebook->owner_hash)
            ->where('meeting_id', $meetingId)
            ->orderBy('kind')
            ->get()
            ->map(fn (PortalArchivedFile $file): NotebookSource => $this->library->addArchivedFile($notebook, $file))
            ->all();
        array_push($files, ...$this->attachCourseFiles($notebook));

        $candidates = $this->archivedFiles->candidatesFromMeeting($meetingDetail, $params);
        $known = PortalArchivedFile::query()->forOwner($notebook->owner_hash)->whereIn('source_url', array_column($candidates, 'source_url'))->count();
        $this->archivedFiles->queueFromMeeting($sessionToken, $meetingDetail, $params);

        $notebook->touch();

        return [
            'note' => $note,
            'files' => $files,
            'meeting_number' => $meetingNumber,
            'queued_files' => max(0, count($candidates) - $known),
        ];
    }

    /**
     * A meeting MyCIC just read from the portal joins its course notebook
     * on its own (`notebook.course_notebooks.auto_add_opened_meetings`),
     * so opening a meeting page is all it takes. Never throws: the
     * notebook must not fail the read that found the meeting.
     *
     * @param  array<string, mixed>  $meetingDetail
     * @param  array<string, mixed>  $params  `meeting_id` and `courses_id`
     */
    public function addOpenedMeeting(string $sessionToken, array $meetingDetail, array $params): ?NotebookSource
    {
        try {
            $meetingId = isset($params['meeting_id']) ? (string) $params['meeting_id'] : '';
            $courseKey = isset($params['courses_id']) ? (string) $params['courses_id'] : '';
            $ownerHash = $this->owner->key($sessionToken);

            if (! $this->enabled() || ! (bool) config('notebook.course_notebooks.auto_add_opened_meetings', true)
                || ! $this->isStudent($ownerHash) || ($meetingDetail['found'] ?? true) === false
                || ! ctype_digit($meetingId) || ! ctype_digit($courseKey)) {
                return null;
            }

            $course = $this->describeCourse($sessionToken, $courseKey) ?? [
                'course_name' => $this->courseNameFromMeeting($meetingDetail)
                    ?? PortalArchivedFile::query()->forOwner($ownerHash)->where('course_key', $courseKey)->whereNotNull('course_name')->value('course_name'),
            ];
            $notebook = $this->courseNotebook($ownerHash, $courseKey, $course);
            $tracked = $notebook->meetingSources()->where('meeting_id', $meetingId)->exists();

            if (! $tracked && $notebook->sources()->count() >= (int) config('notebook.limits.max_sources', 30)) {
                Log::info('Meeting not added to the course notebook: source limit reached', ['notebook_id' => $notebook->id]);

                return null;
            }

            return $this->addMeeting($sessionToken, $notebook, $meetingDetail, ['meeting_id' => $meetingId, 'courses_id' => $courseKey])['note'];
        } catch (\Throwable $throwable) {
            Log::warning('Opened meeting could not be added to the course notebook', ['error' => $throwable->getMessage()]);

            return null;
        }
    }

    /**
     * A file that was just archived joins the course notebook that tracks
     * its meeting (or, for an RPS, any course notebook of its course).
     * Never throws: archiving must not fail because of the notebook.
     */
    public function attachArchivedFile(PortalArchivedFile $file): ?NotebookSource
    {
        try {
            if (! $this->enabled() || $file->course_key === null) {
                return null;
            }

            $notebook = $this->findCourseNotebook($file->owner_hash, $file->course_key);

            if ($notebook === null) {
                return null;
            }

            $tracked = $file->kind === 'rps' || ($file->meeting_id !== null && $notebook->meetingSources()->where('meeting_id', $file->meeting_id)->exists());

            return $tracked ? $this->library->addArchivedFile($notebook, $file) : null;
        } catch (\Throwable $throwable) {
            Log::warning('Archived file could not be added to the course notebook', [
                'file_id' => $file->id,
                'error' => $throwable->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * The meetings of the notebook's class from the stored class page, each
     * marked whether it is in the notebook already. Empty when the class
     * page has not been opened yet.
     *
     * @return array{available: bool, meetings: list<array{meeting_id: string, courses_id: string, meeting_number: int|null, title: string, date: string|null, time: string|null, description: string|null, meeting_url: string, added: bool, archived_files: int}>}
     */
    public function meetings(string $sessionToken, Notebook $notebook): array
    {
        $classDetail = $this->classDetailFromSnapshot($sessionToken, $notebook);

        if ($classDetail === null) {
            return ['available' => false, 'meetings' => []];
        }

        $added = $notebook->meetingSources()->pluck('meeting_id')->map(static fn (mixed $id): string => (string) $id)->all();
        $archived = PortalArchivedFile::query()
            ->forOwner($notebook->owner_hash)
            ->where('course_key', (string) $notebook->course_key)
            ->whereNotNull('meeting_id')
            ->get(['meeting_id'])
            ->countBy('meeting_id');
        $meetings = [];

        foreach ((array) ($classDetail['course_materials'] ?? []) as $index => $material) {
            $params = self::meetingParams($material);

            if ($params === null || isset($meetings[$params['meeting_id']])) {
                continue;
            }

            $number = is_int($material['meeting_number'] ?? null) ? $material['meeting_number'] : null;
            $meetings[$params['meeting_id']] = [
                'meeting_id' => $params['meeting_id'],
                'courses_id' => $params['courses_id'],
                'meeting_number' => $number,
                'title' => $this->clean((string) ($material['title'] ?? '')) ?: 'Pertemuan '.($number ?? $index + 1),
                'date' => is_string($material['date'] ?? null) && $material['date'] !== '' ? $material['date'] : null,
                'time' => is_string($material['time'] ?? null) && $material['time'] !== '' ? $material['time'] : null,
                'description' => is_string($material['description'] ?? null) && trim($material['description']) !== '' ? mb_substr(trim($material['description']), 0, 300) : null,
                'meeting_url' => '/meeting/'.rawurlencode($params['meeting_id']).'?courses_id='.rawurlencode($params['courses_id']),
                'added' => in_array($params['meeting_id'], $added, true),
                'archived_files' => (int) ($archived[$params['meeting_id']] ?? 0),
            ];
        }

        return ['available' => true, 'meetings' => array_values($meetings)];
    }

    /**
     * `meeting_id` and `courses_id` of a class page material's link.
     *
     * @return MeetingParams|null
     */
    public static function meetingParams(mixed $material): ?array
    {
        $href = is_array($material) ? ($material['join_link']['href'] ?? null) : null;

        if (! is_string($href) || $href === '') {
            return null;
        }

        parse_str((string) (parse_url(html_entity_decode($href), PHP_URL_QUERY) ?? ''), $query);
        $meetingId = $query['id'] ?? null;
        $coursesId = $query['courses_id'] ?? ($query['course_id'] ?? null);

        if (! is_string($meetingId) || ! ctype_digit($meetingId) || ! is_string($coursesId) || ! ctype_digit($coursesId)) {
            return null;
        }

        return ['meeting_id' => $meetingId, 'courses_id' => $coursesId];
    }

    /**
     * @param  array<string, mixed>  $meetingDetail
     */
    public function meetingNumber(array $meetingDetail): ?int
    {
        $candidates = [
            $meetingDetail['materials']['assignment_topic'] ?? null,
            $meetingDetail['materials']['main_topic'] ?? null,
            $meetingDetail['conformity_info']['description'] ?? null,
            $meetingDetail['meeting_info']['breadcrumb'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && preg_match('/pertemuan\s*(?:ke\s*)?-?\s*(\d{1,2})/i', $candidate, $matches) === 1) {
                return (int) $matches[1];
            }
        }

        return null;
    }

    /**
     * The course name as the meeting page shows it ("Kelas Basis Data > ...").
     *
     * @param  array<string, mixed>  $meetingDetail
     */
    public function courseNameFromMeeting(array $meetingDetail): ?string
    {
        $breadcrumb = $meetingDetail['meeting_info']['breadcrumb'] ?? null;

        if (is_string($breadcrumb) && preg_match('/Kelas\s+([^>]+?)(?:\s*>|$)/iu', $breadcrumb, $matches) === 1) {
            return trim($matches[1]) !== '' ? trim($matches[1]) : null;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $meetingDetail
     */
    private function meetingTitle(array $meetingDetail, ?int $meetingNumber): string
    {
        $topic = $this->clean((string) ($meetingDetail['materials']['main_topic'] ?? ''));

        return implode(' · ', array_filter([
            $meetingNumber !== null ? 'Pertemuan '.$meetingNumber : 'Pertemuan',
            $topic !== '' ? $topic : null,
        ]));
    }

    /**
     * The meeting as plain text the notebook can quote: what was covered,
     * when, by whom, and what was assigned.
     *
     * @param  array<string, mixed>  $meetingDetail
     */
    private function meetingText(array $meetingDetail, Notebook $notebook, ?int $meetingNumber): string
    {
        $materials = is_array($meetingDetail['materials'] ?? null) ? $meetingDetail['materials'] : [];
        $schedule = is_array($meetingDetail['schedule_info'] ?? null) ? $meetingDetail['schedule_info'] : [];
        $assignment = is_array($meetingDetail['assignment_info'] ?? null) ? $meetingDetail['assignment_info'] : [];
        $instructions = is_array($meetingDetail['instructions'] ?? null) ? $meetingDetail['instructions'] : [];
        $lecturer = $meetingDetail['lecturer_info']['name'] ?? null;
        $topic = $this->clean((string) ($materials['main_topic'] ?? ''));
        $lines = [$this->meetingTitle($meetingDetail, $meetingNumber)];

        $facts = array_filter([
            'Mata kuliah' => implode(' ', array_filter([$notebook->course_name, $notebook->course_code !== null ? '('.$notebook->course_code.')' : null])),
            'Dosen' => is_string($lecturer) ? trim($lecturer) : null,
            'Jadwal' => implode(' ', array_filter([
                is_string($schedule['date'] ?? null) ? trim($schedule['date']) : null,
                is_string($schedule['start_time'] ?? null) && trim($schedule['start_time']) !== '' ? 'pukul '.trim($schedule['start_time']) : null,
            ])),
        ], static fn (?string $value): bool => $value !== null && trim($value) !== '');

        foreach ($facts as $label => $value) {
            $lines[] = "{$label}: {$value}";
        }

        $description = is_string($materials['main_description'] ?? null) ? trim($materials['main_description']) : '';

        if ($topic !== '' || $description !== '') {
            $lines[] = '';
            $lines[] = 'Pokok bahasan: '.($topic !== '' ? $topic : '-');

            if ($description !== '') {
                $lines[] = 'Uraian pokok pembahasan:';
                $lines[] = $description;
            }
        }

        $assignmentTopic = is_string($materials['assignment_topic'] ?? null) ? trim($materials['assignment_topic']) : '';
        $dueDate = $this->firstText([$materials['assignment_instructions'] ?? null, $assignment['deadline'] ?? null]);
        $uploadDeadline = $this->firstText([$instructions['deadline_text'] ?? null]);
        $hasAssignment = $assignmentTopic !== '' || $dueDate !== null || $uploadDeadline !== null
            || (is_string($materials['assignment_material_iframe'] ?? null) && $materials['assignment_material_iframe'] !== '');

        if ($hasAssignment) {
            $lines[] = '';
            $lines[] = 'Tugas pertemuan ini: '.($assignmentTopic !== '' ? $assignmentTopic : 'ada tugas yang harus dikumpulkan');

            if ($dueDate !== null) {
                $lines[] = 'Tenggat (due date): '.$dueDate;
            }

            if ($uploadDeadline !== null && $uploadDeadline !== $dueDate) {
                $lines[] = 'Batas waktu unggah: '.$uploadDeadline;
            }

            if (is_string($materials['assignment_material_iframe'] ?? null) && $materials['assignment_material_iframe'] !== '') {
                $lines[] = 'Soal/latihan tersedia sebagai berkas PDF (lihat sumber "Latihan / tugas" pertemuan ini).';
            }
        }

        if (is_string($materials['main_material_iframe'] ?? null) && $materials['main_material_iframe'] !== '') {
            $lines[] = '';
            $lines[] = 'Modul materi tersedia sebagai berkas PDF (lihat sumber "Materi" pertemuan ini).';
        }

        return implode("\n", $lines);
    }

    /**
     * The course RPS files already archived, as sources of the notebook.
     *
     * @return list<NotebookSource>
     */
    private function attachCourseFiles(Notebook $notebook): array
    {
        if ($notebook->course_key === null) {
            return [];
        }

        return PortalArchivedFile::query()
            ->forOwner($notebook->owner_hash)
            ->where('course_key', $notebook->course_key)
            ->where('kind', 'rps')
            ->get()
            ->map(fn (PortalArchivedFile $file): NotebookSource => $this->library->addArchivedFile($notebook, $file))
            ->all();
    }

    /**
     * The classes of a class list as course descriptions, keyed by course.
     *
     * @param  array<string, mixed>  $classesData
     * @return list<array{course_key: string, course_name: string|null, course_code: string|null, lecturer_name: string|null, class_params: ClassParams}>
     */
    private function describeClasses(array $classesData): array
    {
        $courses = [];

        foreach ((array) ($classesData['classes'] ?? []) as $class) {
            $params = is_array($class) ? ($class['join_link']['params'] ?? null) : null;
            $courseKey = is_array($params) && isset($params['course_id']) && ctype_digit((string) $params['course_id']) ? (string) $params['course_id'] : null;

            if ($courseKey === null || isset($courses[$courseKey])) {
                continue;
            }

            $courses[$courseKey] = [
                'course_key' => $courseKey,
                'course_name' => $this->firstText([$class['course_info']['name'] ?? null]),
                'course_code' => $this->firstText([$class['course_info']['code'] ?? null]),
                'lecturer_name' => $this->firstText([$class['lecturer_info']['name'] ?? null]),
                'class_params' => array_filter([
                    'id' => isset($params['id']) ? (string) $params['id'] : null,
                    'dosen_id' => isset($params['dosen_id']) ? (string) $params['dosen_id'] : null,
                    'course_id' => $courseKey,
                ]),
            ];
        }

        return array_values($courses);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function classesFromSnapshot(string $sessionToken): array
    {
        foreach (['lms_classes', 'classes'] as $dataset) {
            $classes = $this->snapshots->get($sessionToken, $dataset)['data']['classes'] ?? null;

            if (is_array($classes) && $classes !== []) {
                return array_values($classes);
            }
        }

        try {
            $classes = $this->archive->restore($sessionToken, 'classes')['data']['classes'] ?? null;
        } catch (\Throwable) {
            $classes = null;
        }

        return is_array($classes) ? array_values($classes) : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function classDetailFromSnapshot(string $sessionToken, Notebook $notebook): ?array
    {
        $params = $notebook->class_params;

        if (! is_array($params) || ! isset($params['id'])) {
            return null;
        }

        $detail = $this->snapshots->get($sessionToken, 'class_detail', $params)['data'] ?? null;

        if ($detail === null) {
            try {
                $detail = $this->archive->restore($sessionToken, 'class_detail', $params)['data'] ?? null;
            } catch (\Throwable) {
                $detail = null;
            }
        }

        return is_array($detail) ? $detail : null;
    }

    private function title(?string $courseName, string $courseKey): string
    {
        $courseName = $courseName !== null ? trim($courseName) : '';

        return mb_substr($courseName !== '' ? $courseName : 'Mata kuliah '.$courseKey, 0, 120);
    }

    /**
     * @param  list<mixed>  $values
     */
    private function firstText(array $values): ?string
    {
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '' && trim($value) !== ':') {
                return trim($value);
            }
        }

        return null;
    }

    private function clean(string $topic): string
    {
        return trim((string) preg_replace('/^(?:Pokok\s+Bahasan|Materi|Topik)\s*:\s*/iu', '', trim($topic)));
    }

    private function isStudent(string $ownerHash): bool
    {
        return str_starts_with($ownerHash, 'student_');
    }
}
