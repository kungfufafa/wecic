<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * @mixin \Illuminate\Http\Request
 */
class SipandaiAssignmentUploadRequest extends ApiFormRequest
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
            /**
             * Ambil dari query `courses_id` pada URL unggah tugas SIPANDAI.
             *
             * @example 9000
             */
            'courses_id' => ['required', 'integer', 'min:1'],
            /**
             * Ambil dari parameter `id` pada URL unggah tugas.
             *
             * Nilai ini biasanya merepresentasikan identifier diskusi atau materi.
             *
             * @example 30095
             */
            'id' => ['required', 'integer', 'min:1'],
            /**
             * Nomor pertemuan pada URL unggah tugas SIPANDAI.
             *
             * @example 6
             */
            'pertemuan' => ['required', 'integer', 'min:1'],
            /**
             * Override field tambahan dari form asli SIPANDAI bila diperlukan.
             *
             * @var array<string, mixed>
             *
             * @example {"keterangan":"Test upload"}
             */
            'form_values' => ['sometimes', 'array'],
            /**
             * Berkas tugas mahasiswa yang akan dikirim sebagai field file SIPANDAI.
             */
            'attachment' => ['required', 'file', 'max:15360'], // 15 MB
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
            'courses_id.required' => 'Parameter courses_id wajib diisi.',
            'courses_id.integer' => 'Parameter courses_id harus berupa angka.',
            'id.required' => 'Parameter id wajib diisi.',
            'id.integer' => 'Parameter id harus berupa angka.',
            'pertemuan.required' => 'Parameter pertemuan wajib diisi.',
            'pertemuan.integer' => 'Parameter pertemuan harus berupa angka.',
            'form_values.array' => 'Parameter form_values harus berupa object/array.',
            'attachment.required' => 'File tugas wajib diunggah.',
            'attachment.file' => 'Lampiran tugas tidak valid.',
            'attachment.max' => 'Ukuran file tugas maksimal 15 MB.',
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
            'courses_id' => 'course identifier',
            'id' => 'discussion identifier',
            'pertemuan' => 'meeting number',
            'form_values' => 'form override values',
            'attachment' => 'assignment attachment',
        ];
    }

    public function courseId(): int
    {
        return (int) $this->validated('courses_id');
    }

    public function discussionId(): int
    {
        return (int) $this->validated('id');
    }

    public function meetingNumber(): int
    {
        return (int) $this->validated('pertemuan');
    }

    /**
     * @return array<string, mixed>
     */
    public function formValues(): array
    {
        $values = $this->validated('form_values', []);

        return is_array($values) ? $values : [];
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
        $this->merge([
            'courses_id' => $this->input('courses_id', $this->input('course_id')),
            'id' => $this->input('id', $this->input('discussion_id')),
            'pertemuan' => $this->input('pertemuan', $this->input('meeting_number')),
            'form_values' => $this->input('form_values', []),
        ]);
    }
}
