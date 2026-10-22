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
        Schema::create('notebook_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('notebook_id')->constrained()->cascadeOnDelete();
            // user or assistant.
            $table->string('role', 16);
            // chat, or a studio output (summary, study_guide, faq, glossary, quiz, flashcards, mind_map).
            $table->string('kind', 32)->default('chat');
            // Text for chat and written outputs, JSON for structured ones.
            $table->longText('content');
            $table->json('citations')->nullable();
            $table->boolean('pinned')->default(false);
            $table->timestamps();

            $table->index(['notebook_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notebook_messages');
    }
};
