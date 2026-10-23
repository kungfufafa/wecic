<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Notebook sources remember which course file they read (so a re-archived
 * file is linked again instead of added twice) and when their reading was
 * queued (automatic sources wait until the student opens the notebook).
 * Sources a student removed from a course notebook are remembered so the
 * automatic paths do not bring them back. Chunks get the index their
 * per-source rewrites and deletes need.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('notebook_sources', function (Blueprint $table): void {
            // The portal URL of the archived course file, which outlives its archive row.
            $table->string('archived_source_url', 512)->nullable()->after('portal_archived_file_id');
            // When the reading job was queued; null while the source waits for the notebook to be opened.
            $table->timestamp('queued_at')->nullable()->after('truncated');

            $table->index(['notebook_id', 'archived_source_url']);
            $table->index('portal_archived_file_id');
        });

        Schema::table('notebook_chunks', function (Blueprint $table): void {
            $table->index(['notebook_source_id', 'position']);
        });

        Schema::create('notebook_dismissals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('notebook_id')->constrained()->cascadeOnDelete();
            // archived_file (reference: source URL) or meeting (reference: meeting id).
            $table->string('kind', 16);
            $table->string('reference', 512);
            $table->timestamps();

            $table->unique(['notebook_id', 'kind', 'reference']);
        });

        DB::table('notebook_sources')
            ->whereNotNull('portal_archived_file_id')
            ->update(['archived_source_url' => DB::raw('(select source_url from portal_archived_files where portal_archived_files.id = notebook_sources.portal_archived_file_id)')]);
        DB::table('notebook_sources')->update(['queued_at' => DB::raw('created_at')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notebook_dismissals');

        Schema::table('notebook_chunks', function (Blueprint $table): void {
            $table->dropIndex(['notebook_source_id', 'position']);
        });

        Schema::table('notebook_sources', function (Blueprint $table): void {
            $table->dropIndex(['notebook_id', 'archived_source_url']);
            $table->dropIndex(['portal_archived_file_id']);
            $table->dropColumn(['archived_source_url', 'queued_at']);
        });
    }
};
