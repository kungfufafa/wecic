<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ExamSubmissionRecord;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Exam submission records saved without a username could be shown to any
 * student of the class; the data migration drops them and keeps the rest.
 */
class DeleteOwnerlessExamSubmissionRecordsMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_records_without_an_owner_are_deleted_and_owned_ones_kept(): void
    {
        $owned = ExamSubmissionRecord::factory()->create(['exam_id' => 9195, 'username' => '20990000001']);
        ExamSubmissionRecord::factory()->create(['exam_id' => 9195, 'username' => null]);
        ExamSubmissionRecord::factory()->create(['exam_id' => 9195, 'username' => '']);

        $migration = $this->migration();
        $migration->up();

        $this->assertSame([$owned->id], ExamSubmissionRecord::query()->pluck('id')->all());

        $migration->up();

        $this->assertSame([$owned->id], ExamSubmissionRecord::query()->pluck('id')->all());
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_10_22_163611_delete_ownerless_exam_submission_records.php');
    }
}
