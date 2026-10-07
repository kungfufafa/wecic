<?php

declare(strict_types=1);

namespace App\Http\Requests;

class UpdateNotebookRequest extends ApiFormRequest
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
            'title' => ['sometimes', 'string', 'max:255'],
            'sources' => ['nullable', 'array'],
            'sources.*.title' => ['nullable', 'string', 'max:255'],
            'sources.*.type' => ['nullable', 'string', 'max:50'],
            'sources.*.content' => ['nullable', 'string'],
            'sources.*.file_url' => ['nullable', 'string', 'max:500'],
            'draft_content' => ['nullable', 'string'],
            'notes' => ['nullable', 'array'],
        ];
    }
}
