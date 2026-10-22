<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * A new notebook, optionally for one course: its archived files become
 * the first sources.
 */
class StoreNotebookRequest extends ApiFormRequest
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
             * Judul notebook.
             *
             * @example "Persiapan UTS Basis Data"
             */
            'title' => ['required', 'string', 'max:120'],
            /**
             * `course_key` dari arsip materi (`/api/archive/files`); semua berkasnya jadi sumber.
             *
             * @example "8810"
             */
            'course_key' => ['sometimes', 'nullable', 'string', 'max:64'],
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
            'course_key.string' => 'Mata kuliah tidak valid.',
            'course_key.max' => 'Mata kuliah tidak valid.',
        ];
    }
}
