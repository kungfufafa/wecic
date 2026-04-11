<?php

declare(strict_types=1);

namespace App\Http\Requests;

class SipandaiAssignmentDeleteRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /**
             * Identifier submission pada URL hapus tugas (`act=jobdel`).
             *
             * @example 126663
             */
            'id' => ['required', 'integer', 'min:1'],
            /**
             * Identifier diskusi atau materi pada URL hapus tugas.
             *
             * @example 30095
             */
            'idx' => ['required', 'integer', 'min:1'],
            /**
             * Nomor pertemuan pada URL hapus tugas.
             *
             * @example 6
             */
            'pertemuan' => ['required', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'id.required' => 'Parameter id wajib diisi.',
            'id.integer' => 'Parameter id harus berupa angka.',
            'idx.required' => 'Parameter idx wajib diisi.',
            'idx.integer' => 'Parameter idx harus berupa angka.',
            'pertemuan.required' => 'Parameter pertemuan wajib diisi.',
            'pertemuan.integer' => 'Parameter pertemuan harus berupa angka.',
        ];
    }

    public function attributes(): array
    {
        return [
            'id' => 'submission identifier',
            'idx' => 'discussion identifier',
            'pertemuan' => 'meeting number',
        ];
    }

    public function submissionId(): int
    {
        return (int) $this->validated('id');
    }

    public function discussionId(): int
    {
        return (int) $this->validated('idx');
    }

    public function meetingNumber(): int
    {
        return (int) $this->validated('pertemuan');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'id' => $this->input('id', $this->input('submission_id')),
            'idx' => $this->input('idx', $this->input('discussion_id')),
            'pertemuan' => $this->input('pertemuan', $this->input('meeting_number')),
        ]);
    }
}
