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
        Schema::create('notebook_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('notebook_id')->constrained()->cascadeOnDelete();
            // archived_file, upload or text.
            $table->string('kind', 16);
            $table->foreignId('portal_archived_file_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('mime', 127)->nullable();
            $table->string('disk_path')->nullable();
            // pending, processing, ready or failed.
            $table->string('status', 16)->default('pending');
            $table->string('error')->nullable();
            $table->text('summary')->nullable();
            $table->unsignedInteger('char_count')->default(0);
            $table->unsignedSmallInteger('page_count')->nullable();
            $table->boolean('truncated')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notebook_sources');
    }
};
