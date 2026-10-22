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
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            /**
             * Judul baru notebook.
             *
             * @example "Persiapan UAS Basis Data"
             */
            'title' => ['required', 'string', 'max:120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => 'Beri judul notebook.',
            'title.string' => 'Judul notebook tidak valid.',
            'title.max' => 'Judul notebook maksimal 120 karakter.',
        ];
    }
}
