<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ClassDetailRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Allow authenticated users
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'id' => 'required|integer|regex:/^\d{4}$/|between:1000,9999',
            'dosen_id' => 'required|integer|regex:/^\d{4}$/|between:1000,9999',
            'course_id' => 'required|integer|regex:/^\d{4}$/|between:8000,9999',
        ];
    }

    /**
     * Get custom error messages for validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'id.required' => 'Class ID is required',
            'id.integer' => 'Class ID must be a number',
            'id.regex' => 'Class ID must be a 4-digit number',
            'id.between' => 'Class ID must be between 1000 and 9999',
            'dosen_id.required' => 'Lecturer ID is required',
            'dosen_id.integer' => 'Lecturer ID must be a number',
            'dosen_id.regex' => 'Lecturer ID must be a 4-digit number',
            'dosen_id.between' => 'Lecturer ID must be between 1000 and 9999',
            'course_id.required' => 'Course ID is required',
            'course_id.integer' => 'Course ID must be a number',
            'course_id.regex' => 'Course ID must be a 4-digit number',
            'course_id.between' => 'Course ID must be between 8000 and 9999',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'id' => 'class identifier',
            'dosen_id' => 'lecturer identifier',
            'course_id' => 'course identifier',
        ];
    }
}
