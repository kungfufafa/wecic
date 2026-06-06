<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExamSubmissionRecord extends Model
{
    /** @use HasFactory<\Database\Factories\ExamSubmissionRecordFactory> */
    use HasFactory;

    protected $fillable = [
        'username',
        'exam_id',
        'record_key',
        'submission_id',
        'file_url',
        'file_name',
        'delete_url',
        'uploaded_at_label',
        'exam_type',
        'description',
        'source',
        'metadata',
        'last_seen_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'exam_id' => 'integer',
            'submission_id' => 'integer',
            'metadata' => 'array',
            'last_seen_at' => 'datetime',
        ];
    }
}
