<?php

declare(strict_types=1);

namespace App\Http\Requests;

class PortalMeetingDetailByIdRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /**
             * Identifier pertemuan dari link diskusi atau materi SIPANDAI.
             *
             * @example 445
             */
            'meeting_id' => ['required', 'integer', 'min:1'],
            /**
             * Identifier `courses_id` pada URL pertemuan.
             *
             * `course_id` lama masih diterima sebagai fallback kompatibilitas.
             *
             * @example 8861
             */
            'courses_id' => ['required', 'integer', 'min:1'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
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
