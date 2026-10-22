<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * Open (or make) the course notebook of one class.
 */
class StoreCourseNotebookRequest extends ApiFormRequest
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
             * `course_id` kelas (SIPANDAI `courses_id`), sama dengan `course_key` arsip materi.
             *
             * @example "8810"
             */
            'course_key' => ['required', 'string', 'max:64', 'regex:/^\d+$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'course_key.required' => 'Mata kuliah belum dipilih.',
            'course_key.string' => 'Mata kuliah tidak valid.',
            'course_key.max' => 'Mata kuliah tidak valid.',
            'course_key.regex' => 'Mata kuliah tidak valid.',
        ];
    }

    public function courseKey(): string
    {
        return (string) $this->validated('course_key');
    }
}
