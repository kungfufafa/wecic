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
        Schema::create('portal_archive_versions', function (Blueprint $table): void {
            $table->id();
            // `PortalDataOwner::key`: the student, so the archive outlives logins.
            $table->string('owner_hash', 64);
            $table->string('dataset', 64);
            $table->string('params_hash', 32);
            $table->json('params')->nullable();
            $table->string('subject')->nullable();
            // One row per distinct content: the same data seen again only moves `last_seen_at`.
            $table->string('content_hash', 64);
            // Encrypted: grades, bills and attendance are personal data.
            $table->longText('payload');
            $table->string('status', 16)->default('trusted');
            $table->json('findings')->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->unsignedInteger('seen_count')->default(1);
            $table->timestamp('trusted_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['owner_hash', 'dataset', 'params_hash', 'content_hash'], 'portal_archive_versions_content_unique');
            $table->index(['owner_hash', 'dataset', 'params_hash', 'trusted_at'], 'portal_archive_versions_trusted_index');
            $table->index(['owner_hash', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('portal_archive_versions');
    }
};
