<?php

declare(strict_types=1);

namespace App\Http\Requests;

class SaveDraftRequest extends ApiFormRequest
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
            'draft_content' => ['nullable', 'string'],
        ];
    }
}
