<?php

declare(strict_types=1);

namespace App\Http\Requests;

class StoreNotebookRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'course_id' => ['nullable', 'string', 'max:100'],
            'course_name' => ['nullable', 'string', 'max:255'],
            'meeting_id' => ['nullable', 'string', 'max:100'],
            'meeting_number' => ['nullable', 'string', 'max:50'],
            'sources' => ['nullable', 'array'],
            'sources.*.title' => ['nullable', 'string', 'max:255'],
            'sources.*.type' => ['nullable', 'string', 'max:50'],
            'sources.*.content' => ['nullable', 'string'],
            'sources.*.file_url' => ['nullable', 'string', 'max:500'],
            'draft_content' => ['nullable', 'string'],
            'notes' => ['nullable', 'array'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => 'Judul notebook wajib diisi.',
            'title.max' => 'Judul notebook maksimal 255 karakter.',
        ];
    }
}
