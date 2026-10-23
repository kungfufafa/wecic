<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `last_online_login_at` was created as a NOT NULL TIMESTAMP without a
 * default, the table's first TIMESTAMP column. MySQL 5.7 and MariaDB up to
 * 10.9 (explicit_defaults_for_timestamp=OFF) then give it DEFAULT
 * CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, so every update of the row
 * (background sync, offline login, the retention prune itself) resets it to
 * now and the stored credentials never age out. An explicit default stops
 * that: the column only changes when PortalOfflineAccess::remember() sets it.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('portal_offline_accounts', function (Blueprint $table): void {
            $table->timestamp('last_online_login_at')->useCurrent()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('portal_offline_accounts', function (Blueprint $table): void {
            $table->timestamp('last_online_login_at')->change();
        });
    }
};
