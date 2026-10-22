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
        Schema::create('notebooks', function (Blueprint $table): void {
            $table->id();
            // `PortalDataOwner::key`: the student, not the login.
            $table->string('owner_hash', 64)->index();
            $table->string('title');
            // SIPANDAI `courses_id` when the notebook was made for one course.
            $table->string('course_key', 64)->nullable();
            $table->string('course_name')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notebooks');
    }
};
