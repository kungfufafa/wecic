<?php

declare(strict_types=1);

namespace App\Http\Requests;

abstract class PortalClassParameterRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /**
             * Class identifier dari URL portal `act=download`.
             *
             * Pastikan nilai ini adalah `id` kelas, bukan `course_id`.
             *
             * @example 2265
             */
            'id' => ['required', 'integer', 'regex:/^\d{4}$/', 'between:1000,9999'],
            /**
             * Identifier dosen dari URL portal `act=download`.
             *
             * @example 5240
             */
            'dosen_id' => ['required', 'integer', 'regex:/^\d{4}$/', 'between:1000,9999'],
            /**
             * Identifier mata kuliah dari URL portal `act=download`.
             *
             * @example 8810
             */
            'course_id' => ['required', 'integer', 'regex:/^\d{4}$/', 'between:8000,9999'],
        ];
    }

    public function messages(): array
    {
        return [
            'id.required' => 'Class ID (parameter "id") is required',
            'id.integer' => 'Class ID must be a number',
            'id.regex' => 'Class ID must be a 4-digit number (1000-9999)',
            'id.between' => 'Class ID must be between 1000 and 9999',
            'dosen_id.required' => 'Lecturer ID (parameter "dosen_id") is required',
            'dosen_id.integer' => 'Lecturer ID must be a number',
            'dosen_id.regex' => 'Lecturer ID must be a 4-digit number (1000-9999)',
            'dosen_id.between' => 'Lecturer ID must be between 1000 and 9999',
            'course_id.required' => 'Course ID (parameter "course_id") is required',
            'course_id.integer' => 'Course ID must be a number',
            'course_id.regex' => 'Course ID must be a 4-digit number (8000-9999)',
            'course_id.between' => 'Course ID must be between 8000 and 9999. Example: course_id=8810 or course_id=8861',
        ];
    }

    public function attributes(): array
    {
        return [
            'id' => 'class identifier',
            'dosen_id' => 'lecturer identifier',
            'course_id' => 'course identifier',
        ];
    }
}
