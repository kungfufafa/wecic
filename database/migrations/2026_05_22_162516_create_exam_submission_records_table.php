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
        Schema::create('exam_submission_records', function (Blueprint $table) {
            $table->id();
            $table->string('username')->nullable()->index();
            $table->unsignedBigInteger('exam_id')->index();
            $table->unsignedBigInteger('submission_id')->nullable()->index();
            $table->string('file_url')->nullable();
            $table->string('file_name')->nullable();
            $table->string('delete_url')->nullable();
            $table->string('uploaded_at_label')->nullable();
            $table->string('exam_type')->nullable();
            $table->string('description')->nullable();
            $table->string('source')->default('detail');
            $table->json('metadata')->nullable();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exam_submission_records');
    }
};
