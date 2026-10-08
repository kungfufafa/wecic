<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The AI notebook feature was removed; its tables and data go with it.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::dropIfExists('notebook_messages');
        Schema::dropIfExists('notebooks');
    }

    /**
     * Reverse the migrations (restores the empty tables, not their data).
     */
    public function down(): void
    {
        Schema::create('notebooks', function (Blueprint $table) {
            $table->id();
            $table->string('username')->index();
            $table->string('title');
            $table->string('course_id')->nullable()->index();
            $table->string('course_name')->nullable();
            $table->string('meeting_id')->nullable()->index();
            $table->string('meeting_number')->nullable();
            $table->json('sources')->nullable();
            $table->longText('draft_content')->nullable();
            $table->json('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('notebook_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notebook_id')->constrained('notebooks')->cascadeOnDelete();
            $table->string('role', 20);
            $table->longText('content');
            $table->string('action_type', 50)->nullable();
            $table->timestamps();
        });
    }
};
