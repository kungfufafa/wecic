<?php

declare(strict_types=1);

namespace App\Http\Requests;

class NotebookSourceChunksRequest extends ApiFormRequest
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
             * Posisi potongan yang dirujuk (`citations.*.position`). Jendela berisi potongan di sekitarnya. Kosong berarti dari awal sumber.
             *
             * @example 12
             */
            'around' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            /**
             * Untuk rujukan lama tanpa `position`: id pesan yang memuat rujukan. Bersama `n`, posisi dicari dari teks kutipannya.
             *
             * @example 40
             */
            'message' => ['nullable', 'integer', 'min:1', 'required_with:n'],
            /**
             * Nomor rujukan `[n]` di pesan `message`.
             *
             * @example 2
             */
            'n' => ['nullable', 'integer', 'min:1', 'max:9999', 'required_with:message'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'around.integer' => 'Posisi potongan harus berupa angka.',
            'around.min' => 'Posisi potongan tidak valid.',
            'around.max' => 'Posisi potongan tidak valid.',
            'message.integer' => 'Id pesan harus berupa angka.',
            'message.min' => 'Id pesan tidak valid.',
            'message.required_with' => 'Kirim id pesan bersama nomor rujukannya.',
            'n.integer' => 'Nomor rujukan harus berupa angka.',
            'n.min' => 'Nomor rujukan tidak valid.',
            'n.max' => 'Nomor rujukan tidak valid.',
            'n.required_with' => 'Kirim nomor rujukan bersama id pesannya.',
        ];
    }

    public function around(): ?int
    {
        return $this->filled('around') ? (int) $this->validated('around') : null;
    }

    public function citedMessageId(): ?int
    {
        return $this->filled('message') ? (int) $this->validated('message') : null;
    }

    public function citationNumber(): ?int
    {
        return $this->filled('n') ? (int) $this->validated('n') : null;
    }
}
