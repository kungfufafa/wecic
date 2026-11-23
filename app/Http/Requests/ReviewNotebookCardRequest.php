<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\NotebookReviewScheduler;
use Illuminate\Validation\Rule;

/**
 * One answer of "Ulasan harian": how well the student remembered the card.
 */
class ReviewNotebookCardRequest extends ApiFormRequest
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
        return [
            /**
             * Seberapa ingat: `lagi` (lupa, muncul lagi 10 menit lagi), `sulit`, `bisa`, atau `mudah`.
             *
             * @example "bisa"
             */
            'grade' => ['required', 'string', Rule::in(NotebookReviewScheduler::GRADES)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'grade.required' => 'Pilih seberapa ingat kamu dengan kartu ini.',
            'grade.string' => 'Pilihan tidak valid.',
            'grade.in' => 'Pilih lagi, sulit, bisa, atau mudah.',
        ];
    }

    public function grade(): string
    {
        return (string) $this->validated('grade');
    }
}
