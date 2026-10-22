<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * Add one meeting (note and course files) to its course notebook.
 */
class AddNotebookMeetingRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            /**
             * `id` pertemuan dari link diskusi SIPANDAI.
             *
             * @example "445"
             */
            'meeting_id' => ['required', 'string', 'max:64', 'regex:/^\d+$/'],
            /**
             * `courses_id` pertemuan; menentukan notebook mata kuliah tujuannya.
             *
             * @example "8810"
             */
            'courses_id' => ['required', 'string', 'max:64', 'regex:/^\d+$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'meeting_id.required' => 'Pertemuan belum dipilih.',
            'meeting_id.regex' => 'Id pertemuan tidak valid.',
            'meeting_id.max' => 'Id pertemuan tidak valid.',
            'courses_id.required' => 'Mata kuliah pertemuan ini tidak diketahui.',
            'courses_id.regex' => 'Id mata kuliah tidak valid.',
            'courses_id.max' => 'Id mata kuliah tidak valid.',
        ];
    }

    /**
     * @return array{meeting_id: string, courses_id: string}
     */
    public function meetingParams(): array
    {
        return [
            'meeting_id' => (string) $this->validated('meeting_id'),
            'courses_id' => (string) $this->validated('courses_id'),
        ];
    }
}
