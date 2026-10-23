<?php

declare(strict_types=1);

namespace App\Http\Requests;

class AskNotebookRequest extends ApiFormRequest
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
             * Pertanyaan untuk sumber-sumber notebook.
             *
             * @example "Apa beda 2NF dan 3NF?"
             */
            'question' => ['required', 'string', 'min:2', 'max:2000'],
            /**
             * Batasi konteks ke sumber-sumber ini saja (kosong = semua sumber yang siap).
             *
             * @example [11, 12]
             */
            'source_ids' => ['sometimes', 'nullable', 'array', 'max:'.max(60, (int) config('notebook.limits.max_sources', 30), (int) config('notebook.limits.max_course_sources', 80))],
            'source_ids.*' => ['integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'question.required' => 'Tulis pertanyaanmu dulu.',
            'question.string' => 'Pertanyaan tidak valid.',
            'question.min' => 'Pertanyaan terlalu pendek.',
            'question.max' => 'Pertanyaan maksimal 2.000 karakter.',
            'source_ids.array' => 'Daftar sumber tidak valid.',
            'source_ids.max' => 'Terlalu banyak sumber dipilih.',
            'source_ids.*.integer' => 'Id sumber harus berupa angka.',
            'source_ids.*.min' => 'Id sumber tidak valid.',
        ];
    }

    public function question(): string
    {
        return trim((string) $this->validated('question'));
    }

    /**
     * @return list<int>
     */
    public function sourceIds(): array
    {
        return array_values(array_unique(array_map('intval', (array) ($this->validated('source_ids') ?? []))));
    }
}
