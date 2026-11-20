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
        Schema::create('notebook_ai_consents', function (Blueprint $table): void {
            $table->id();
            // `PortalDataOwner::key`: one consent per student, whichever session gave it.
            $table->string('owner_hash', 64)->unique();
            // When the student agreed to send Notebook content to Google Gemini.
            // Null once they withdrew it: nothing is sent to the model until they agree again.
            $table->timestamp('granted_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notebook_ai_consents');
    }
};
