<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\NotebookAssistant;
use Illuminate\Validation\Rule;

class AskNotebookRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
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
            /**
             * Cara menjawab: `answer` (jawab langsung, bawaan), `tutor` (membimbing selangkah demi selangkah tanpa menyelesaikan tugas), atau `ringkas` (paling banyak 5 poin).
             *
             * @example "tutor"
             */
            'mode' => ['sometimes', 'nullable', 'string', Rule::in(array_keys(NotebookAssistant::ASK_MODES))],
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
            'mode.string' => 'Mode jawaban tidak valid.',
            'mode.in' => 'Mode jawaban tidak dikenal. Pilih jawab langsung, mode tutor, atau ringkas.',
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

    /**
     * @return key-of<NotebookAssistant::ASK_MODES>
     */
    public function mode(): string
    {
        $mode = (string) ($this->validated('mode') ?? '');

        return array_key_exists($mode, NotebookAssistant::ASK_MODES) ? $mode : NotebookAssistant::MODE_ANSWER;
    }
}
