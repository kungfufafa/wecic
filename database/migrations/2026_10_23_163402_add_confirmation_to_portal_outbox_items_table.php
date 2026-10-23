<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An offline session only proves that its password matched the student's
 * last online login. What it queues is sent once the portal has accepted
 * that same password (PortalOutbox::confirmWithPassword), not merely with
 * the next session that happens to be online.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('portal_outbox_items', function (Blueprint $table): void {
            // The offline-login password hash the queuing session was opened
            // with; '' when unknown (never confirmed), null on items queued
            // before this column existed.
            $table->string('credential_hash')->nullable()->after('status');
            // When the portal accepted that password; only confirmed items are sent.
            $table->timestamp('confirmed_at')->nullable()->after('credential_hash');
        });

        // Items queued before the check existed keep being sent as before.
        DB::table('portal_outbox_items')->where('status', 'queued')->update(['confirmed_at' => now()]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('portal_outbox_items', function (Blueprint $table): void {
            $table->dropColumn(['credential_hash', 'confirmed_at']);
        });
    }
};
