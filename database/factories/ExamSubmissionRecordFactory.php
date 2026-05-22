<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ExamSubmissionRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ExamSubmissionRecord>
 */
class ExamSubmissionRecordFactory extends Factory
{
    protected $model = ExamSubmissionRecord::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $examId = $this->faker->numberBetween(8800, 9300);
        $submissionId = $this->faker->numberBetween(120000, 140000);
        $filename = "{$examId}-UTS-20251030030-{$this->faker->regexify('[a-f0-9]{3}')}.pdf";

        return [
            'username' => '20251030030',
            'exam_id' => $examId,
            'submission_id' => $submissionId,
            'file_url' => "https://my.cic.ac.id/portal/files/tugasmhs/{$filename}",
            'file_name' => $filename,
            'delete_url' => "https://my.cic.ac.id/portal/sipandai/?mod=sipandai&act=jobdelujian&id={$submissionId}",
            'uploaded_at_label' => 'Rabu, 20 Mei 2026',
            'exam_type' => 'UTS / Reguler',
            'description' => '-',
            'source' => 'detail',
            'metadata' => [],
            'last_seen_at' => now(),
        ];
    }
}
