<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Exceptions\NotebookAiException;
use App\Http\Requests\AddNotebookMeetingRequest;
use App\Http\Requests\AskNotebookRequest;
use App\Http\Requests\NotebookStudioRequest;
use App\Http\Requests\StoreCourseNotebookRequest;
use App\Http\Requests\StoreNotebookRequest;
use App\Http\Requests\StoreNotebookSourceRequest;
use App\Http\Requests\UpdateNotebookMessageRequest;
use App\Http\Requests\UpdateNotebookRequest;
use App\Jobs\SyncCourseNotebookJob;
use App\Models\Notebook;
use App\Models\NotebookMessage;
use App\Models\NotebookSource;
use App\Models\PortalArchivedFile;
use App\Services\NotebookAssistant;
use App\Services\NotebookCourseSync;
use App\Services\NotebookLibrary;
use App\Services\PortalArchivedFileStore;
use App\Services\PortalDataOwner;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;

#[Group('Notebook', 'Buku belajar ala NotebookLM. Setiap mata kuliah di daftar kelas punya notebook sendiri yang dibuat otomatis; pertemuan masuk sendiri saat dibuka (catatan pertemuan + berkas materinya) dan bisa ditambahkan dari daftar. Sumber lain: arsip materi, unggahan, catatan. Tanya jawab bersitasi dengan konteks yang bisa dipilih dan terukur; studio: ringkasan, panduan belajar, kerangka tugas, FAQ, glosarium, kuis, flashcard, peta konsep.', 18)]
class NotebookController extends CampusApiController
{
    private const NOTEBOOK_ITEM = "array{id: int, title: string, kind: 'course'|'custom', course_key: string|null, course_name: string|null, course_code: string|null, lecturer_name: string|null, class_url: string|null, sources_count: int, meetings_count: int, messages_count: int, created_at: string|null, updated_at: string|null}";

    private const SOURCE_ITEM = "array{id: int, kind: 'meeting'|'archived_file'|'upload'|'text', title: string, meeting_id: string|null, meeting_number: int|null, mime: string|null, status: 'pending'|'processing'|'ready'|'failed', error: string|null, summary: string|null, char_count: int, page_count: int|null, truncated: bool, stalled: bool, waiting: bool, archived_file_id: int|null, created_at: string|null}";

    private const CONTEXT_ITEM = 'array{source_ids: list<int>, sources: list<array{id: int, title: string, meeting_number: int|null}>, total_sources: int, excerpts: int, chars: int, partial: bool, focus: string|null, truncated?: bool}';

    private const MESSAGE_ITEM = "array{id: int, role: 'user'|'assistant', kind: string, content: string, data: mixed, citations: list<array{n: int, source_id: int, source_title: string, page: int|null, excerpt: string}>, context: ".self::CONTEXT_ITEM.'|null, pinned: bool, created_at: string|null}';

    private const MEETING_ITEM = 'array{meeting_id: string, courses_id: string, meeting_number: int|null, title: string, date: string|null, time: string|null, description: string|null, meeting_url: string, added: bool, archived_files: int}';

    private const MEETINGS_ITEM = 'array{available: bool, meetings: list<'.self::MEETING_ITEM.'>}';

    private const AI_STATUS = "'ok'|'disabled'|'missing_key'|'invalid_key'|'model_misconfigured'";

    private const AI_ERROR_RESPONSE = "array{status: 'error', message: string, data?: array{ai_status: ".self::AI_STATUS.'}, meta: array{timestamp: string, campus_session_valid: true}}';

    public function __construct(
        private readonly NotebookLibrary $library,
        private readonly NotebookAssistant $assistant,
        private readonly NotebookCourseSync $courseSync,
        private readonly PortalArchivedFileStore $archivedFiles,
        private readonly PortalDataOwner $owner
    ) {}

    /**
     * Daftar notebook milik mahasiswa, terbaru dulu.
     *
     * Notebook mata kuliah (`kind: course`) dibuat otomatis dari daftar kelas yang tersimpan, satu per mata kuliah.
     * `ai_available` false berarti model AI belum bisa dipakai; sumber tetap bisa ditambahkan. `ai_status` menyebut sebabnya:
     * `disabled` (dimatikan di server), `missing_key` (GEMINI_API_KEY kosong), `invalid_key` (kunci API ditolak Gemini),
     * `model_misconfigured` (pengaturan model AI ditolak Gemini: model tidak dikenali atau sudah tidak tersedia, atau
     * NOTEBOOK_THINKING_BUDGET tidak cocok), atau `ok`.
     */
    #[Response(description: 'Daftar notebook.', type: "array{status: 'success', message: string, data: array{notebooks: list<".self::NOTEBOOK_ITEM.'>, ai_available: bool, ai_status: '.self::AI_STATUS.', course_notebooks: bool, studio_kinds: array<string, string>}, meta: array{timestamp: string, campus_session_valid: true}}')]
    #[Response(401, description: 'Endpoint ini membutuhkan sesi kampus yang aktif.', type: CampusApiController::SCRAMBLE_SESSION_ERROR_RESPONSE)]
    public function index(): JsonResponse
    {
        return $this->withCampusSession('Notebook list failed', 'Gagal memuat notebook', function (string $sessionToken): JsonResponse {
            $this->courseSync->provisionFromSnapshot($sessionToken);

            $notebooks = Notebook::query()
                ->forOwner($this->owner->key($sessionToken))
                ->withCount(['sources', 'messages', 'meetingSources as meetings_count'])
                ->latest('updated_at')
                ->get()
                ->map(static fn (Notebook $notebook): array => $notebook->toApiArray())
                ->all();

            return $this->statusSuccess('Notebooks retrieved', [
                'data' => [
                    'notebooks' => $notebooks,
                    'ai_available' => $this->assistant->isAvailable(),
                    'ai_status' => $this->assistant->aiStatus(),
                    'course_notebooks' => $this->courseSync->enabled(),
                    'studio_kinds' => NotebookAssistant::STUDIO_KINDS,
                ],
            ], campusSessionValid: true);
        });
    }

    /**
     * Buka notebook satu mata kuliah; dibuat dulu bila belum ada.
     *
     * Mata kuliah dicari di daftar kelas yang tersimpan, lalu di arsip materi. `created` memberi tahu apakah notebook baru dibuat.
     */
    #[Response(description: 'Notebook mata kuliah.', type: "array{status: 'success', message: string, data: array{notebook: ".self::NOTEBOOK_ITEM.', created: bool}, meta: array{timestamp: string, campus_session_valid: true}}')]
    #[Response(404, description: 'Mata kuliah tidak ada di daftar kelas maupun arsip materi.', type: CampusApiController::SCRAMBLE_NOT_FOUND_RESPONSE)]
    #[Response(422, description: 'Parameter tidak valid.', type: CampusApiController::SCRAMBLE_VALIDATION_ERROR_RESPONSE)]
    public function storeCourse(StoreCourseNotebookRequest $request): JsonResponse
    {
        return $this->withCampusSession('Course notebook failed', 'Gagal membuka notebook mata kuliah', function (string $sessionToken) use ($request): JsonResponse {
            $ownerHash = $this->owner->key($sessionToken);
            $courseKey = $request->courseKey();
            $course = $this->courseSync->describeCourse($sessionToken, $courseKey);

            if ($course === null && $this->courseSync->findCourseNotebook($ownerHash, $courseKey) === null) {
                $archivedName = PortalArchivedFile::query()->forOwner($ownerHash)->where('course_key', $courseKey)->whereNotNull('course_name')->value('course_name');

                if ($archivedName === null) {
                    return $this->statusError('Mata kuliah ini tidak ada di daftar kelasmu. Buka halaman Kuliah dulu agar daftar kelas tersimpan.', 404, campusSessionValid: true);
                }

                $course = ['course_name' => $archivedName];
            }

            $notebook = $this->courseSync->courseNotebook($ownerHash, $courseKey, $course ?? [], $created);

            return $this->statusSuccess($created ? 'Notebook mata kuliah dibuat.' : 'Notebook mata kuliah dibuka.', [
                'data' => [
                    'notebook' => $notebook->loadCount(['sources', 'messages', 'meetingSources as meetings_count'])->toApiArray(),
                    'created' => $created,
                ],
            ], campusSessionValid: true, statusCode: $created ? 201 : 200);
        });
    }

    /**
     * Masukkan satu pertemuan ke notebook mata kuliahnya (dibuat bila belum ada).
     *
     * Pertemuan menjadi catatan (pokok bahasan, uraian, jadwal, tugas, tenggat) yang langsung siap, ditambah berkas materi dan latihannya
     * dari arsip (`pending` sampai selesai dibaca). Berkas yang belum diarsipkan diunduh di background dan masuk sendiri.
     * Detail pertemuan diambil dari data tersimpan; pertemuan yang belum pernah dibuka diambil dari portal (butuh online).
     */
    #[Response(201, description: 'Pertemuan masuk ke notebook.', type: "array{status: 'success', message: string, data: array{notebook: ".self::NOTEBOOK_ITEM.', meeting: array{meeting_id: string, meeting_number: int|null, title: string, queued_files: int}, sources: list<'.self::SOURCE_ITEM.'>}, meta: array{timestamp: string, campus_session_valid: true}}')]
    #[Response(404, description: 'Pertemuan tidak ditemukan di portal.', type: CampusApiController::SCRAMBLE_NOT_FOUND_RESPONSE)]
    #[Response(422, description: 'Parameter tidak valid atau batas sumber tercapai.', type: CampusApiController::SCRAMBLE_VALIDATION_ERROR_RESPONSE)]
    #[Response(503, description: 'Mode offline dan pertemuan ini belum pernah dibuka.', type: CampusApiController::SCRAMBLE_STATUS_ERROR_RESPONSE)]
    public function addMeeting(AddNotebookMeetingRequest $request): JsonResponse
    {
        return $this->withCampusSession('Notebook meeting add failed', 'Gagal memasukkan pertemuan ke notebook', function (string $sessionToken) use ($request): JsonResponse {
            $params = $request->meetingParams();
            $meeting = $this->readDataset($request, $sessionToken, 'meeting_detail', $params)['data'];

            if (($meeting['found'] ?? true) === false) {
                return $this->statusError('Pertemuan tidak ditemukan. Periksa kembali link yang kamu buka.', 404, campusSessionValid: true);
            }

            $ownerHash = $this->owner->key($sessionToken);
            $course = $this->courseSync->describeCourse($sessionToken, $params['courses_id']) ?? [
                'course_name' => $this->courseSync->courseNameFromMeeting($meeting)
                    ?? PortalArchivedFile::query()->forOwner($ownerHash)->where('course_key', $params['courses_id'])->whereNotNull('course_name')->value('course_name'),
            ];
            $notebook = $this->courseSync->courseNotebook($ownerHash, $params['courses_id'], $course);
            $existing = $notebook->meetingSources()->where('meeting_id', $params['meeting_id'])->exists();

            if (! $this->courseSync->canAddMeeting($notebook, $params['meeting_id'])) {
                return $this->statusError('Notebook mata kuliah ini sudah mencapai batas jumlah sumber. Hapus sumber yang tidak perlu dulu.', 422, campusSessionValid: true);
            }

            $added = $this->courseSync->addMeeting($sessionToken, $notebook, $meeting, $params, byStudent: true);
            $this->library->readWaitingSources($notebook);
            $sources = [$added['note'], ...$added['files']];

            return $this->statusSuccess($existing ? 'Catatan pertemuan diperbarui di notebook.' : 'Pertemuan masuk ke notebook.', [
                'data' => [
                    'notebook' => $notebook->refresh()->loadCount(['sources', 'messages', 'meetingSources as meetings_count'])->toApiArray(),
                    'meeting' => [
                        'meeting_id' => $params['meeting_id'],
                        'meeting_number' => $added['meeting_number'],
                        'title' => $added['note']->title,
                        'queued_files' => $added['queued_files'],
                    ],
                    'sources' => array_map(static fn (NotebookSource $source): array => $source->refresh()->toApiArray(), $sources),
                ],
            ], campusSessionValid: true, statusCode: 201);
        }, $request->only(['meeting_id', 'courses_id']));
    }

    /**
     * Pertemuan-pertemuan mata kuliah notebook ini, dengan tanda mana yang sudah masuk.
     *
     * Dibaca dari halaman kelas yang tersimpan; `available` false berarti halaman kelas belum pernah dibuka.
     */
    #[PathParameter('notebook', 'Id notebook mata kuliah.', type: 'integer', example: 3)]
    #[Response(description: 'Daftar pertemuan.', type: "array{status: 'success', message: string, data: ".self::MEETINGS_ITEM.', meta: array{timestamp: string, campus_session_valid: true}}')]
    #[Response(404, description: 'Notebook tidak ditemukan.', type: CampusApiController::SCRAMBLE_NOT_FOUND_RESPONSE)]
    #[Response(422, description: 'Bukan notebook mata kuliah.', type: CampusApiController::SCRAMBLE_STATUS_ERROR_RESPONSE)]
    public function meetings(int $notebook): JsonResponse
    {
        return $this->withNotebook($notebook, 'Notebook meetings failed', 'Gagal memuat daftar pertemuan', function (Notebook $owned): JsonResponse {
            if (! $owned->isCourseNotebook()) {
                return $this->statusError('Hanya notebook mata kuliah yang punya daftar pertemuan.', 422, campusSessionValid: true);
            }

            return $this->statusSuccess('Meetings retrieved', [
                'data' => $this->courseSync->meetings((string) $this->sessionToken(), $owned),
            ], campusSessionValid: true);
        });
    }

    /**
     * Masukkan semua pertemuan mata kuliah ini ke notebook di background.
     *
     * Setiap pertemuan di halaman kelas dibaca dari portal, dicatat, dan berkasnya diarsipkan. Hasilnya diumumkan lewat event realtime.
     */
    #[PathParameter('notebook', 'Id notebook mata kuliah.', type: 'integer', example: 3)]
    #[Response(202, description: 'Sinkronisasi berjalan di background.', type: "array{status: 'success', message: string, data: array{status: 'queued', notebook_id: int}, meta: array{timestamp: string, campus_session_valid: true}}")]
    #[Response(404, description: 'Notebook tidak ditemukan.', type: CampusApiController::SCRAMBLE_NOT_FOUND_RESPONSE)]
    #[Response(422, description: 'Bukan notebook mata kuliah, atau kelasnya belum dikenal.', type: CampusApiController::SCRAMBLE_STATUS_ERROR_RESPONSE)]
    public function syncMeetings(int $notebook): JsonResponse
    {
        return $this->withNotebook($notebook, 'Notebook meeting sync failed', 'Gagal menyinkronkan pertemuan', function (Notebook $owned): JsonResponse {
            if (! $owned->isCourseNotebook() || ! is_array($owned->class_params) || ! isset($owned->class_params['id'])) {
                return $this->statusError('Kelas notebook ini belum dikenal. Buka halaman Kuliah dulu agar daftar kelas tersimpan.', 422, campusSessionValid: true);
            }

            SyncCourseNotebookJob::dispatch((string) session()->getId(), (string) $this->sessionToken(), $owned->id);

            return $this->statusSuccess('Semua pertemuan sedang dimasukkan ke notebook. Kamu akan diberi tahu saat selesai.', [
                'data' => ['status' => 'queued', 'notebook_id' => $owned->id],
            ], campusSessionValid: true, statusCode: 202);
        });
    }

    /**
     * Buat notebook. Dengan `course_key`, semua materi arsip mata kuliah itu langsung jadi sumber.
     */
    #[Response(201, description: 'Notebook dibuat.', type: "array{status: 'success', message: string, data: array{notebook: ".self::NOTEBOOK_ITEM.'}, meta: array{timestamp: string, campus_session_valid: true}}')]
    #[Response(422, description: 'Data tidak valid atau batas jumlah notebook tercapai.', type: CampusApiController::SCRAMBLE_VALIDATION_ERROR_RESPONSE)]
    public function store(StoreNotebookRequest $request): JsonResponse
    {
        return $this->withCampusSession('Notebook create failed', 'Gagal membuat notebook', function (string $sessionToken) use ($request): JsonResponse {
            $ownerHash = $this->owner->key($sessionToken);

            if (Notebook::query()->forOwner($ownerHash)->count() >= (int) config('notebook.limits.max_notebooks', 50)) {
                return $this->statusError('Jumlah notebook sudah mencapai batas. Hapus notebook lama dulu.', 422, campusSessionValid: true);
            }

            $courseKey = $request->validated('course_key');
            $courseFiles = is_string($courseKey) && $courseKey !== ''
                ? PortalArchivedFile::query()->forOwner($ownerHash)->where('course_key', $courseKey)->orderBy('meeting_number')->get()
                : collect();

            $notebook = Notebook::query()->create([
                'owner_hash' => $ownerHash,
                'kind' => Notebook::KIND_CUSTOM,
                'title' => trim((string) $request->validated('title')),
                'course_key' => $courseFiles->isNotEmpty() ? $courseKey : null,
                'course_name' => $courseFiles->pluck('course_name')->filter()->first(),
            ]);

            $limit = $notebook->sourceLimit();

            foreach ($courseFiles->take($limit) as $file) {
                $this->library->addArchivedFile($notebook, $file);
            }

            $message = $courseFiles->count() > $limit
                ? "Notebook dibuat dengan {$limit} dari {$courseFiles->count()} berkas materi (batas {$limit} sumber per notebook)."
                : 'Notebook dibuat.';

            return $this->statusSuccess($message, [
                'data' => ['notebook' => $notebook->loadCount(['sources', 'messages', 'meetingSources as meetings_count'])->toApiArray()],
            ], campusSessionValid: true, statusCode: 201);
        });
    }

    /**
     * Isi notebook: sumber, percakapan, hasil studio, dan (notebook mata kuliah) daftar pertemuannya.
     *
     * Berkas yang masuk sendiri ke notebook mata kuliah (`pending`, `waiting`) mulai dibaca saat notebook dibuka, beberapa sekaligus.
     * `messages` memuat 200 pesan terbaru ditambah semua catatan tersimpan dan hasil studio; percakapan dimulai dari `chat_from_id`
     * (pesan chat yang lebih lama hanya ada karena tersimpan di Catatan).
     */
    #[PathParameter('notebook', 'Id notebook.', type: 'integer', example: 3)]
    #[Response(description: 'Detail notebook.', type: "array{status: 'success', message: string, data: array{notebook: ".self::NOTEBOOK_ITEM.', sources: list<'.self::SOURCE_ITEM.'>, messages: list<'.self::MESSAGE_ITEM.'>, chat_from_id: int|null, meetings: '.self::MEETINGS_ITEM.'|null, archived_courses: list<array<string, mixed>>, source_limit: int, ai_available: bool, ai_status: '.self::AI_STATUS.', studio_kinds: array<string, string>}, meta: array{timestamp: string, campus_session_valid: true}}')]
    #[Response(404, description: 'Notebook tidak ditemukan.', type: CampusApiController::SCRAMBLE_NOT_FOUND_RESPONSE)]
    public function show(int $notebook): JsonResponse
    {
        return $this->withNotebook($notebook, 'Notebook read failed', 'Gagal memuat notebook', function (Notebook $owned): JsonResponse {
            // Course files that joined the notebook on their own are read now that the student opened it.
            $this->library->readWaitingSources($owned);

            return $this->statusSuccess('Notebook retrieved', [
                'data' => $this->notebookPayload($owned),
            ], campusSessionValid: true);
        });
    }

    /**
     * Ganti judul notebook.
     */
    #[PathParameter('notebook', 'Id notebook.', type: 'integer', example: 3)]
    #[Response(description: 'Notebook diperbarui.', type: "array{status: 'success', message: string, data: array{notebook: ".self::NOTEBOOK_ITEM.'}, meta: array{timestamp: string, campus_session_valid: true}}')]
    public function update(UpdateNotebookRequest $request, int $notebook): JsonResponse
    {
        return $this->withNotebook($notebook, 'Notebook update failed', 'Gagal memperbarui notebook', function (Notebook $owned) use ($request): JsonResponse {
            $owned->update(['title' => trim((string) $request->validated('title'))]);

            return $this->statusSuccess('Judul notebook diperbarui.', [
                'data' => ['notebook' => $owned->loadCount(['sources', 'messages', 'meetingSources as meetings_count'])->toApiArray()],
            ], campusSessionValid: true);
        });
    }

    /**
     * Hapus notebook beserta sumber unggahan dan percakapannya. Berkas arsip materi tidak ikut terhapus.
     *
     * Notebook mata kuliah tidak dihapus, hanya dikosongkan (`emptied: true`): ia dibuat otomatis selama mata kuliahnya ada di daftar kelas.
     */
    #[PathParameter('notebook', 'Id notebook.', type: 'integer', example: 3)]
    #[Response(description: 'Notebook dihapus atau dikosongkan.', type: "array{status: 'success', message: string, data: array{emptied: bool}, meta: array{timestamp: string, campus_session_valid: true}}")]
    public function destroy(int $notebook): JsonResponse
    {
        return $this->withNotebook($notebook, 'Notebook delete failed', 'Gagal menghapus notebook', function (Notebook $owned): JsonResponse {
            if ($owned->isCourseNotebook()) {
                $this->library->clearNotebook($owned);

                return $this->statusSuccess('Isi notebook mata kuliah dikosongkan.', ['data' => ['emptied' => true]], campusSessionValid: true);
            }

            $this->library->deleteNotebook($owned);

            return $this->statusSuccess('Notebook dihapus.', ['data' => ['emptied' => false]], campusSessionValid: true);
        });
    }

    /**
     * Tambah sumber: berkas arsip materi (`archived_file`), unggahan (`upload`, multipart), atau catatan (`text`).
     *
     * Berkas dibaca di background (`status` pending → ready). PDF dan gambar dibaca oleh model AI sekali saja.
     * Satu notebook membaca beberapa berkas sekaligus (`notebook.limits.queued_readings`); sisanya menunggu giliran
     * (`waiting`) dan mulai dibaca sendiri begitu pembacaan sebelumnya selesai.
     */
    #[PathParameter('notebook', 'Id notebook.', type: 'integer', example: 3)]
    #[Response(201, description: 'Sumber ditambahkan.', type: "array{status: 'success', message: string, data: array{sources: list<".self::SOURCE_ITEM.'>}, meta: array{timestamp: string, campus_session_valid: true}}')]
    #[Response(422, description: 'Data tidak valid atau batas sumber tercapai.', type: CampusApiController::SCRAMBLE_VALIDATION_ERROR_RESPONSE)]
    public function storeSource(StoreNotebookSourceRequest $request, int $notebook): JsonResponse
    {
        return $this->withNotebook($notebook, 'Notebook source add failed', 'Gagal menambah sumber', function (Notebook $owned) use ($request): JsonResponse {
            $room = $owned->sourceRoom();
            $type = (string) $request->validated('type');

            if ($room <= 0) {
                return $this->statusError('Notebook ini sudah mencapai batas jumlah sumber.', 422, campusSessionValid: true);
            }

            $files = $type === NotebookSource::KIND_ARCHIVED_FILE
                ? PortalArchivedFile::query()->forOwner($owned->owner_hash)->whereKey($request->archivedFileIds())->orderBy('id')->get()
                : collect();
            $newFiles = $files->reject(fn (PortalArchivedFile $file): bool => $owned->sources()
                ->where(static fn (Builder $query): Builder => $query->where('portal_archived_file_id', $file->id)->orWhere('archived_source_url', $file->source_url))
                ->exists())->count();

            if ($newFiles > $room) {
                return $this->statusError("Notebook ini hanya bisa menambah {$room} sumber lagi. Pilih paling banyak {$room} berkas, atau hapus sumber yang tidak perlu dulu.", 422, campusSessionValid: true);
            }

            $sources = match ($type) {
                NotebookSource::KIND_ARCHIVED_FILE => $files
                    ->map(fn (PortalArchivedFile $file): ?NotebookSource => $this->library->addArchivedFile($owned, $file))
                    ->filter()
                    ->values()
                    ->all(),
                NotebookSource::KIND_UPLOAD => [$this->library->addUpload($owned, $request->file('file'))],
                default => [$this->library->addText($owned, (string) $request->validated('title'), (string) $request->validated('content'))],
            };

            if ($sources === []) {
                return $this->statusError('Berkas materi tidak ditemukan di arsipmu.', 422, campusSessionValid: true);
            }

            $owned->touch();
            $sources = array_map(static fn (NotebookSource $source): NotebookSource => $source->refresh(), $sources);
            $waiting = count(array_filter($sources, static fn (NotebookSource $source): bool => $source->isWaiting()));

            return $this->statusSuccess($waiting > 0 ? "Sumber ditambahkan. {$waiting} berkas menunggu giliran dibaca dan menyusul otomatis." : 'Sumber ditambahkan.', [
                'data' => ['sources' => array_map(static fn (NotebookSource $source): array => $source->toApiArray(), $sources)],
            ], campusSessionValid: true, statusCode: 201);
        });
    }

    /**
     * Baca ulang sumber yang gagal dibaca, atau yang macet (`stalled`) karena pembacaannya terhenti.
     *
     * Berkas yang masih menunggu giliran (`waiting`) langsung dibaca dengan kuota baca harian mahasiswa.
     */
    #[PathParameter('notebook', 'Id notebook.', type: 'integer', example: 3)]
    #[PathParameter('source', 'Id sumber.', type: 'integer', example: 11)]
    #[Response(description: 'Sumber dibaca ulang.', type: "array{status: 'success', message: string, data: array{source: ".self::SOURCE_ITEM.'}, meta: array{timestamp: string, campus_session_valid: true}}')]
    public function retrySource(int $notebook, int $source): JsonResponse
    {
        return $this->withSource($notebook, $source, 'Notebook source retry failed', 'Gagal membaca ulang sumber', function (Notebook $owned, NotebookSource $ownedSource): JsonResponse {
            $this->library->retry($ownedSource);

            return $this->statusSuccess('Sumber dibaca ulang.', [
                'data' => ['source' => $ownedSource->refresh()->toApiArray()],
            ], campusSessionValid: true);
        });
    }

    /**
     * Hapus satu sumber dari notebook.
     *
     * Dari notebook mata kuliah, berkas atau pertemuan yang dihapus tidak dimasukkan lagi secara otomatis;
     * menambahkannya sendiri (dari arsip atau Tambah pertemuan) memasukkannya kembali.
     */
    #[PathParameter('notebook', 'Id notebook.', type: 'integer', example: 3)]
    #[PathParameter('source', 'Id sumber.', type: 'integer', example: 11)]
    #[Response(description: 'Sumber dihapus.', type: "array{status: 'success', message: string, meta: array{timestamp: string, campus_session_valid: true}}")]
    public function destroySource(int $notebook, int $source): JsonResponse
    {
        return $this->withSource($notebook, $source, 'Notebook source delete failed', 'Gagal menghapus sumber', function (Notebook $owned, NotebookSource $ownedSource): JsonResponse {
            $this->library->delete($ownedSource);

            return $this->statusSuccess('Sumber dihapus.', campusSessionValid: true);
        });
    }

    /**
     * Tanya sumber-sumber notebook. Jawaban memuat rujukan `[n]` yang dijelaskan di `citations`.
     *
     * `source_ids` membatasi konteks ke sumber pilihan (mis. satu pertemuan). `context` pada jawaban mencatat sumber dan jumlah teks yang dibaca model.
     */
    #[PathParameter('notebook', 'Id notebook.', type: 'integer', example: 3)]
    #[Response(201, description: 'Pertanyaan dijawab.', type: "array{status: 'success', message: string, data: array{question: ".self::MESSAGE_ITEM.', answer: '.self::MESSAGE_ITEM.'}, meta: array{timestamp: string, campus_session_valid: true}}')]
    #[Response(422, description: 'Belum ada sumber yang siap, atau pertanyaan tidak valid.', type: CampusApiController::SCRAMBLE_STATUS_ERROR_RESPONSE)]
    #[Response(429, description: 'Batas harian tercapai.', type: CampusApiController::SCRAMBLE_STATUS_ERROR_RESPONSE)]
    #[Response(503, description: 'Model AI belum aktif, salah diatur di server, atau tidak bisa dihubungi. Bila sebabnya pengaturan server, `data.ai_status` menyebutnya.', type: self::AI_ERROR_RESPONSE)]
    public function ask(AskNotebookRequest $request, int $notebook): JsonResponse
    {
        return $this->withNotebook($notebook, 'Notebook question failed', 'Gagal menjawab pertanyaan', function (Notebook $owned) use ($request): JsonResponse {
            $exchange = $this->assistant->ask($owned, $request->question(), $request->sourceIds());

            return $this->statusSuccess('Pertanyaan dijawab.', [
                'data' => [
                    'question' => $exchange['question']->toApiArray(),
                    'answer' => $exchange['answer']->toApiArray(),
                ],
            ], campusSessionValid: true, statusCode: 201);
        });
    }

    /**
     * Buat hasil studio: ringkasan, panduan belajar, kerangka tugas, FAQ, glosarium, kuis, flashcard, atau peta konsep.
     *
     * Dari semua sumber, atau hanya `source_ids` pilihan. `focus` (instruksi tugas) mengarahkan `assignment_plan`: kerangka, poin per bagian,
     * konsep kunci bersitasi, langkah kerja, dan checklist, bukan jawaban siap kumpul. Hasil otomatis tersimpan di Catatan (`pinned: true`).
     */
    #[PathParameter('notebook', 'Id notebook.', type: 'integer', example: 3)]
    #[Response(201, description: 'Hasil dibuat.', type: "array{status: 'success', message: string, data: array{message: ".self::MESSAGE_ITEM.'}, meta: array{timestamp: string, campus_session_valid: true}}')]
    #[Response(429, description: 'Batas harian tercapai.', type: CampusApiController::SCRAMBLE_STATUS_ERROR_RESPONSE)]
    #[Response(503, description: 'Model AI belum aktif, salah diatur di server, atau tidak bisa dihubungi. Bila sebabnya pengaturan server, `data.ai_status` menyebutnya.', type: self::AI_ERROR_RESPONSE)]
    public function studio(NotebookStudioRequest $request, int $notebook): JsonResponse
    {
        return $this->withNotebook($notebook, 'Notebook studio failed', 'Gagal membuat hasil studio', function (Notebook $owned) use ($request): JsonResponse {
            $message = $this->assistant->studio($owned, $request->kind(), $request->sourceIds(), $request->focus());

            return $this->statusSuccess(NotebookAssistant::STUDIO_KINDS[$request->kind()].' dibuat.', [
                'data' => ['message' => $message->toApiArray()],
            ], campusSessionValid: true, statusCode: 201);
        });
    }

    /**
     * Simpan atau lepas pesan dari Catatan.
     */
    #[PathParameter('notebook', 'Id notebook.', type: 'integer', example: 3)]
    #[PathParameter('message', 'Id pesan.', type: 'integer', example: 40)]
    #[Response(description: 'Pesan diperbarui.', type: "array{status: 'success', message: string, data: array{message: ".self::MESSAGE_ITEM.'}, meta: array{timestamp: string, campus_session_valid: true}}')]
    public function updateMessage(UpdateNotebookMessageRequest $request, int $notebook, int $message): JsonResponse
    {
        return $this->withMessage($notebook, $message, 'Notebook message update failed', 'Gagal memperbarui catatan', function (NotebookMessage $owned) use ($request): JsonResponse {
            $owned->update(['pinned' => $request->boolean('pinned')]);

            return $this->statusSuccess($owned->pinned ? 'Disimpan ke Catatan.' : 'Dilepas dari Catatan.', [
                'data' => ['message' => $owned->toApiArray()],
            ], campusSessionValid: true);
        });
    }

    /**
     * Hapus satu pesan atau hasil studio.
     */
    #[PathParameter('notebook', 'Id notebook.', type: 'integer', example: 3)]
    #[PathParameter('message', 'Id pesan.', type: 'integer', example: 40)]
    #[Response(description: 'Pesan dihapus.', type: "array{status: 'success', message: string, meta: array{timestamp: string, campus_session_valid: true}}")]
    public function destroyMessage(int $notebook, int $message): JsonResponse
    {
        return $this->withMessage($notebook, $message, 'Notebook message delete failed', 'Gagal menghapus pesan', function (NotebookMessage $owned): JsonResponse {
            $owned->delete();

            return $this->statusSuccess('Pesan dihapus.', campusSessionValid: true);
        });
    }

    /**
     * The archived course files and the course's meetings come along for the
     * "add source" pickers.
     *
     * @return array<string, mixed>
     */
    private function notebookPayload(Notebook $notebook): array
    {
        $latest = $notebook->messages()->latest('id')->limit(200)->get();

        return [
            'notebook' => $notebook->loadCount(['sources', 'messages', 'meetingSources as meetings_count'])->toApiArray(),
            'sources' => $notebook->sources()->orderByRaw('meeting_number is null, meeting_number')->orderBy('id')->get()->map(static fn (NotebookSource $source): array => $source->toApiArray())->all(),
            'messages' => $this->payloadMessages($notebook, $latest),
            'chat_from_id' => $latest->min('id'),
            'meetings' => $notebook->isCourseNotebook() ? $this->courseSync->meetings((string) $this->sessionToken(), $notebook) : null,
            'archived_courses' => $this->archivedFiles->grouped($notebook->owner_hash),
            'source_limit' => $notebook->sourceLimit(),
            'ai_available' => $this->assistant->isAvailable(),
            'ai_status' => $this->assistant->aiStatus(),
            'studio_kinds' => NotebookAssistant::STUDIO_KINDS,
        ];
    }

    /**
     * The latest messages, plus every saved note and studio output however
     * old: the Notes panel is built from this list. The chat shows only the
     * latest ones (from `chat_from_id`), so an old saved answer does not
     * appear there without its question.
     *
     * @param  Collection<int, NotebookMessage>  $latest
     * @return list<array<string, mixed>>
     */
    private function payloadMessages(Notebook $notebook, Collection $latest): array
    {
        $kept = $notebook->messages()
            ->whereKeyNot($latest->modelKeys())
            ->where(static fn (Builder $query): Builder => $query->where('pinned', true)->orWhere('kind', '!=', NotebookMessage::KIND_CHAT))
            ->get();

        return $latest->concat($kept)
            ->sortBy('id')
            ->values()
            ->map(static fn (NotebookMessage $message): array => $message->toApiArray())
            ->all();
    }

    /**
     * @param  callable(Notebook): JsonResponse  $callback
     */
    private function withNotebook(int $notebookId, string $failureLogMessage, string $clientErrorPrefix, callable $callback): JsonResponse
    {
        return $this->withCampusSession($failureLogMessage, $clientErrorPrefix, function (string $sessionToken) use ($notebookId, $callback): JsonResponse {
            $notebook = Notebook::query()->forOwner($this->owner->key($sessionToken))->whereKey($notebookId)->first();

            if ($notebook === null) {
                return $this->statusError('Notebook tidak ditemukan.', 404, campusSessionValid: true);
            }

            try {
                return $callback($notebook);
            } catch (NotebookAiException $exception) {
                $status = in_array($exception->getCode(), [422, 429, 502, 503], true) ? $exception->getCode() : 503;

                // The AI is off or set up wrong: the page swaps its banner for the right one.
                return $this->statusError($exception->getMessage(), $status, $exception->concernsConfiguration()
                    ? ['data' => ['ai_status' => $this->assistant->aiStatus()]]
                    : [], campusSessionValid: true);
            }
        }, ['notebook_id' => $notebookId]);
    }

    /**
     * @param  callable(Notebook, NotebookSource): JsonResponse  $callback
     */
    private function withSource(int $notebookId, int $sourceId, string $failureLogMessage, string $clientErrorPrefix, callable $callback): JsonResponse
    {
        return $this->withNotebook($notebookId, $failureLogMessage, $clientErrorPrefix, function (Notebook $notebook) use ($sourceId, $callback): JsonResponse {
            $source = $notebook->sources()->whereKey($sourceId)->first();

            return $source === null
                ? $this->statusError('Sumber tidak ditemukan.', 404, campusSessionValid: true)
                : $callback($notebook, $source);
        });
    }

    /**
     * @param  callable(NotebookMessage): JsonResponse  $callback
     */
    private function withMessage(int $notebookId, int $messageId, string $failureLogMessage, string $clientErrorPrefix, callable $callback): JsonResponse
    {
        return $this->withNotebook($notebookId, $failureLogMessage, $clientErrorPrefix, function (Notebook $notebook) use ($messageId, $callback): JsonResponse {
            $message = $notebook->messages()->whereKey($messageId)->first();

            return $message === null
                ? $this->statusError('Pesan tidak ditemukan.', 404, campusSessionValid: true)
                : $callback($message);
        });
    }
}
