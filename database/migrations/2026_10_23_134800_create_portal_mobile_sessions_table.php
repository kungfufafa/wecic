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
        Schema::create('portal_mobile_sessions', function (Blueprint $table): void {
            $table->id();
            // The Laravel session the token's requests run in; it holds the
            // campus session token, never this table. The bearer token is a
            // Sanctum personal access token of this row.
            $table->string('session_id')->unique();
            $table->string('device_name', 100)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('portal_mobile_sessions');
    }
};
