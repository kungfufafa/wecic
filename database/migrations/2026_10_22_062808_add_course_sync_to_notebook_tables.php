<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Course notebooks (one per class the student takes, made automatically),
 * meeting sources, and the context an answer was grounded in.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('notebooks', function (Blueprint $table): void {
            // course (made from the class list) or custom (made by the student).
            $table->string('kind', 16)->default('custom')->after('title');
            $table->string('course_code', 32)->nullable()->after('course_name');
            $table->string('lecturer_name')->nullable()->after('course_code');
            // `id`, `dosen_id`, `course_id` of the class page, for the class snapshot.
            $table->json('class_params')->nullable()->after('lecturer_name');

            $table->index(['owner_hash', 'course_key']);
        });

        Schema::table('notebook_sources', function (Blueprint $table): void {
            // SIPANDAI meeting the source belongs to (meeting note or its files).
            $table->string('meeting_id', 64)->nullable()->after('portal_archived_file_id');
            $table->unsignedSmallInteger('meeting_number')->nullable()->after('meeting_id');

            $table->index(['notebook_id', 'meeting_id']);
        });

        Schema::table('notebook_messages', function (Blueprint $table): void {
            // Which sources (and how much of them) the model saw for this message.
            $table->json('context')->nullable()->after('citations');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notebook_messages', function (Blueprint $table): void {
            $table->dropColumn('context');
        });

        Schema::table('notebook_sources', function (Blueprint $table): void {
            $table->dropIndex(['notebook_id', 'meeting_id']);
            $table->dropColumn(['meeting_id', 'meeting_number']);
        });

        Schema::table('notebooks', function (Blueprint $table): void {
            $table->dropIndex(['owner_hash', 'course_key']);
            $table->dropColumn(['kind', 'course_code', 'lecturer_name', 'class_params']);
        });
    }
};
