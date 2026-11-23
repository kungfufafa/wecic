<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\NotebookReviewCard;
use Illuminate\Validation\Rule;

/**
 * Edit a review card's sides, or suspend it (it leaves the queue) and
 * bring it back.
 */
class UpdateNotebookReviewCardRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $max = NotebookReviewCard::MAX_SIDE_CHARS;

        return [
            /**
             * Sisi depan baru (pertanyaan atau istilah), paling banyak 2.000 karakter. Wajib bila `back` dan `suspended` tidak dikirim.
             *
             * @example "Apa syarat 2NF?"
             */
            'front' => [Rule::requiredIf(fn (): bool => ! $this->hasAny(['back', 'suspended'])), 'string', 'max:'.$max],
            /**
             * Sisi belakang baru (jawaban), paling banyak 2.000 karakter.
             *
             * @example "Sudah 1NF dan tidak ada ketergantungan parsial pada kunci."
             */
            'back' => ['sometimes', 'required', 'string', 'max:'.$max],
            /**
             * true menahan kartu (tidak muncul di ulasan), false mengembalikannya.
             *
             * @example true
             */
            'suspended' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'front.required' => 'Kirim isi kartu atau status tahannya.',
            'front.string' => 'Sisi depan tidak valid.',
            'front.max' => 'Sisi depan maksimal 2.000 karakter.',
            'back.required' => 'Sisi belakang tidak boleh kosong.',
            'back.string' => 'Sisi belakang tidak valid.',
            'back.max' => 'Sisi belakang maksimal 2.000 karakter.',
            'suspended.boolean' => 'Nilai suspended harus true atau false.',
        ];
    }

    /**
     * @return array{front?: string, back?: string, suspended?: bool}
     */
    public function changes(): array
    {
        $validated = $this->validated();

        return array_filter([
            'front' => isset($validated['front']) ? trim((string) $validated['front']) : null,
            'back' => isset($validated['back']) ? trim((string) $validated['back']) : null,
            'suspended' => array_key_exists('suspended', $validated) ? $this->boolean('suspended') : null,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
