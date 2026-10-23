<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('portal_archive_versions', function (Blueprint $table): void {
            // 0 for the first row of a content. When the portal goes back to
            // content MyCIC trusted earlier and it breaks a guard now, the
            // suspect sighting is a new row (1, 2, ...), so the version that
            // was trusted keeps its status and history.
            $table->unsignedInteger('sighting')->default(0)->after('content_hash');
        });

        Schema::table('portal_archive_versions', function (Blueprint $table): void {
            $table->dropUnique('portal_archive_versions_content_unique');
            $table->unique(['owner_hash', 'dataset', 'params_hash', 'content_hash', 'sighting'], 'portal_archive_versions_content_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // The old index allows one row per content: later sightings go.
        DB::table('portal_archive_versions')->where('sighting', '>', 0)->delete();

        Schema::table('portal_archive_versions', function (Blueprint $table): void {
            $table->dropUnique('portal_archive_versions_content_unique');
            $table->unique(['owner_hash', 'dataset', 'params_hash', 'content_hash'], 'portal_archive_versions_content_unique');
        });

        Schema::table('portal_archive_versions', function (Blueprint $table): void {
            $table->dropColumn('sighting');
        });
    }
};
