<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The sources the student ticked as the AI context of a notebook, kept on
 * the server so the web and the app open with the same choice. Null means
 * every source (nothing was left out, or nothing was saved yet).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('notebooks', function (Blueprint $table): void {
            $table->json('context_source_ids')->nullable()->after('class_params');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notebooks', function (Blueprint $table): void {
            $table->dropColumn('context_source_ids');
        });
    }
};
