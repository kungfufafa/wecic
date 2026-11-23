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
        Schema::create('notebook_review_cards', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('notebook_id')->constrained()->cascadeOnDelete();
            // The flashcards or quiz output the card was saved from; the card stays when that output is deleted.
            $table->foreignId('notebook_message_id')->nullable()->constrained()->nullOnDelete();
            // The item's index in that output, so saving the same output again adds no duplicate.
            $table->unsignedSmallInteger('item_index')->nullable();
            $table->text('front');
            $table->text('back');
            $table->json('citations')->nullable();
            $table->string('topic', 120)->nullable();
            $table->unsignedSmallInteger('meeting_number')->nullable();
            $table->timestamp('due_at');
            $table->unsignedSmallInteger('interval_days')->default(0);
            $table->decimal('ease', 4, 2)->default(2.5);
            $table->unsignedInteger('reps')->default(0);
            $table->unsignedInteger('lapses')->default(0);
            // When the card was first graded: the queue lets in a limited number of new cards a day.
            $table->timestamp('first_reviewed_at')->nullable();
            $table->timestamp('last_reviewed_at')->nullable();
            $table->boolean('suspended')->default(false);
            $table->timestamps();

            $table->index(['notebook_id', 'due_at']);
            $table->unique(['notebook_message_id', 'item_index']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notebook_review_cards');
    }
};
