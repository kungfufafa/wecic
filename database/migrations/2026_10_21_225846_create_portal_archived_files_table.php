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
        Schema::create('portal_archived_files', function (Blueprint $table): void {
            $table->id();
            $table->string('owner_hash', 64);
            // SIPANDAI `courses_id` of the class the file belongs to.
            $table->string('course_key', 64)->nullable();
            $table->string('course_name')->nullable();
            $table->string('meeting_id', 32)->nullable();
            $table->unsignedSmallInteger('meeting_number')->nullable();
            // modul (meeting material), latihan (assignment material) or rps.
            $table->string('kind', 16);
            $table->string('title')->nullable();
            // Canonical /portal/files/ URL (PortalUrlGuard::campusFileUrl).
            $table->string('source_url', 512);
            $table->string('filename');
            $table->string('mime', 127);
            $table->unsignedBigInteger('size');
            // Blobs are stored once by content and shared between students.
            $table->string('sha256', 64);
            $table->timestamp('archived_at');
            $table->timestamps();

            $table->unique(['owner_hash', 'source_url']);
            $table->index(['owner_hash', 'course_key']);
            $table->index('sha256');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('portal_archived_files');
    }
};
