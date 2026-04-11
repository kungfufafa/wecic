<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Http\UploadedFile;

class SipandaiExamUploadRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /**
             * Ambil dari parameter `id` pada URL unggah ujian SIPANDAI.
             *
             * @example 8833
             */
            'id' => ['required', 'integer', 'min:1'],
            /**
             * Lampiran berkas ujian mahasiswa.
             *
             * PDF umum dipakai untuk teori, sedangkan ZIP atau RAR untuk praktikum.
             */
            'attachment' => ['required', 'file', 'mimes:pdf,zip,rar', 'max:20480'],
            /**
             * Override field tambahan dari form unggah ujian bila diperlukan.
             *
             * @var array<string, mixed>
             *
             * @example {"catatan":"Upload via API"}
             */
            'form_values' => ['sometimes', 'array'],
        ];
    }

    public function messages(): array
    {
        return [
            'id.required' => 'Parameter id wajib diisi.',
            'id.integer' => 'Parameter id harus berupa angka.',
            'attachment.required' => 'File ujian wajib diunggah.',
            'attachment.file' => 'Lampiran ujian tidak valid.',
            'attachment.mimes' => 'File ujian harus berupa PDF, ZIP, atau RAR.',
            'attachment.max' => 'Ukuran file ujian maksimal 20 MB.',
            'form_values.array' => 'Parameter form_values harus berupa object/array.',
        ];
    }

    public function attributes(): array
    {
        return [
            'id' => 'exam identifier',
            'attachment' => 'exam attachment',
            'form_values' => 'form override values',
        ];
    }

    public function examId(): int
    {
        return (int) $this->validated('id');
    }

    /**
     * @return array<string, mixed>
     */
    public function formValues(): array
    {
        $values = $this->validated('form_values', []);

        return is_array($values) ? $values : [];
    }

    public function attachment(): UploadedFile
    {
        $file = $this->file('attachment');

        if (! $file instanceof UploadedFile) {
            throw new \RuntimeException('Attachment file is missing or invalid.');
        }

        return $file;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'id' => $this->input('id'),
            'form_values' => $this->input('form_values', []),
        ]);
    }
}
