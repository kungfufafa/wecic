<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Background sync becomes opt-in: a student's portal password is stored
     * only once they switched it on (`background_sync_enabled_at`). Nobody
     * has made that choice yet, so credentials stored under the old opt-out
     * default are dropped.
     */
    public function up(): void
    {
        Schema::table('portal_offline_accounts', function (Blueprint $table): void {
            $table->timestamp('background_sync_enabled_at')->nullable()->after('credentials_rejected_at');
        });

        DB::table('portal_offline_accounts')->whereNotNull('credentials')->update(['credentials' => null]);

        Schema::table('portal_offline_accounts', function (Blueprint $table): void {
            $table->dropColumn('background_sync_disabled_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('portal_offline_accounts', function (Blueprint $table): void {
            $table->timestamp('background_sync_disabled_at')->nullable()->after('credentials_rejected_at');
        });

        DB::table('portal_offline_accounts')
            ->whereNull('background_sync_enabled_at')
            ->update(['background_sync_disabled_at' => now(), 'credentials' => null]);

        Schema::table('portal_offline_accounts', function (Blueprint $table): void {
            $table->dropColumn('background_sync_enabled_at');
        });
    }
};
