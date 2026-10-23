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
        Schema::create('portal_push_tokens', function (Blueprint $table): void {
            $table->id();
            // `PortalDataOwner::key`: pushes reach the student's devices
            // whichever session produced the notification.
            $table->string('owner_hash', 64)->index();
            // An Expo push token names one app install, so it belongs to one
            // student at a time: the last one who registered it.
            $table->string('token')->unique();
            $table->string('platform', 16)->nullable();
            // The login that registered it: logging out of that login stops
            // the pushes. Null once that login is gone without a logout (it
            // idled out), when the pushes keep coming.
            $table->foreignId('portal_mobile_session_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('portal_push_tokens');
    }
};
