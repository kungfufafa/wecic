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
        Schema::table('portal_offline_accounts', function (Blueprint $table): void {
            // When the background sync last crawled the student's classes
            // (class detail, every meeting and the files they link to); this
            // crawl has its own, longer interval than the dataset sync.
            $table->timestamp('last_course_sync_at')->nullable()->after('last_background_sync_error');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('portal_offline_accounts', function (Blueprint $table): void {
            $table->dropColumn('last_course_sync_at');
        });
    }
};
