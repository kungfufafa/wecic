<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\NotebookAssistant;
use App\Services\NotebookPractice;
use Illuminate\Validation\Rule;

class NotebookStudioRequest extends ApiFormRequest
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
             * Jenis hasil: `summary`, `study_guide`, `assignment_plan`, `faq`, `glossary`, `quiz`, `flashcards`, atau `mind_map`.
             *
             * @example "assignment_plan"
             */
            'kind' => ['required', 'string', Rule::in(array_keys(NotebookAssistant::STUDIO_KINDS))],
            /**
             * Batasi konteks ke sumber-sumber ini saja (kosong = semua sumber yang siap).
             *
             * @example [11, 12]
             */
            'source_ids' => ['sometimes', 'nullable', 'array', 'max:'.max(60, (int) config('notebook.limits.max_sources', 30), (int) config('notebook.limits.max_course_sources', 80))],
            'source_ids.*' => ['integer', 'min:1'],
            /**
             * Instruksi atau fokus tugas, untuk `assignment_plan` (opsional untuk jenis lain).
             *
             * @example "Makalah 5 halaman tentang normalisasi basis data, kumpul pertemuan 7."
             */
            'focus' => ['sometimes', 'nullable', 'string', 'max:1500'],
            /**
             * Jumlah dan tingkat kesulitan, untuk `quiz` (5–15 soal) dan `flashcards` (5–20 kartu).
             *
             * @example {"count": 5, "difficulty": "sulit"}
             */
            'options' => ['sometimes', 'nullable', 'array:count,difficulty', 'prohibited_unless:kind,quiz,flashcards'],
            'options.count' => ['sometimes', 'nullable', 'integer', 'min:'.NotebookAssistant::MIN_PRACTICE_ITEMS, 'max:'.NotebookAssistant::maxPracticeItems((string) $this->input('kind'))],
            'options.difficulty' => ['sometimes', 'nullable', 'string', Rule::in(array_keys(NotebookAssistant::DIFFICULTIES))],
            /**
             * Latihan dari soal yang salah (hanya `quiz`): id kuis atau flashcard asal dan nomor soal (mulai 0) yang salah.
             * Server menyusun fokusnya dari teks soal-soal itu; `focus` yang dikirim diabaikan.
             *
             * @example {"message_id": 40, "missed": [1, 4]}
             */
            'practice' => ['sometimes', 'nullable', 'array:message_id,missed', 'prohibited_unless:kind,quiz'],
            'practice.message_id' => ['required_with:practice', 'integer', 'min:1'],
            'practice.missed' => ['required_with:practice', 'array', 'min:1', 'max:'.NotebookPractice::MAX_ITEMS],
            'practice.missed.*' => ['integer', 'min:0', 'distinct'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'kind.required' => 'Pilih jenis hasil yang ingin dibuat.',
            'kind.string' => 'Jenis hasil tidak valid.',
            'kind.in' => 'Jenis hasil tidak dikenal.',
            'source_ids.array' => 'Daftar sumber tidak valid.',
            'source_ids.max' => 'Terlalu banyak sumber dipilih.',
            'source_ids.*.integer' => 'Id sumber harus berupa angka.',
            'source_ids.*.min' => 'Id sumber tidak valid.',
            'focus.string' => 'Fokus tugas tidak valid.',
            'focus.max' => 'Fokus tugas maksimal 1.500 karakter.',
            'options.array' => 'Pilihan latihan tidak valid.',
            'options.prohibited_unless' => 'Jumlah dan tingkat kesulitan hanya untuk kuis dan flashcard.',
            'options.count.integer' => 'Jumlah soal harus berupa angka.',
            'options.count.min' => 'Jumlah paling sedikit '.NotebookAssistant::MIN_PRACTICE_ITEMS.'.',
            'options.count.max' => 'Jumlah paling banyak '.NotebookAssistant::maxPracticeItems((string) $this->input('kind')).' untuk '.mb_strtolower(NotebookAssistant::STUDIO_KINDS[(string) $this->input('kind')] ?? 'jenis ini').'.',
            'options.difficulty.string' => 'Tingkat kesulitan tidak valid.',
            'options.difficulty.in' => 'Tingkat kesulitan tidak dikenal. Pilih campuran, dasar, atau sulit.',
            'practice.array' => 'Latihan dari soal yang salah tidak valid.',
            'practice.prohibited_unless' => 'Latihan dari soal yang salah selalu berupa kuis.',
            'practice.message_id.required_with' => 'Pilih kuis asal latihannya.',
            'practice.message_id.integer' => 'Id kuis asal tidak valid.',
            'practice.message_id.min' => 'Id kuis asal tidak valid.',
            'practice.missed.required_with' => 'Pilih soal yang salah dulu.',
            'practice.missed.array' => 'Daftar soal yang salah tidak valid.',
            'practice.missed.min' => 'Pilih soal yang salah dulu.',
            'practice.missed.max' => 'Terlalu banyak soal yang salah dipilih.',
            'practice.missed.*.integer' => 'Nomor soal harus berupa angka.',
            'practice.missed.*.min' => 'Nomor soal tidak valid.',
            'practice.missed.*.distinct' => 'Nomor soal tidak boleh berulang.',
        ];
    }

    public function kind(): string
    {
        return (string) $this->validated('kind');
    }

    /**
     * @return list<int>
     */
    public function sourceIds(): array
    {
        return array_values(array_unique(array_map('intval', (array) ($this->validated('source_ids') ?? []))));
    }

    public function focus(): ?string
    {
        $focus = trim((string) ($this->validated('focus') ?? ''));

        return $focus !== '' ? $focus : null;
    }

    /**
     * @return array{count?: int, difficulty?: key-of<NotebookAssistant::DIFFICULTIES>}
     */
    public function options(): array
    {
        $options = (array) ($this->validated('options') ?? []);

        return array_filter([
            'count' => isset($options['count']) ? (int) $options['count'] : null,
            'difficulty' => isset($options['difficulty']) ? (string) $options['difficulty'] : null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array{message_id: int, missed: list<int>}|null
     */
    public function practice(): ?array
    {
        $practice = $this->validated('practice');

        if (! is_array($practice)) {
            return null;
        }

        return [
            'message_id' => (int) $practice['message_id'],
            'missed' => array_values(array_unique(array_map('intval', (array) $practice['missed']))),
        ];
    }
}
