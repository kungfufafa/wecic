<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * Agreeing to send Notebook content to the AI provider must be an explicit
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
             * Persetujuan mengirim isi Notebook ke penyedia AI yang dipakai server (`provider` di GET /ai-consent).
             *
             * @example true
             */
            'accept' => ['required', 'accepted'],
            /**
             * `consent_key` dari GET /ai-consent (atau `ai_provider.consent_key` notebook) yang ditampilkan ke mahasiswa.
             * Bila server sudah berganti penyedia, persetujuan ditolak dengan 409 beserta status terbaru.
             *
             * @example "gemini:free"
             */
            'consent_key' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /**
     * The consent key the student was shown, when the client sent it.
     */
    public function shownConsentKey(): ?string
    {
        $key = $this->validated('consent_key');

        return is_string($key) && $key !== '' ? $key : null;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'accept.required' => 'Centang persetujuan dulu sebelum mengaktifkan AI Notebook.',
            'accept.accepted' => 'Centang persetujuan dulu sebelum mengaktifkan AI Notebook.',
            'consent_key.string' => 'Data persetujuan tidak valid. Muat ulang halaman, lalu coba lagi.',
            'consent_key.max' => 'Data persetujuan tidak valid. Muat ulang halaman, lalu coba lagi.',
        ];
    }
}
