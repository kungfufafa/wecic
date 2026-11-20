<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * Agreeing to send Notebook content to Google Gemini must be an explicit
 * choice of the student, never a side effect of opening the Notebook.
 */
class NotebookAiConsentGrantRequest extends ApiFormRequest
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
             * Persetujuan mengirim isi Notebook ke Google Gemini.
             *
             * @example true
             */
            'accept' => ['required', 'accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'accept.required' => 'Centang persetujuan dulu sebelum mengaktifkan AI Notebook.',
            'accept.accepted' => 'Centang persetujuan dulu sebelum mengaktifkan AI Notebook.',
        ];
    }
}
