<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * @mixin \Illuminate\Http\Request
 */
class SipandaiAssignmentUploadRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'course_id' => ['required', 'integer', 'min:1'],
            'discussion_id' => ['required', 'integer', 'min:1'],
            'meeting_number' => ['required', 'integer', 'min:1'],
            'attachment' => ['required', 'file', 'max:15360'], // 15 MB
            'form_values' => ['sometimes', 'array'],
            'form_values.*' => ['nullable', 'string'],
        ];
    }

    /**
     * Custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'course_id.required' => 'Parameter course_id wajib diisi.',
            'course_id.integer' => 'Parameter course_id harus berupa angka.',
            'discussion_id.required' => 'Parameter discussion_id wajib diisi.',
            'discussion_id.integer' => 'Parameter discussion_id harus berupa angka.',
            'meeting_number.required' => 'Nomor pertemuan wajib diisi.',
            'meeting_number.integer' => 'Nomor pertemuan harus berupa angka.',
            'attachment.required' => 'File tugas wajib diunggah.',
            'attachment.file' => 'Lampiran tugas tidak valid.',
            'attachment.max' => 'Ukuran file tugas maksimal 15 MB.',
            'form_values.array' => 'form_values harus berupa array key-value.',
        ];
    }

    /**
     * Custom attribute labels.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'course_id' => 'course identifier',
            'discussion_id' => 'discussion identifier',
            'meeting_number' => 'meeting number',
            'attachment' => 'assignment attachment',
        ];
    }

    /**
     * Accessor for the uploaded assignment file.
     */
    public function attachment(): UploadedFile
    {
        $laravelRequest = request();

        if (! $laravelRequest instanceof Request) {
            throw new \RuntimeException('Unexpected request instance.');
        }

        $file = $laravelRequest->file('attachment');

        if (! $file instanceof UploadedFile) {
            throw new \RuntimeException('Assignment attachment is not available.');
        }

        return $file;
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $laravelRequest = request();

        if (! $laravelRequest instanceof Request) {
            return;
        }

        $formValues = $laravelRequest->get('form_values');

        if ($formValues === null) {
            return;
        }

        if (! is_array($formValues)) {
            $laravelRequest->request->set('form_values', []);
        }
    }

    /**
     * Describe body parameters for API documentation.
     *
     * @return array<string, array<string, mixed>>
     */
    public function bodyParameters(): array
    {
        return [
            'course_id' => [
                'description' => 'Ambil dari query `courses_id` pada URL upload tugas SIPANDAI.',
                'example' => 9000,
            ],
            'discussion_id' => [
                'description' => 'Ambil dari parameter `id`/`discussion_id` pada URL upload tugas.',
                'example' => 30095,
            ],
            'meeting_number' => [
                'description' => 'Ambil dari parameter `pertemuan` pada URL upload.',
                'example' => 6,
            ],
            'attachment' => [
                'description' => 'File PDF tugas mahasiswa (field `data[tugas]`).',
            ],
            'form_values' => [
                'description' => 'Key-value override tambahan (misal `cmd`, `keterangan`).',
                'example' => [
                    'cmd' => 'Upload',
                ],
            ],
        ];
    }
}
