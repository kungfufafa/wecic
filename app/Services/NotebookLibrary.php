<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\NotebookAiException;
use App\Exceptions\NotebookSourceException;
use App\Jobs\IngestNotebookSourceJob;
use App\Models\Notebook;
use App\Models\NotebookChunk;
use App\Models\NotebookSource;
use App\Models\PortalArchivedFile;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The sources of a notebook: adding them, reading their text once
 * (IngestNotebookSourceJob) and keeping it as citable chunks.
 */
class NotebookLibrary
{
    private const SUMMARY_PROMPT = <<<'PROMPT'
        Tulis ringkasan sumber ini dalam 2–3 kalimat bahasa Indonesia, lalu satu baris "Topik: " berisi 3–6 topik utama dipisah koma.
        Jangan memakai Markdown.
        PROMPT;

    private const SUMMARY_INPUT_CHARS = 12000;

    public function __construct(
        private readonly NotebookSourceReader $reader,
        private readonly PortalArchivedFileStore $archivedFiles,
        private readonly GeminiClient $gemini
    ) {}

    /**
     * An archived course file becomes a source once per notebook.
     */
    public function addArchivedFile(Notebook $notebook, PortalArchivedFile $file): NotebookSource
    {
        $existing = $notebook->sources()->where('portal_archived_file_id', $file->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        $title = trim(implode(' · ', array_filter([
            $file->meeting_number !== null ? 'Pertemuan '.$file->meeting_number : null,
            $file->title ?? $file->filename,
        ])));

        return $this->queue($notebook->sources()->create([
            'kind' => NotebookSource::KIND_ARCHIVED_FILE,
            'portal_archived_file_id' => $file->id,
            'meeting_id' => $file->meeting_id,
            'meeting_number' => $file->meeting_number,
            'title' => mb_substr($title !== '' ? $title : $file->filename, 0, 255),
            'mime' => $file->mime,
            'status' => NotebookSource::STATUS_PENDING,
        ]));
    }

    public function addUpload(Notebook $notebook, UploadedFile $file): NotebookSource
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: (string) $file->extension());
        $path = $file->storeAs('notebook-uploads/'.$notebook->id, Str::uuid()->toString().'.'.$extension, $this->diskName());

        if ($path === false) {
            throw new NotebookSourceException('Berkas gagal disimpan.');
        }

        return $this->queue($notebook->sources()->create([
            'kind' => NotebookSource::KIND_UPLOAD,
            'title' => mb_substr(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME) ?: 'Berkas unggahan', 0, 255),
            'mime' => $file->getMimeType() ?: $file->getClientMimeType(),
            'disk_path' => $path,
            'status' => NotebookSource::STATUS_PENDING,
        ]));
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
        ]);

        $this->index($source, [['page' => null, 'text' => $content]], false);

        return $source->refresh();
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

        $source->fill([
            'meeting_number' => $meetingNumber,
            'title' => mb_substr(trim($title) !== '' ? trim($title) : 'Pertemuan', 0, 255),
            'mime' => 'text/plain',
            'status' => NotebookSource::STATUS_PROCESSING,
        ])->save();

        $this->index($source, [['page' => null, 'text' => $content]], false, summarize: false);

        return $source->refresh();
    }

    /**
     * Read the source's text and store it as chunks. A failure is kept on
     * the source with a message the student can act on.
     */
    public function ingest(NotebookSource $source): void
    {
        $source->forceFill(['status' => NotebookSource::STATUS_PROCESSING, 'error' => null])->save();

        try {
            [$contents, $filename] = $this->contents($source);
            $read = $this->reader->read($contents, (string) $source->mime, $filename);

            $this->index($source, $read['pages'], $read['truncated']);
        } catch (\Throwable $throwable) {
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

    public function delete(NotebookSource $source): void
    {
        if ($source->disk_path !== null) {
            $this->disk()->delete($source->disk_path);
        }

        $source->delete();
    }

    public function deleteNotebook(Notebook $notebook): void
    {
        $this->disk()->deleteDirectory('notebook-uploads/'.$notebook->id);
        $notebook->delete();
    }

    /**
     * Empty a notebook (sources, uploads, conversation) but keep it: a
     * course notebook stays as long as the course is in the class list.
     */
    public function clearNotebook(Notebook $notebook): void
    {
        $this->disk()->deleteDirectory('notebook-uploads/'.$notebook->id);
        $notebook->chunks()->delete();
        $notebook->sources()->delete();
        $notebook->messages()->delete();
        $notebook->touch();
    }

    private function queue(NotebookSource $source): NotebookSource
    {
        IngestNotebookSourceJob::dispatch($source->id);

        return $source->refresh();
    }

    /**
     * @param  list<array{page: int|null, text: string}>  $pages
     */
    private function index(NotebookSource $source, array $pages, bool $truncated, bool $summarize = true): void
    {
        $chunkChars = max(400, (int) config('notebook.limits.chunk_chars', 1800));
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
            'summary' => $summarize ? $this->summarize(implode("\n\n", array_column($chunks, 'content'))) : null,
        ])->save();
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

    private function summarize(string $text): ?string
    {
        if (! $this->gemini->isConfigured()) {
            return null;
        }

        try {
            $result = $this->gemini->generate([[
                'role' => 'user',
                'parts' => [['text' => mb_substr($text, 0, self::SUMMARY_INPUT_CHARS)."\n\n---\n".self::SUMMARY_PROMPT]],
            ]], null, ['temperature' => 0.2, 'maxOutputTokens' => 512]);

            return mb_substr($result['text'], 0, 1000);
        } catch (\Throwable $throwable) {
            Log::info('Notebook source summary skipped', ['error' => $throwable->getMessage()]);

            return null;
        }
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
