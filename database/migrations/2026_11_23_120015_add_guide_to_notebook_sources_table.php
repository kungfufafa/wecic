<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A source guide: the main topics of a source and a few understanding
 * questions about it, written with its summary. Sources read before this
 * keep null until they are read again; the notebook then falls back to its
 * general starter questions.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('notebook_sources', function (Blueprint $table): void {
            $table->json('topics')->nullable()->after('summary');
            $table->json('suggested_questions')->nullable()->after('topics');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notebook_sources', function (Blueprint $table): void {
            $table->dropColumn(['topics', 'suggested_questions']);
        });
    }
};
