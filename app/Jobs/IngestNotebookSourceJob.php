<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\NotebookSource;
use App\Services\NotebookLibrary;
use Illuminate\Bus\Queueable;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Read a notebook source's text once (a PDF is transcribed by the model),
 * so later questions only use the stored text. A notebook keeps only a few
 * readings queued; when this one ends, its slot goes to the next file the
 * student asked for (NotebookLibrary::queueRequestedReadings()).
 *
 * `$automatic` marks a course file that joined its notebook on its own
 * (NotebookLibrary::readWaitingSources): it leaves part of the daily reading
 * budget to the files the student adds, and waits for the next day instead
 * of failing when it is spent.
 */
class IngestNotebookSourceJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    /**
     * Not promoted, so it has a default of its own: a job queued before this
     * flag existed unserializes without it and is read as one the student
     * asked for.
     */
    private bool $automatic = false;

    public function __construct(private readonly int $sourceId, bool $automatic = false)
    {
        $this->automatic = $automatic;
    }

    public function uniqueId(): string
    {
        return (string) $this->sourceId;
    }

    /**
     * The reading is queued once while its lock lasts: longer than a busy
     * queue's backlog and the job's own run, but not for good, so a job that
     * was lost (the queue was cleared) does not block its source.
     */
    public function uniqueFor(): int
    {
        return max($this->timeout, NotebookSource::QUEUED_STALLED_AFTER_SECONDS);
    }

    /**
     * A reading queued again after its lock expired never runs twice at once.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping((string) $this->sourceId))->dontRelease()->expireAfter($this->timeout + 60)];
    }

    /**
     * Let a source whose job was lost (its lock still held) be queued again.
     */
    public static function releaseUniqueLock(int $sourceId): void
    {
        (new UniqueLock(app(Cache::class)))->release(new self($sourceId));
    }

    public function handle(NotebookLibrary $library): void
    {
        $source = NotebookSource::query()->find($this->sourceId);

        if ($source === null) {
            return;
        }

        if ($source->status !== NotebookSource::STATUS_READY) {
            $library->ingest($source, $this->automatic);
        }

        $this->queueNextReadings($library, $source);
    }

    /**
     * The worker died mid-reading (timeout, out of memory, stopped): the
     * reading's own error handling never ran, so the source would stay
     * "processing" for good.
     */
    public function failed(?\Throwable $exception): void
    {
        $source = NotebookSource::query()->find($this->sourceId);

        if ($source === null) {
            return;
        }

        if ($source->status !== NotebookSource::STATUS_READY) {
            Log::warning('Notebook source reading stopped', [
                'source_id' => $this->sourceId,
                'error' => $exception?->getMessage(),
            ]);

            $source->forceFill([
                'status' => NotebookSource::STATUS_FAILED,
                'error' => 'Sumber ini berhenti dibaca di tengah jalan. Ketuk "Baca ulang" untuk mencoba lagi.',
            ])->save();
        }

        $this->queueNextReadings(app(NotebookLibrary::class), $source);
    }

    /**
     * This reading's slot is free again: the next files the student asked
     * for take it. A failure here must not mark the reading itself failed.
     */
    private function queueNextReadings(NotebookLibrary $library, NotebookSource $source): void
    {
        try {
            $notebook = $source->notebook;

            if ($notebook !== null) {
                $library->queueRequestedReadings($notebook);
            }
        } catch (\Throwable $throwable) {
            Log::warning('Next notebook readings could not be queued', [
                'source_id' => $source->id,
                'error' => $throwable->getMessage(),
            ]);
        }
    }
}
