<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('exam_submission_records', function (Blueprint $table): void {
            $table->string('record_key')->nullable()->after('exam_id');
            $table->unique('record_key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('exam_submission_records', function (Blueprint $table): void {
            $table->dropUnique(['record_key']);
            $table->dropColumn('record_key');
        });
    }
};
