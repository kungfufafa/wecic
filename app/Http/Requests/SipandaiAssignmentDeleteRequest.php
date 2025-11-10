<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SipandaiAssignmentDeleteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'submission_id' => ['required', 'integer', 'min:1'],
            'discussion_id' => ['required', 'integer', 'min:1'],
            'meeting_number' => ['required', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'submission_id.required' => 'Parameter submission_id wajib diisi.',
            'submission_id.integer' => 'Parameter submission_id harus berupa angka.',
            'discussion_id.required' => 'Parameter discussion_id wajib diisi.',
            'discussion_id.integer' => 'Parameter discussion_id harus berupa angka.',
            'meeting_number.required' => 'Nomor pertemuan wajib diisi.',
            'meeting_number.integer' => 'Nomor pertemuan harus berupa angka.',
        ];
    }

    public function attributes(): array
    {
        return [
            'submission_id' => 'submission identifier',
            'discussion_id' => 'discussion identifier',
            'meeting_number' => 'meeting number',
        ];
    }

    /**
     * Describe body parameters for API documentation.
     */
    public function bodyParameters(): array
    {
        return [
            'submission_id' => [
                'description' => 'Ambil dari parameter `id` pada URL hapus (`act=jobdel`).',
                'example' => 126663,
            ],
            'discussion_id' => [
                'description' => 'Ambil dari parameter `idx` / `discussion_id` pada URL hapus.',
                'example' => 30095,
            ],
            'meeting_number' => [
                'description' => 'Ambil dari parameter `pertemuan` pada URL hapus.',
                'example' => 6,
            ],
        ];
    }
}
