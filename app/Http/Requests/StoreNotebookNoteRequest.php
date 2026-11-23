<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\NotebookMessage;

/**
 * A note the student writes, or "Salin ke catatan" from an answer
 * (`from_message_id`: its text and citations are copied by the server).
 */
class StoreNotebookNoteRequest extends ApiFormRequest
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
             * Isi catatan (Markdown), paling banyak 10.000 karakter. Boleh kosong untuk catatan baru; bila `from_message_id`
             * dikirim tanpa `content`, isinya disalin dari pesan itu.
             *
             * @example "## Normalisasi\n- 1NF: nilai atomik"
             */
            'content' => ['nullable', 'string', 'max:'.NotebookMessage::MAX_NOTE_CHARS],
            /**
             * Salin ke catatan: id jawaban atau hasil studio berteks di notebook ini. Teks dan rujukannya ikut disalin.
             *
             * @example 40
             */
            'from_message_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'content.string' => 'Isi catatan tidak valid.',
            'content.max' => 'Catatan maksimal 10.000 karakter.',
            'from_message_id.integer' => 'Pesan yang disalin tidak valid.',
            'from_message_id.min' => 'Pesan yang disalin tidak valid.',
        ];
    }

    public function content(): ?string
    {
        $content = $this->validated('content');

        return is_string($content) ? $content : null;
    }

    public function fromMessageId(): ?int
    {
        $id = $this->validated('from_message_id');

        return $id === null ? null : (int) $id;
    }
}
