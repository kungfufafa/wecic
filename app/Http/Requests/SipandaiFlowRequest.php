<?php

declare(strict_types=1);

namespace App\Http\Requests;

class SipandaiFlowRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /**
             * Identifier kelas atau diskusi utama pada URL SIPANDAI.
             *
             * @example 2265
             */
            'id' => ['required', 'integer', 'min:1'],
            /**
             * Identifier dosen dari URL materi SIPANDAI.
             *
             * Opsional bila alur hanya membutuhkan detail kelas dasar.
             *
             * @example 5240
             */
            'dosen_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            /**
             * Identifier mata kuliah dari URL SIPANDAI.
             *
             * @example 8810
             */
            'course_id' => ['required', 'integer', 'min:1'],
            /**
             * Identifier `courses_id` untuk detail pertemuan, form tugas, atau diskusi.
             *
             * Jika tidak diisi, nilai `course_id` akan dipakai.
             *
             * @example 8810
             */
            'courses_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            /**
             * Nomor pertemuan yang sedang diproses.
             *
             * @example 6
             */
            'pertemuan' => ['required', 'integer', 'min:1'],
            /**
             * Identifier pertemuan bila ingin menyertakan detail meeting sekaligus.
             *
             * @example 445
             */
            'meeting_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            /**
             * Identifier submission tugas bila ingin membentuk link hapus tugas.
             *
             * @example 126663
             */
            'submission_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'id' => $this->input('id', $this->input('discussion_id')),
            'course_id' => $this->input('course_id', $this->input('courseid')),
            'courses_id' => $this->input('courses_id', $this->input('course_id', $this->input('courseid'))),
            'pertemuan' => $this->input('pertemuan', $this->input('meeting_number')),
            'submission_id' => $this->input('submission_id', $this->input('delete_id')),
        ]);
    }
}
