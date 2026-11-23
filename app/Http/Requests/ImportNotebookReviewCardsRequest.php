<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * "Simpan ke ulasan": the items of a flashcards output, or the questions
 * missed in the last practice of a quiz or a flashcards output.
 */
class ImportNotebookReviewCardsRequest extends ApiFormRequest
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
             * true hanya menyimpan soal atau kartu yang salah di latihan terakhir (`context.practice.missed`). Kuis selalu
             * begini; flashcard tanpa ini menyimpan semua kartunya.
             *
             * @example true
             */
            'only_missed' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'only_missed.boolean' => 'Nilai only_missed harus true atau false.',
        ];
    }

    public function onlyMissed(): bool
    {
        return $this->boolean('only_missed');
    }
}
