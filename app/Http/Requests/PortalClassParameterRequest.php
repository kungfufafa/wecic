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
            'id' => ['required', 'integer', 'min:1', 'digits_between:1,10'],
            /**
             * Identifier dosen dari URL portal `act=download`.
             *
             * @example 5240
             */
            'dosen_id' => ['required', 'integer', 'min:1'],
            /**
             * Identifier mata kuliah dari URL portal `act=download`.
             *
             * @example 8810
             */
            'course_id' => ['required', 'integer', 'min:1', 'digits_between:1,10'],
        ];
    }

    public function messages(): array
    {
        return [
            'id.required' => 'Class ID (parameter "id") is required',
            'id.integer' => 'Class ID must be a number',
            'id.min' => 'Class ID must be a valid positive number',
            'id.digits_between' => 'Class ID must be a positive number of at most 10 digits',
            'dosen_id.required' => 'Lecturer ID (parameter "dosen_id") is required',
            'dosen_id.integer' => 'Lecturer ID must be a number',
            'dosen_id.min' => 'Lecturer ID must be a valid positive number',
            'course_id.required' => 'Course ID (parameter "course_id") is required',
            'course_id.integer' => 'Course ID must be a number',
            'course_id.min' => 'Course ID must be a valid positive number. Example: course_id=8810 or course_id=8861',
            'course_id.digits_between' => 'Course ID must be a positive number of at most 10 digits',
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
