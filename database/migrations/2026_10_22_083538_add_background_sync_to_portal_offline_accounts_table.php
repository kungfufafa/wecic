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
            // Encrypted username + password of the last online login, so MyCIC
            // can log in on the student's behalf and keep the archive current.
            $table->text('credentials')->nullable()->after('user_info');
            // A background login the portal rejected: the password changed.
            $table->timestamp('credentials_rejected_at')->nullable()->after('credentials');
            // The student switched background sync off; logins stop storing credentials.
            $table->timestamp('background_sync_disabled_at')->nullable()->after('credentials_rejected_at');
            $table->timestamp('last_background_sync_at')->nullable()->after('last_offline_login_at');
            $table->string('last_background_sync_status', 16)->nullable()->after('last_background_sync_at');
            $table->string('last_background_sync_error', 500)->nullable()->after('last_background_sync_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('portal_offline_accounts', function (Blueprint $table): void {
            $table->dropColumn([
                'credentials',
                'credentials_rejected_at',
                'background_sync_disabled_at',
                'last_background_sync_at',
                'last_background_sync_status',
                'last_background_sync_error',
            ]);
        });
    }
};
