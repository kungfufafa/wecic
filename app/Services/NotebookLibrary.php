<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\NotebookAiException;
use App\Exceptions\NotebookSourceException;
use App\Jobs\IngestNotebookSourceJob;
use App\Models\Notebook;
use App\Models\NotebookChunk;
use App\Models\NotebookDismissal;
use App\Models\NotebookMessage;
use App\Models\NotebookSource;
use App\Models\PortalArchivedFile;
use App\Services\Ai\NotebookAiClient;
use App\Support\Ai\AiCapabilities;
use App\Support\Ai\AiRequest;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The sources of a notebook: adding them, reading their text once
 * (IngestNotebookSourceJob) and keeping it as citable chunks.
 *
 * A notebook keeps only a few readings queued at a time
 * (`notebook.limits.queued_readings`). A source the student added takes the
 * next free slot, and the rest of a bulk pick follow as readings finish. A
 * course file that joined a course notebook on its own (a meeting read by a
 * background sync, a file archived later) waits until the student opens
 * the notebook, so nothing is sent to the model that nobody asked for, and
 * is then read a few at a time while it is open (one that needs the model,
 * only while the AI can be used). Reading with the model
 * counts against a daily budget per student
 * (`notebook.limits.daily_source_reads`), part of which is kept for the
 * files the student adds; a course file another notebook has read already
 * is reused instead.
 *
 * Nothing goes to the model before the student agreed to it
 * (NotebookAiConsents): without that consent a file that needs the model
 * waits (or, when the student asked for it, fails saying so) and no source
 * gets a model summary; files read locally are still read.
 */
class NotebookLibrary
{
    private const SUMMARY_PROMPT = <<<'PROMPT'
        Tulis ringkasan sumber ini dalam 2–3 kalimat bahasa Indonesia, lalu satu baris "Topik: " berisi 3–6 topik utama dipisah koma.
        Jangan memakai Markdown.
        PROMPT;

    /**
     * The source guide (summary, topics, understanding questions) for a
     * model with structured output (GUIDE_SCHEMA). The questions help a
     * student understand the source; they never ask for a task to be done.
     */
    private const GUIDE_PROMPT = <<<'PROMPT'
        Teks di atas bisa berupa cuplikan awal, tengah, dan akhir sebuah sumber kuliah (bagian yang dilewati ditandai […]). Isi:
        - summary: ringkasan sumber dalam 2–3 kalimat bahasa Indonesia, tanpa Markdown.
        - topics: 3–6 topik utama sumber, masing-masing 1–4 kata.
        - questions: tepat 3 pertanyaan pemahaman yang bisa diajukan mahasiswa untuk memahami sumber ini, misalnya "Mengapa …?", "Apa beda … dan …?", atau "Bagaimana contoh …?". Setiap pertanyaan diakhiri tanda tanya.
        Pertanyaan itu untuk memahami materi, bukan perintah mengerjakan tugas: jangan memakai kata seperti "kerjakan", "buatkan", "jawabkan", atau "selesaikan", dan jangan meminta jawaban soal atau tugas.
        PROMPT;

    /**
     * Lowercase JSON Schema with an object root, as every provider's
     * structured output takes it. The counts are held by the prompt and
     * cleanTopics()/cleanQuestions(): Anthropic's structured outputs take
     * no item counts beyond 0 or 1.
     */
    private const GUIDE_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'summary' => ['type' => 'string', 'description' => 'Ringkasan 2–3 kalimat.'],
            'topics' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => '3–6 topik utama.'],
            'questions' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => '3 pertanyaan pemahaman.'],
        ],
        'required' => ['summary', 'topics', 'questions'],
        'additionalProperties' => false,
    ];

    private const SUMMARY_INPUT_CHARS = 12000;

    /**
     * Between the parts of a long source the summary reads (summaryInput()).
     */
    private const SUMMARY_GAP = "\n\n[…]\n\n";

    /**
     * A source read without the model, or whose summary failed.
     */
    private const NO_GUIDE = ['summary' => null, 'topics' => null, 'suggested_questions' => null];

    private const MAX_TOPICS = 6;

    private const MAX_QUESTIONS = 3;

    private const MAX_TOPIC_CHARS = 60;

    private const MAX_QUESTION_CHARS = 200;

    /**
     * A question that asks the model to do the student's work rather than
     * to explain (dropped from the guide whatever the prompt said).
     */
    /**
     * A question that asks for the student's work to be done: the suffixed
     * imperatives on their own, and the plain ones ("tulis", "buat", "susun",
     * "jawab", "isi", "beri") followed within two words by a piece of work.
     */
    private const DO_MY_TASK_PATTERN = '/\b(kerja(kan|in)|buat(kan|in)|jawab(kan|in|lah)|selesai(kan|in)|contekan|kunci\s+jawaban|jawaban\s+lengkap|(tulis|buat|susun|jawab|isi|beri|berikan)(kan|in|lah)?\s+(\p{L}+\s+){0,2}(jawaban|esai|essay|makalah|laporan|soal|tugas|kuis|ujian|lembar|skripsi|paper|artikel))\b/iu';

    private const SUMMARY_MAX_CHARS = 1000;

    private const TRUNCATED_SUMMARY_NOTE = '(Ringkasan terpotong karena terlalu panjang.)';

    /**
     * Source kinds read from a file, which wait for a reading slot.
     */
    private const READ_KINDS = [NotebookSource::KIND_ARCHIVED_FILE, NotebookSource::KIND_UPLOAD];

    private const WAITING_FOR_READS = 'Kuota baca AI untuk berkas yang masuk sendiri hari ini sudah terpakai. Berkas ini dibaca otomatis saat notebook dibuka lagi besok, atau ketuk "Baca sekarang".';

    /**
     * A file that joined on its own and needs the model while the AI cannot
     * be used (no key, or a key or model setting the API rejected lately).
     */
    private const WAITING_FOR_AI = 'Berkas ini perlu dibaca AI, yang sedang tidak bisa dipakai. Berkas ini dibaca otomatis saat notebook dibuka setelah AI aktif lagi.';

    /**
     * A file that needs the model while the student has not agreed to send
     * Notebook content to the provider that reads files (`%s`), or
     * withdrew that consent.
     */
    private const WAITING_FOR_CONSENT = 'Berkas ini perlu dibaca AI (%s). Setujui pengiriman data ke AI di Notebook dulu; berkas ini dibaca otomatis saat notebook dibuka lagi.';

    private const NEEDS_CONSENT = 'Berkas ini perlu dibaca AI (%s). Setujui pengiriman data ke AI di Notebook dulu, lalu ketuk "Baca ulang".';

    public function __construct(
        private readonly NotebookSourceReader $reader,
        private readonly PortalArchivedFileStore $archivedFiles,
        private readonly NotebookAiClient $ai,
        private readonly NotebookAiConsents $aiConsents
    ) {}

    /**
     * An archived course file becomes a source once per notebook; a source
     * whose archive row was deleted and archived again is linked again.
     *
     * `$byStudent` is false on the course-notebook paths that add files on
     * their own: those skip files the student removed from the notebook and
     * leave the reading for when the notebook is opened. A file the student
     * picked is queued when a reading slot is free and waits for one
     * otherwise (queueRequestedReadings()). A file the student picks that
     * is already in the notebook, waiting as one that joined on its own,
     * becomes theirs: it no longer waits for that share. Returns null when
     * the file is skipped or the notebook is full.
     */
    public function addArchivedFile(Notebook $notebook, PortalArchivedFile $file, bool $byStudent = true): ?NotebookSource
    {
        $existing = $notebook->sources()->where('portal_archived_file_id', $file->id)->first()
            ?? $notebook->sources()->where('kind', NotebookSource::KIND_ARCHIVED_FILE)->where('archived_source_url', $file->source_url)->first();

        if ($byStudent) {
            $notebook->dismissals()->where('kind', NotebookDismissal::KIND_ARCHIVED_FILE)->where('reference', $file->source_url)->delete();
        }

        if ($existing !== null) {
            if ($existing->portal_archived_file_id !== $file->id || $existing->archived_source_url !== $file->source_url) {
                $existing->forceFill(['portal_archived_file_id' => $file->id, 'archived_source_url' => $file->source_url])->save();
            }

            if ($byStudent && $existing->isWaiting()) {
                $existing->forceFill(['requested_at' => $existing->requested_at ?? now(), 'error' => null])->save();
                $this->queueRequestedReadings($notebook);

                return $existing->refresh();
            }

            return $existing;
        }

        if ((! $byStudent && $notebook->isDismissed(NotebookDismissal::KIND_ARCHIVED_FILE, $file->source_url)) || $notebook->sourceRoom() < 1) {
            return null;
        }

        $title = trim(implode(' · ', array_filter([
            $file->meeting_number !== null ? 'Pertemuan '.$file->meeting_number : null,
            $file->title ?? $file->filename,
        ])));

        $source = $notebook->sources()->create([
            'kind' => NotebookSource::KIND_ARCHIVED_FILE,
            'portal_archived_file_id' => $file->id,
            'archived_source_url' => $file->source_url,
            'meeting_id' => $file->meeting_id,
            'meeting_number' => $file->meeting_number,
            'title' => mb_substr($title !== '' ? $title : $file->filename, 0, 255),
            'mime' => $file->mime,
            'status' => NotebookSource::STATUS_PENDING,
            'requested_at' => $byStudent ? now() : null,
        ]);

        if ($byStudent) {
            $this->queueRequestedReadings($notebook);
        }

        return $source->refresh();
    }

    /**
     * An uploaded file, queued for reading when a reading slot is free.
     */
    public function addUpload(Notebook $notebook, UploadedFile $file): NotebookSource
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: (string) $file->extension());
        $path = $file->storeAs('notebook-uploads/'.$notebook->id, Str::uuid()->toString().'.'.$extension, $this->diskName());

        if ($path === false) {
            throw new NotebookSourceException('Berkas gagal disimpan.');
        }

        $source = $notebook->sources()->create([
            'kind' => NotebookSource::KIND_UPLOAD,
            'title' => mb_substr(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME) ?: 'Berkas unggahan', 0, 255),
            'mime' => $file->getMimeType() ?: $file->getClientMimeType(),
            'disk_path' => $path,
            'status' => NotebookSource::STATUS_PENDING,
            'requested_at' => now(),
        ]);
        $this->queueRequestedReadings($notebook);

        return $source->refresh();
    }

    /**
     * Pasted text needs no reading, so it is indexed right away.
     */
    public function addText(Notebook $notebook, string $title, string $content): NotebookSource
    {
        $source = $notebook->sources()->create([
            'kind' => NotebookSource::KIND_TEXT,
            'title' => mb_substr(trim($title) !== '' ? trim($title) : 'Catatan', 0, 255),
            'mime' => 'text/plain',
            'status' => NotebookSource::STATUS_PROCESSING,
            'requested_at' => now(),
        ]);

        $this->index($source, [['page' => null, 'text' => $content]], false, summarize: $this->modelUsableFor($notebook->owner_hash) && $this->spendRead($notebook->owner_hash));

        return $source->refresh();
    }

    /**
     * "Jadikan sumber": the student's own note becomes a pasted-text source
     * (addText(): the same source limit is checked by the caller, and the
     * summary is written only with consent and a read left today). Its
     * title is the note's first line; the source is recorded on the note.
     */
    public function addNote(Notebook $notebook, NotebookMessage $note): NotebookSource
    {
        $source = $this->addText($notebook, $this->noteTitle($note->content), $note->content);

        $note->update(['context' => [
            ...($note->context ?? []),
            'note_source' => ['id' => $source->id, 'title' => $source->title, 'at' => now()->toISOString()],
        ]]);

        return $source;
    }

    /**
     * The note's first line without its Markdown marks, else "Catatan" and its date.
     */
    private function noteTitle(string $content): string
    {
        foreach (preg_split('/\R/u', $content) ?: [] as $line) {
            $title = trim((string) preg_replace('/^\s{0,3}(?:#{1,6}\s+|[-*+]\s+|\d+[.)]\s+|>\s*)|[*_`]+/u', '', $line));

            if ($title !== '') {
                return Str::limit($title, 117, '…');
            }
        }

        return 'Catatan '.now()->format('d-m-Y');
    }

    /**
     * The note of one meeting of a course notebook, written once per meeting
     * and rewritten when the meeting is added again (the portal may have
     * gained an assignment or a deadline since). Short and structured, so
     * it needs no model summary.
     */
    public function addMeetingNote(Notebook $notebook, string $meetingId, ?int $meetingNumber, string $title, string $content): NotebookSource
    {
        $source = $notebook->sources()
            ->where('kind', NotebookSource::KIND_MEETING)
            ->where('meeting_id', $meetingId)
            ->first() ?? $notebook->sources()->make(['kind' => NotebookSource::KIND_MEETING, 'meeting_id' => $meetingId]);
        $title = mb_substr(trim($title) !== '' ? trim($title) : 'Pertemuan', 0, 255);

        // Every re-read of the meeting lands here; an unchanged note keeps its chunks.
        if ($source->exists && $source->status === NotebookSource::STATUS_READY
            && $source->chunks()->orderBy('position')->pluck('content')->all() === $this->split($content, $this->chunkChars())) {
            $source->fill(['meeting_number' => $meetingNumber, 'title' => $title]);

            if ($source->isDirty()) {
                $source->save();
            }

            return $source;
        }

        $source->fill([
            'meeting_number' => $meetingNumber,
            'title' => $title,
            'mime' => 'text/plain',
            'status' => NotebookSource::STATUS_PROCESSING,
        ])->save();

        $this->index($source, [['page' => null, 'text' => $content]], false, summarize: false);

        return $source->refresh();
    }

    /**
     * Read the source's text and store it as chunks. A failure is kept on
     * the source with a message the student can act on.
     *
     * `$automatic` marks a course file that joined its notebook on its own:
     * it leaves the reserved part of the daily budget alone, and when the
     * rest is spent, or the AI cannot be used, it waits to be queued again
     * instead of failing. A source the student asked for is never read that
     * way. A reading the model never did because the AI settings were
     * rejected gives its read back. A file the reading model cannot take
     * (a scanned PDF or an image for a model without PDF or image input)
     * fails at once, whichever way it came in. The model that reads files
     * is NotebookSourceReader's (`notebook.ai.ingest_provider`).
     */
    public function ingest(NotebookSource $source, bool $automatic = false): void
    {
        $automatic = $automatic && ! $source->isRequested();
        $source->forceFill(['status' => NotebookSource::STATUS_PROCESSING, 'error' => null])->save();
        $ownerHash = (string) $source->notebook?->owner_hash;
        $spentRead = false;

        try {
            if ($this->reuseReading($source)) {
                return;
            }

            [$contents, $filename] = $this->contents($source);
            $mime = (string) $source->mime;
            $unreadable = $this->reader->unreadable($contents, $mime, $filename);

            if ($unreadable !== null) {
                throw new NotebookSourceException($unreadable);
            }

            $byModel = $this->reader->needsModel($mime, $filename, $contents);
            $consented = $this->aiConsents->granted($ownerHash);

            if ($byModel && ! $consented) {
                if (! $automatic) {
                    throw new NotebookSourceException(sprintf(self::NEEDS_CONSENT, $this->reader->modelLabel()));
                }

                $this->waitForTurn($source, sprintf(self::WAITING_FOR_CONSENT, $this->reader->modelLabel()));

                return;
            }

            if ($byModel && $automatic && ! $this->reader->modelIsUsable()) {
                $this->waitForTurn($source, self::WAITING_FOR_AI);

                return;
            }

            if ($byModel && $this->reader->modelIsUsable()) {
                $spentRead = $this->spendRead($ownerHash, $automatic);

                if (! $spentRead) {
                    if (! $automatic) {
                        throw new NotebookSourceException('Kuota baca berkas dengan AI hari ini sudah habis. Ketuk "Baca ulang" besok.');
                    }

                    $this->waitForTurn($source, self::WAITING_FOR_READS);

                    return;
                }
            }

            $read = $this->reader->read($contents, $mime, $filename);

            // A transcribed file's summary is part of the reading it paid for.
            $this->index($source, $read['pages'], $read['truncated'], summarize: $consented && $this->ai->isUsable() && ($byModel || $this->spendRead($ownerHash, $automatic)));
        } catch (\Throwable $throwable) {
            $unusable = $throwable instanceof NotebookAiException && $throwable->concernsConfiguration();

            if ($spentRead && $unusable) {
                RateLimiter::decrement($this->readsKey($ownerHash), 86400);
            }

            if ($automatic && $unusable) {
                $this->waitForTurn($source, self::WAITING_FOR_AI);

                return;
            }

            Log::warning('Notebook source could not be read', [
                'source_id' => $source->id,
                'error' => $throwable->getMessage(),
            ]);

            $source->forceFill([
                'status' => NotebookSource::STATUS_FAILED,
                'error' => mb_substr($this->failureMessage($throwable), 0, 255),
            ])->save();
        }
    }

    /**
     * Queue the next readings of the sources that wait for a reading slot:
     * first the files the student asked for (queueRequestedReadings()),
     * then the ones that joined on their own and wait for the notebook to
     * be opened. Only a few are queued at a time
     * (`notebook.limits.queued_readings`); the rest follow as those finish,
     * whenever the notebook is loaded again (the open page refreshes itself
     * while sources are being read). A file that joined on its own and
     * needs the model is queued only while the AI can be used and the share
     * of the daily budget for such files lasts (otherwise the file says why
     * and waits: for the AI to work again, or for the next day); a file
     * read locally or already read by another notebook costs nothing.
     * Returns how many were queued.
     */
    public function readWaitingSources(Notebook $notebook): int
    {
        $requested = $this->queueRequestedReadings($notebook);
        $slots = $this->readingSlots($notebook);

        if ($slots <= 0) {
            return $requested;
        }

        $waiting = $notebook->sources()
            ->where('status', NotebookSource::STATUS_PENDING)
            ->whereNull('queued_at')
            ->whereNull('requested_at')
            ->whereIn('kind', self::READ_KINDS)
            ->with('archivedFile')
            ->orderBy('id')
            ->get();
        $readElsewhere = $this->hashesReadElsewhere($waiting);
        $usable = $this->reader->modelIsUsable();
        $consented = $this->aiConsents->granted($notebook->owner_hash);
        $modelReads = $usable && $consented ? $this->automaticReadsLeft($notebook->owner_hash) : 0;
        $queued = 0;

        foreach ($waiting as $source) {
            if ($queued >= $slots) {
                break;
            }

            $file = $source->kind === NotebookSource::KIND_ARCHIVED_FILE ? $source->archivedFile : null;
            $free = ($file !== null && isset($readElsewhere[$file->sha256]))
                || ! $this->reader->needsModel((string) $source->mime, $file !== null ? $file->filename : (string) $source->disk_path);

            if (! $free) {
                if ($modelReads <= 0) {
                    // Say why it waits; past the share the student can read it now with their own reads.
                    $this->waitForTurn($source, match (true) {
                        ! $usable => self::WAITING_FOR_AI,
                        ! $consented => sprintf(self::WAITING_FOR_CONSENT, $this->reader->modelLabel()),
                        default => self::WAITING_FOR_READS,
                    });

                    continue;
                }

                $modelReads--;
            }

            $this->queue($source, automatic: true);
            $queued++;
        }

        return $requested + $queued;
    }

    /**
     * Queue the sources the student asked for that wait for a reading slot,
     * oldest first, into the slots the notebook's other readings leave free
     * (`notebook.limits.queued_readings`). Runs whenever the student adds a
     * source and whenever a reading finishes or is removed, so a bulk pick
     * is read a few at a time to the end, whether the page stays open or
     * not. Returns how many were queued.
     */
    public function queueRequestedReadings(Notebook $notebook): int
    {
        $queued = 0;

        // One at a time: on a synchronous queue a reading (and the ones it queues) finishes before the next query.
        while ($this->readingSlots($notebook) > 0) {
            $next = $notebook->sources()
                ->where('status', NotebookSource::STATUS_PENDING)
                ->whereNull('queued_at')
                ->whereNotNull('requested_at')
                ->whereIn('kind', self::READ_KINDS)
                ->orderBy('id')
                ->first();

            if ($next === null) {
                break;
            }

            $this->queue($next);
            $queued++;
        }

        return $queued;
    }

    /**
     * Read a file source again: one that failed, one whose reading job was
     * lost (the worker died), or one that waits to be queued, which the
     * student then reads now with their own reading budget. Returns false
     * when there is nothing to do.
     */
    public function retry(NotebookSource $source): bool
    {
        if ($source->kind === NotebookSource::KIND_TEXT || $source->kind === NotebookSource::KIND_MEETING) {
            return false;
        }

        $stalled = $source->isStalled();

        if ($source->status !== NotebookSource::STATUS_FAILED && ! $stalled && ! $source->isWaiting()) {
            return false;
        }

        if ($stalled) {
            IngestNotebookSourceJob::releaseUniqueLock($source->id);
        }

        // Asked for by the student now: read with the student's own reads, without waiting for a slot.
        $source->forceFill(['status' => NotebookSource::STATUS_PENDING, 'error' => null, 'requested_at' => $source->requested_at ?? now()])->save();
        $this->queue($source);

        return true;
    }

    /**
     * Remove a source. From a course notebook, the course file or meeting
     * is remembered as removed, so the paths that fill the notebook on
     * their own do not bring it back. A reading it held frees its slot for
     * the next file the student asked for.
     */
    public function delete(NotebookSource $source): void
    {
        $notebook = $source->notebook;
        $this->rememberDismissal($source);

        if ($source->disk_path !== null) {
            $this->disk()->delete($source->disk_path);
        }

        $source->delete();

        if ($notebook !== null) {
            $this->queueRequestedReadings($notebook);
        }
    }

    public function deleteNotebook(Notebook $notebook): void
    {
        $this->disk()->deleteDirectory('notebook-uploads/'.$notebook->id);
        $notebook->delete();
    }

    /**
     * Empty a notebook (sources, uploads, conversation, review cards) but keep it: a
     * course notebook stays as long as the course is in the class list.
     */
    public function clearNotebook(Notebook $notebook): void
    {
        $this->disk()->deleteDirectory('notebook-uploads/'.$notebook->id);
        $notebook->chunks()->delete();
        $notebook->sources()->delete();
        $notebook->messages()->delete();
        $notebook->reviewCards()->delete();
        $notebook->touch();
    }

    /**
     * The model can be used for this student's content: the AI works and
     * they agreed to send Notebook content to the AI provider(s).
     */
    private function modelUsableFor(string $ownerHash): bool
    {
        return $this->ai->isUsable() && $this->aiConsents->granted($ownerHash);
    }

    /**
     * Reading slots the notebook has free: `notebook.limits.queued_readings`
     * less the readings queued or running (a stalled one holds no slot).
     */
    private function readingSlots(Notebook $notebook): int
    {
        $inFlight = $notebook->sources()
            ->where(static fn (Builder $query): Builder => $query
                ->where('status', NotebookSource::STATUS_PROCESSING)
                ->orWhere(static fn (Builder $queued): Builder => $queued->where('status', NotebookSource::STATUS_PENDING)->whereNotNull('queued_at')))
            ->get(['id', 'status', 'queued_at', 'updated_at'])
            ->reject(static fn (NotebookSource $source): bool => $source->isStalled())
            ->count();

        return max(1, (int) config('notebook.limits.queued_readings', 4)) - $inFlight;
    }

    /**
     * Leave a file that joined on its own waiting to be queued (pending, no
     * `queued_at`), with why it waits.
     */
    private function waitForTurn(NotebookSource $source, string $reason): void
    {
        $source->forceFill(['status' => NotebookSource::STATUS_PENDING, 'queued_at' => null, 'error' => $reason])->save();
    }

    private function queue(NotebookSource $source, bool $automatic = false): NotebookSource
    {
        $source->forceFill(['queued_at' => now(), 'error' => null])->save();
        IngestNotebookSourceJob::dispatch($source->id, $automatic);

        return $source->refresh();
    }

    /**
     * Content hashes of the waiting course files that another notebook has
     * read already, so reading them again is free (reuseReading()).
     *
     * @param  Collection<int, NotebookSource>  $waiting
     * @return array<string, int>
     */
    private function hashesReadElsewhere(Collection $waiting): array
    {
        $hashes = $waiting
            ->map(static fn (NotebookSource $source): ?string => $source->kind === NotebookSource::KIND_ARCHIVED_FILE ? $source->archivedFile?->sha256 : null)
            ->filter(static fn (?string $hash): bool => $hash !== null && $hash !== '')
            ->unique()
            ->values()
            ->all();

        if ($hashes === []) {
            return [];
        }

        return PortalArchivedFile::query()
            ->whereIn('sha256', $hashes)
            ->whereIn('id', NotebookSource::query()
                ->select('portal_archived_file_id')
                ->where('kind', NotebookSource::KIND_ARCHIVED_FILE)
                ->where('status', NotebookSource::STATUS_READY)
                ->whereNotNull('portal_archived_file_id'))
            ->pluck('sha256')
            ->unique()
            ->flip()
            ->all();
    }

    /**
     * A course file is the same file for every student who archived it: the
     * text another notebook read from it (same content hash) is copied with
     * its summary and guide, so a file a whole class shares is sent to the
     * model once.
     */
    private function reuseReading(NotebookSource $source): bool
    {
        $file = $source->kind === NotebookSource::KIND_ARCHIVED_FILE ? $source->archivedFile : null;

        if ($file === null || $file->sha256 === '') {
            return false;
        }

        $reader = NotebookSource::query()
            ->where('kind', NotebookSource::KIND_ARCHIVED_FILE)
            ->where('status', NotebookSource::STATUS_READY)
            ->whereKeyNot($source->id)
            ->whereIn('portal_archived_file_id', PortalArchivedFile::query()->select('id')->where('sha256', $file->sha256))
            ->latest('id')
            ->first();
        $chunks = $reader?->chunks()->orderBy('position')->get(['position', 'page', 'content']);

        if ($reader === null || $chunks === null || $chunks->isEmpty()) {
            return false;
        }

        $source->chunks()->delete();
        NotebookChunk::query()->insert($chunks->map(static fn (NotebookChunk $chunk): array => [
            'notebook_source_id' => $source->id,
            'notebook_id' => $source->notebook_id,
            'position' => $chunk->position,
            'page' => $chunk->page,
            'content' => $chunk->content,
        ])->all());

        $source->forceFill([
            'status' => NotebookSource::STATUS_READY,
            'error' => null,
            'char_count' => $reader->char_count,
            'page_count' => $reader->page_count,
            'truncated' => $reader->truncated,
            'summary' => $reader->summary,
            'topics' => $reader->topics,
            'suggested_questions' => $reader->suggested_questions,
        ])->save();

        return true;
    }

    /**
     * One reading with the model (a transcription and its summary, or a
     * summary) from the student's daily budget; false when it is spent. An
     * `$automatic` reading leaves the reserved reads alone.
     */
    private function spendRead(string $ownerHash, bool $automatic = false): bool
    {
        $allowed = $this->dailyReads() - ($automatic ? $this->reservedReads() : 0);

        if ($allowed < 1 || RateLimiter::tooManyAttempts($this->readsKey($ownerHash), $allowed)) {
            return false;
        }

        RateLimiter::hit($this->readsKey($ownerHash), 86400);

        return true;
    }

    /**
     * Model readings left today for files that joined on their own.
     */
    private function automaticReadsLeft(string $ownerHash): int
    {
        return max(0, $this->dailyReads() - $this->reservedReads() - RateLimiter::attempts($this->readsKey($ownerHash)));
    }

    private function readsKey(string $ownerHash): string
    {
        return 'notebook-reads:'.$ownerHash.':'.now()->format('Y-m-d');
    }

    private function dailyReads(): int
    {
        return max(1, (int) config('notebook.limits.daily_source_reads', 60));
    }

    /**
     * Reads kept for the files the student adds: at most half the budget.
     */
    private function reservedReads(): int
    {
        return min(max(0, (int) config('notebook.limits.reserved_source_reads', 10)), intdiv($this->dailyReads(), 2));
    }

    private function rememberDismissal(NotebookSource $source): void
    {
        $notebook = $source->notebook;

        if ($notebook === null || ! $notebook->isCourseNotebook()) {
            return;
        }

        [$kind, $reference] = match ($source->kind) {
            NotebookSource::KIND_ARCHIVED_FILE => [NotebookDismissal::KIND_ARCHIVED_FILE, $source->archived_source_url ?? $source->archivedFile?->source_url],
            NotebookSource::KIND_MEETING => [NotebookDismissal::KIND_MEETING, $source->meeting_id],
            default => [null, null],
        };

        if ($kind !== null && is_string($reference) && $reference !== '') {
            $notebook->dismissals()->firstOrCreate(['kind' => $kind, 'reference' => mb_substr($reference, 0, 512)]);
        }
    }

    /**
     * @param  list<array{page: int|null, text: string}>  $pages
     */
    private function index(NotebookSource $source, array $pages, bool $truncated, bool $summarize = true): void
    {
        $chunkChars = $this->chunkChars();
        $budget = max(1000, (int) config('notebook.limits.max_text_chars', 200000));
        $chunks = [];
        $total = 0;

        foreach ($pages as $page) {
            foreach ($this->split($page['text'], $chunkChars) as $text) {
                if ($total + mb_strlen($text) > $budget) {
                    $truncated = true;

                    break 2;
                }

                $total += mb_strlen($text);
                $chunks[] = ['page' => $page['page'], 'content' => $text];
            }
        }

        if ($chunks === []) {
            $source->forceFill([
                'status' => NotebookSource::STATUS_FAILED,
                'error' => 'Tidak ada teks yang bisa dibaca dari sumber ini.',
            ])->save();

            return;
        }

        $source->chunks()->delete();
        NotebookChunk::query()->insert(array_map(static fn (array $chunk, int $position): array => [
            'notebook_source_id' => $source->id,
            'notebook_id' => $source->notebook_id,
            'position' => $position,
            'page' => $chunk['page'],
            'content' => $chunk['content'],
        ], $chunks, array_keys($chunks)));

        $pageNumbers = array_filter(array_column($chunks, 'page'));

        $source->forceFill([
            'status' => NotebookSource::STATUS_READY,
            'error' => null,
            'char_count' => $total,
            'page_count' => $pageNumbers !== [] ? max($pageNumbers) : null,
            'truncated' => $truncated,
            ...($summarize ? $this->summarize(array_column($chunks, 'content')) : self::NO_GUIDE),
        ])->save();
    }

    private function chunkChars(): int
    {
        return max(400, (int) config('notebook.limits.chunk_chars', 1800));
    }

    /**
     * Paragraph-sized passages of at most `$limit` characters.
     *
     * @return list<string>
     */
    private function split(string $text, int $limit): array
    {
        $chunks = [];
        $current = '';

        foreach (preg_split("/\n\s*\n/u", trim($text)) ?: [] as $paragraph) {
            $paragraph = trim($paragraph);

            if ($paragraph === '') {
                continue;
            }

            foreach (mb_strlen($paragraph) > $limit ? $this->hardSplit($paragraph, $limit) : [$paragraph] as $piece) {
                if ($current !== '' && mb_strlen($current) + mb_strlen($piece) + 2 > $limit) {
                    $chunks[] = $current;
                    $current = '';
                }

                $current = $current === '' ? $piece : $current."\n\n".$piece;
            }
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }

    /**
     * @return list<string>
     */
    private function hardSplit(string $paragraph, int $limit): array
    {
        $sentences = preg_split('/(?<=[.!?])\s+/u', $paragraph) ?: [$paragraph];
        $pieces = [];
        $current = '';

        foreach ($sentences as $sentence) {
            while (mb_strlen($sentence) > $limit) {
                $pieces[] = mb_substr($sentence, 0, $limit);
                $sentence = mb_substr($sentence, $limit);
            }

            if ($current !== '' && mb_strlen($current) + mb_strlen($sentence) + 1 > $limit) {
                $pieces[] = $current;
                $current = '';
            }

            $current = $current === '' ? $sentence : $current.' '.$sentence;
        }

        if ($current !== '') {
            $pieces[] = $current;
        }

        return $pieces;
    }

    /**
     * The source guide: a short summary, the main topics and a few
     * understanding questions, all null when the model gave no summary.
     *
     * A model with structured output answers GUIDE_SCHEMA; one without
     * (`structured_output` none) writes the summary with a "Topik:" line,
     * read into topics, and gives no questions. A structured request the
     * provider refused, or an answer that is not the guide's JSON, falls
     * back to that plain summary. A summary cut at its output limit (even
     * after the client's larger retry) says so, and one cut before any
     * text is no summary at all.
     *
     * @param  list<string>  $contents  The source's chunks, in order.
     * @return array{summary: string|null, topics: list<string>|null, suggested_questions: list<string>|null}
     */
    private function summarize(array $contents): array
    {
        if (! $this->ai->isUsable()) {
            return self::NO_GUIDE;
        }

        $input = $this->summaryInput($contents);

        if ($this->ai->capabilities()->structuredOutput === AiCapabilities::STRUCTURED_NONE) {
            return $this->plainSummary($input);
        }

        try {
            // The output limit leaves a thinking model room to reason before its 2–3 sentences.
            $result = $this->ai->generate(new AiRequest(
                messages: [AiRequest::user([AiRequest::text($input."\n\n---\n".self::GUIDE_PROMPT)])],
                maxOutputTokens: 2048,
                temperature: 0.2,
                jsonSchema: self::GUIDE_SCHEMA,
            ));
        } catch (\Throwable $throwable) {
            Log::info('Notebook source guide skipped', ['error' => $throwable->getMessage()]);

            // A provider that refused the schema may still write a plain summary; one that is busy or set up wrong will not.
            return $throwable instanceof NotebookAiException && $throwable->is(NotebookAiException::REASON_REJECTED)
                ? $this->plainSummary($input)
                : self::NO_GUIDE;
        }

        $data = $this->guideJson($result->text);

        if (is_array($data) && is_string($data['summary'] ?? null) && trim($data['summary']) !== '') {
            $topics = $this->cleanTopics(is_array($data['topics'] ?? null) ? $data['topics'] : []);
            $questions = $this->cleanQuestions(is_array($data['questions'] ?? null) ? $data['questions'] : []);

            return [
                'summary' => $this->fitSummary(trim($data['summary']), false),
                'topics' => $topics !== [] ? $topics : null,
                'suggested_questions' => $questions !== [] ? $questions : null,
            ];
        }

        // Prose where the JSON belongs is a plain summary; broken JSON is asked for again as one.
        return str_starts_with(ltrim($result->text), '{') || str_starts_with(ltrim($result->text), '`')
            ? $this->plainSummary($input)
            : $this->plainGuide($result->text, $result->truncated);
    }

    /**
     * The summary without structured output: prose with a "Topik:" line.
     *
     * @return array{summary: string|null, topics: list<string>|null, suggested_questions: list<string>|null}
     */
    private function plainSummary(string $input): array
    {
        if (! $this->ai->isUsable()) {
            return self::NO_GUIDE;
        }

        try {
            $result = $this->ai->generate(new AiRequest(
                messages: [AiRequest::user([AiRequest::text($input."\n\n---\n".self::SUMMARY_PROMPT)])],
                maxOutputTokens: 2048,
                temperature: 0.2,
            ));
        } catch (\Throwable $throwable) {
            Log::info('Notebook source summary skipped', ['error' => $throwable->getMessage()]);

            return self::NO_GUIDE;
        }

        return $this->plainGuide($result->text, $result->truncated);
    }

    /**
     * A plain summary with its "Topik:" line read into topics (and left out
     * of the summary, which shows them as badges).
     *
     * @return array{summary: string|null, topics: list<string>|null, suggested_questions: list<string>|null}
     */
    private function plainGuide(string $text, bool $truncated): array
    {
        $topics = [];

        if (preg_match('/^[ \t*_]*Topik[ \t*_]*:[ \t*_]*(.+)$/mu', $text, $match) === 1) {
            $topics = $this->cleanTopics(preg_split('/\s*[,;]\s*/u', $match[1]) ?: []);

            if ($topics !== []) {
                $text = trim(str_replace($match[0], '', $text));
            }
        }

        $text = trim($text);

        if ($text === '') {
            return self::NO_GUIDE;
        }

        return [
            'summary' => $this->fitSummary($text, $truncated),
            'topics' => $topics !== [] ? $topics : null,
            'suggested_questions' => null,
        ];
    }

    private function fitSummary(string $text, bool $truncated): string
    {
        $note = $truncated ? "\n".self::TRUNCATED_SUMMARY_NOTE : '';
        $room = self::SUMMARY_MAX_CHARS - mb_strlen($note);
        $summary = mb_strlen($text) > $room ? rtrim(mb_substr($text, 0, $room - 1)).'…' : $text;

        return $summary.$note;
    }

    /**
     * The guide's JSON: bare, or (from a model that only has the schema in
     * its prompt) inside a code block or a sentence. Null when there is none.
     */
    private function guideJson(string $text): mixed
    {
        $data = json_decode($text, true);

        if ($data !== null) {
            return $data;
        }

        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        return $start !== false && $end !== false && $end > $start
            ? json_decode(substr($text, $start, $end - $start + 1), true)
            : null;
    }

    /**
     * At most MAX_TOPICS short topics, each once.
     *
     * @param  array<mixed>  $topics
     * @return list<string>
     */
    private function cleanTopics(array $topics): array
    {
        $kept = [];

        foreach ($topics as $topic) {
            $topic = is_string($topic) ? trim(preg_replace('/\s+/u', ' ', $topic) ?? '', " \t\n\r\0\x0B.*_\"'") : '';

            if ($topic === '' || mb_strlen($topic) > self::MAX_TOPIC_CHARS || in_array(mb_strtolower($topic), array_map('mb_strtolower', $kept), true)) {
                continue;
            }

            $kept[] = $topic;

            if (count($kept) === self::MAX_TOPICS) {
                break;
            }
        }

        return $kept;
    }

    /**
     * At most MAX_QUESTIONS understanding questions, each once. A line that
     * is not a question (no closing "?", as GUIDE_PROMPT asks) or that asks
     * for the student's work to be done (DO_MY_TASK_PATTERN) is dropped, so a
     * suggestion never starts a "do my assignment" request.
     *
     * @param  array<mixed>  $questions
     * @return list<string>
     */
    private function cleanQuestions(array $questions): array
    {
        $kept = [];

        foreach ($questions as $question) {
            $question = is_string($question) ? trim(preg_replace('/\s+/u', ' ', $question) ?? '') : '';

            if ($question === '' || ! str_ends_with($question, '?') || mb_strlen($question) > self::MAX_QUESTION_CHARS || preg_match(self::DO_MY_TASK_PATTERN, $question) === 1 || in_array($question, $kept, true)) {
                continue;
            }

            $kept[] = $question;

            if (count($kept) === self::MAX_QUESTIONS) {
                break;
            }
        }

        return $kept;
    }

    /**
     * What the summary reads: the whole source when it fits in
     * SUMMARY_INPUT_CHARS, else a part from its beginning, its middle and
     * its end (a third each, joined by SUMMARY_GAP), so a long source is not
     * summarised from its first pages alone.
     *
     * @param  list<string>  $contents
     */
    private function summaryInput(array $contents): string
    {
        $text = implode("\n\n", $contents);

        if (mb_strlen($text) <= self::SUMMARY_INPUT_CHARS) {
            return $text;
        }

        $count = count($contents);
        $share = intdiv(self::SUMMARY_INPUT_CHARS - 2 * mb_strlen(self::SUMMARY_GAP), 3);
        $picked = [];

        foreach ([[0, 1], [intdiv($count, 2), 1], [$count - 1, -1]] as [$index, $step]) {
            $room = $share;

            while ($index >= 0 && $index < $count && $room > 0 && ! isset($picked[$index])) {
                $piece = $step > 0 ? mb_substr($contents[$index], 0, $room) : mb_substr($contents[$index], -$room);
                $picked[$index] = $piece;
                $room -= mb_strlen($piece) + 2;
                $index += $step;
            }
        }

        ksort($picked);
        $input = '';
        $previous = null;

        foreach ($picked as $index => $piece) {
            $input .= $previous === null ? $piece : ($index === $previous + 1 ? "\n\n" : self::SUMMARY_GAP).$piece;
            $previous = $index;
        }

        return mb_substr($input, 0, self::SUMMARY_INPUT_CHARS);
    }

    /**
     * @return array{0: string, 1: string} Contents and file name.
     */
    private function contents(NotebookSource $source): array
    {
        if ($source->kind === NotebookSource::KIND_ARCHIVED_FILE) {
            $file = $source->archivedFile;
            $contents = $file !== null ? $this->archivedFiles->contents($file) : null;

            if ($file === null || $contents === null) {
                throw new NotebookSourceException('Berkas arsipnya sudah tidak ada.');
            }

            return [$contents, $file->filename];
        }

        $contents = $source->disk_path !== null ? $this->disk()->get($source->disk_path) : null;

        if (! is_string($contents)) {
            throw new NotebookSourceException('Berkas unggahannya sudah tidak ada.');
        }

        return [$contents, (string) $source->disk_path];
    }

    private function failureMessage(\Throwable $throwable): string
    {
        return $throwable instanceof NotebookAiException || $throwable instanceof NotebookSourceException
            ? $throwable->getMessage()
            : 'Sumber ini gagal dibaca. Coba unggah ulang.';
    }

    private function diskName(): string
    {
        return (string) config('notebook.uploads.disk', 'local');
    }

    private function disk(): Filesystem
    {
        return Storage::disk($this->diskName());
    }
}
