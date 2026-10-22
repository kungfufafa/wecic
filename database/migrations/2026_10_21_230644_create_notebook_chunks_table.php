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
        Schema::create('notebook_chunks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('notebook_source_id')->constrained()->cascadeOnDelete();
            $table->foreignId('notebook_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            // Page (PDF) or slide (PPTX) the text comes from, when known.
            $table->unsignedSmallInteger('page')->nullable();
            $table->text('content');

            $table->index(['notebook_id', 'notebook_source_id', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notebook_chunks');
    }
};
