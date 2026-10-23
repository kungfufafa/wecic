<?php

declare(strict_types=1);

namespace App\Http\Requests;

class SipandaiMeetingDetailRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /**
             * Identifier pertemuan pada URL SIPANDAI.
             *
             * @example 445
             */
            'meeting_id' => ['required', 'integer', 'min:1'],
            /**
             * Identifier `courses_id` pada URL SIPANDAI.
             *
             * `course_id` lama masih diterima sebagai fallback kompatibilitas.
             *
             * @example 8861
             */
            'courses_id' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'meeting_id.required' => 'Parameter meeting_id wajib diisi.',
            'meeting_id.integer' => 'Parameter meeting_id harus berupa angka.',
            'meeting_id.min' => 'Parameter meeting_id tidak valid.',
            'courses_id.required' => 'Parameter courses_id wajib diisi. Buka pertemuan dari halaman Kelas.',
            'courses_id.integer' => 'Parameter courses_id harus berupa angka.',
            'courses_id.min' => 'Parameter courses_id tidak valid.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'meeting_id' => $this->route('meeting_id', $this->input('meeting_id')),
            'courses_id' => $this->input('courses_id', $this->input('course_id')),
        ]);
    }

    public function meetingId(): string
    {
        return (string) $this->validated('meeting_id');
    }

    public function coursesId(): string
    {
        return (string) $this->validated('courses_id');
    }
}
