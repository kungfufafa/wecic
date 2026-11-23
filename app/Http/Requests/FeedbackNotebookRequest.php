<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\NotebookAssistant;
use Illuminate\Validation\Rule;

class FeedbackNotebookRequest extends ApiFormRequest
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
             * Soal, konsep, atau instruksi tugas yang dijawab mahasiswa.
             *
             * @example "Mengapa bentuk normal ketiga menghapus ketergantungan transitif?"
             */
            'prompt' => ['required', 'string', 'min:2', 'max:1500'],
            /**
             * Jawaban, penjelasan, atau kerangka tulisan mahasiswa sendiri. Dinilai sebagai data, bukan instruksi.
             *
             * @example "3NF menghapus ketergantungan transitif karena atribut bukan kunci hanya boleh bergantung pada kunci primer."
             */
            'answer' => ['required', 'string', 'min:20', 'max:6000'],
            /**
             * Jenis umpan balik: `practice_answer` (jawaban soal latihan), `explain_concept` (penjelasan konsep dengan kata-kata sendiri),
             * atau `outline_check` (cakupan konsep, struktur, dan celah argumen sebuah kerangka).
             *
             * @example "practice_answer"
             */
            'mode' => ['required', 'string', Rule::in(array_keys(NotebookAssistant::FEEDBACK_MODES))],
            /**
             * Batasi konteks ke sumber-sumber ini saja (kosong = semua sumber yang siap). Semuanya harus milik notebook ini.
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
            'prompt.required' => 'Soal atau konsep yang dijawab belum ada.',
            'prompt.string' => 'Soal tidak valid.',
            'prompt.min' => 'Soal terlalu pendek.',
            'prompt.max' => 'Soal maksimal 1.500 karakter.',
            'answer.required' => 'Tulis jawabanmu dulu.',
            'answer.string' => 'Jawaban tidak valid.',
            'answer.min' => 'Jawabanmu terlalu pendek untuk dinilai. Tulis minimal 20 karakter.',
            'answer.max' => 'Jawaban maksimal 6.000 karakter.',
            'mode.required' => 'Pilih jenis umpan balik.',
            'mode.string' => 'Jenis umpan balik tidak valid.',
            'mode.in' => 'Jenis umpan balik tidak dikenal.',
            'source_ids.array' => 'Daftar sumber tidak valid.',
            'source_ids.max' => 'Terlalu banyak sumber dipilih.',
            'source_ids.*.integer' => 'Id sumber harus berupa angka.',
            'source_ids.*.min' => 'Id sumber tidak valid.',
        ];
    }

    public function prompt(): string
    {
        return trim((string) $this->validated('prompt'));
    }

    public function answer(): string
    {
        return trim((string) $this->validated('answer'));
    }

    /**
     * @return key-of<NotebookAssistant::FEEDBACK_MODES>
     */
    public function mode(): string
    {
        return (string) $this->validated('mode');
    }

    /**
     * @return list<int>
     */
    public function sourceIds(): array
    {
        return array_values(array_unique(array_map('intval', (array) ($this->validated('source_ids') ?? []))));
    }
}
