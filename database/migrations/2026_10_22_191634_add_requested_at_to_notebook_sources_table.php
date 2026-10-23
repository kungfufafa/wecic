<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A notebook queues only a few readings at a time, so files a student adds
 * in bulk wait their turn like course files that joined on their own. While
 * they wait, `requested_at` tells them apart: a file the student asked for
 * is read with the student's own reading budget and is queued as soon as a
 * reading finishes, not only while the notebook is open.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('notebook_sources', function (Blueprint $table): void {
            // When the student asked for this source to be read; null for a course file that joined on its own.
            $table->timestamp('requested_at')->nullable()->after('queued_at');
        });

        DB::table('notebook_sources')
            ->whereIn('kind', ['upload', 'text'])
            ->update(['requested_at' => DB::raw('created_at')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notebook_sources', function (Blueprint $table): void {
            $table->dropColumn('requested_at');
        });
    }
};
