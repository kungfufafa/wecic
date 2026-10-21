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
        Schema::create('portal_notifications', function (Blueprint $table): void {
            $table->id();
            // `PortalDataOwner::key`: the student, not the login, so a re-login
            // keeps the history and never repeats a notification.
            $table->string('owner_hash', 64);
            $table->string('type', 64);
            $table->string('dataset', 64)->nullable();
            $table->string('record_key')->nullable();
            $table->string('dedupe_key', 64);
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('url', 512)->nullable();
            $table->string('urgency', 16)->default('info');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('read_at')->nullable();

            $table->unique(['owner_hash', 'dedupe_key']);
            $table->index(['owner_hash', 'read_at']);
            $table->index(['owner_hash', 'id']);
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('portal_notifications');
    }
};
