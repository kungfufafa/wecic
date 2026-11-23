<?php

declare(strict_types=1);

namespace App\Http\Requests;

class NotebookReviewQueueRequest extends ApiFormRequest
{
    public const DEFAULT_LIMIT = 50;

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
             * Kartu paling banyak dalam satu antrean (1 sampai 100, bawaan 50).
             *
             * @example 50
             */
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'limit.integer' => 'Jumlah kartu harus berupa angka.',
            'limit.min' => 'Jumlah kartu paling sedikit 1.',
            'limit.max' => 'Jumlah kartu paling banyak 100.',
        ];
    }

    public function limit(): int
    {
        return $this->filled('limit') ? (int) $this->validated('limit') : self::DEFAULT_LIMIT;
    }
}
