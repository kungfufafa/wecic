<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\NotebookSource;
use App\Services\NotebookLibrary;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Read a notebook source's text once (a PDF is transcribed by the model),
 * so later questions only use the stored text.
 */
class IngestNotebookSourceJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(private readonly int $sourceId) {}

    public function uniqueId(): string
    {
        return (string) $this->sourceId;
    }

    public function handle(NotebookLibrary $library): void
    {
        $source = NotebookSource::query()->find($this->sourceId);

        if ($source === null || $source->status === NotebookSource::STATUS_READY) {
            return;
        }

        $library->ingest($source);
    }
}
