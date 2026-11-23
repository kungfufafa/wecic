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
        Schema::create('notebook_ai_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('owner_hash')->unique();
            // gemini, anthropic or openai; null follows NOTEBOOK_AI_PROVIDER.
            $table->string('provider', 32)->nullable();
            // Encrypted with APP_KEY (the model's `encrypted` cast).
            $table->text('gemini_api_key')->nullable();
            $table->text('anthropic_api_key')->nullable();
            $table->text('openai_api_key')->nullable();
            $table->string('openai_base_url', 2048)->nullable();
            $table->string('openai_model')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notebook_ai_settings');
    }
};
