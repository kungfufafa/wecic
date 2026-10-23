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
        Schema::create('portal_outbox_items', function (Blueprint $table): void {
            $table->id();
            // `PortalDataOwner::key`: the student, not the offline session the
            // item was queued in, so a fresh online login can still send it.
            $table->string('owner_hash', 64);
            // assignment_upload | exam_upload | attendance
            $table->string('action', 32);
            // Encrypted validated request: portal ids, form values, the
            // attendance code, the original file name and type.
            $table->text('payload');
            // The attachment, kept on the `local` disk until the item is settled.
            $table->string('file_path', 512)->nullable();
            // What the student sees in the queue: course, meeting or exam.
            $table->string('subject')->nullable();
            // Past this moment the item is marked expired and never sent.
            $table->timestamp('deadline_at')->nullable();
            // queued | sent | failed | expired
            $table->string('status', 16)->default('queued');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('last_error', 500)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['owner_hash', 'status']);
            $table->index(['status', 'deadline_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('portal_outbox_items');
    }
};
