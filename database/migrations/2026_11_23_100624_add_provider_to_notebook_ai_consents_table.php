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
        Schema::table('notebook_ai_consents', function (Blueprint $table): void {
            // Which recipient(s) the student agreed to send Notebook content
            // to (`NotebookAiProviders::consentKey()`, e.g. `gemini:free`,
            // `anthropic+gemini:free` or `openai@llm.example.com`). Null is a
            // consent given before the column existed, which was for Google
            // Gemini's free tier.
            $table->string('provider')->nullable()->after('owner_hash');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notebook_ai_consents', function (Blueprint $table): void {
            $table->dropColumn('provider');
        });
    }
};
