<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Exam submission records saved while the student was unknown carry no
 * username. Exam ids are shared by a whole class, so such a row (file name,
 * file and delete links) could be shown to another student; nothing saves
 * or reads them any more, and the old ones are dropped here. Running it
 * again finds nothing left to delete.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('exam_submission_records')) {
            return;
        }

        DB::table('exam_submission_records')
            ->where(fn ($query) => $query->whereNull('username')->orWhere('username', ''))
            ->delete();
    }

    /**
     * Reverse the migrations.
     *
     * The deleted rows belonged to no student and are not brought back.
     */
    public function down(): void {}
};
