<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;

/**
 * @mixin \Illuminate\Http\Request
 */
class SipandaiAttendanceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'course_id' => ['required', 'integer', 'min:1'],
            'discussion_id' => ['required', 'integer', 'min:1'],
            'meeting_number' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'form_values' => ['sometimes', 'array'],
            'form_values.*' => ['nullable', 'string'],
        ];
    }

    /**
     * Custom messages for validation errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'course_id.required' => 'Parameter course_id wajib diisi.',
            'course_id.integer' => 'Parameter course_id harus berupa angka.',
            'discussion_id.required' => 'Parameter discussion_id wajib diisi.',
            'discussion_id.integer' => 'Parameter discussion_id harus berupa angka.',
            'meeting_number.integer' => 'Nomor pertemuan harus berupa angka.',
            'form_values.array' => 'form_values harus berupa array key-value.',
        ];
    }

    /**
     * Custom attribute mapping.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'course_id' => 'course identifier',
            'discussion_id' => 'discussion identifier',
            'meeting_number' => 'meeting number',
        ];
    }

    /**
     * Normalise form_values to array.
     */
    protected function prepareForValidation(): void
    {
        $laravelRequest = request();

        if (! $laravelRequest instanceof Request) {
            return;
        }

        $formValues = $laravelRequest->get('form_values');

        if ($formValues === null) {
            return;
        }

        if (! is_array($formValues)) {
            $laravelRequest->request->set('form_values', []);
        }
    }
}
