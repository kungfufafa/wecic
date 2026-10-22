<?php

declare(strict_types=1);

namespace App\Http\Requests;

class UpdateNotebookMessageRequest extends ApiFormRequest
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
             * Simpan (true) atau lepas (false) dari Catatan.
             *
             * @example true
             */
            'pinned' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'pinned.required' => 'Pilih simpan atau lepas dari catatan.',
            'pinned.boolean' => 'Nilai pinned harus true atau false.',
        ];
    }
}
