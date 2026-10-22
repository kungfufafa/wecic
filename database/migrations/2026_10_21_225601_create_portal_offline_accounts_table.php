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
        Schema::create('portal_offline_accounts', function (Blueprint $table): void {
            $table->id();
            // `PortalDataOwner::keyForUsername`; the NIM itself is not stored.
            $table->string('owner_hash', 64)->unique();
            // Hash of the password of the last successful portal login.
            $table->string('password_hash');
            // Encrypted user info shown while offline.
            $table->text('user_info')->nullable();
            $table->timestamp('last_online_login_at');
            $table->timestamp('last_offline_login_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('portal_offline_accounts');
    }
};
