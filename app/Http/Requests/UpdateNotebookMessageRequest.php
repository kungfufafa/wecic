<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\NotebookPractice;

class UpdateNotebookMessageRequest extends ApiFormRequest
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
        $max = NotebookPractice::MAX_ITEMS;

        return [
            /**
             * Simpan (true) atau lepas (false) dari Catatan. Wajib bila `practice` tidak dikirim.
             *
             * @example true
             */
            'pinned' => ['required_without:practice', 'boolean'],
            /**
             * Hasil latihan kuis atau flashcard (tanpa AI, tanpa kuota harian). Untuk kuis wajib kirim `choices` (indeks pilihan per soal,
             * `null` bila dilewati): skor dihitung server dari kunci jawaban, dan `score`/`total`/`missed` yang ikut dikirim diabaikan.
             * Untuk flashcard kirim `score`, `total`, dan `missed`. `flagged` adalah soal yang ditandai salah oleh mahasiswa; soal itu
             * tidak dihitung.
             *
             * @example {"choices": [1, 0, null, 2], "flagged": [3]}
             */
            'practice' => ['sometimes', 'required', 'array:score,total,missed,choices,flagged'],
            'practice.choices' => ['sometimes', 'array', 'max:'.$max],
            'practice.choices.*' => ['nullable', 'integer', 'min:0', 'max:9'],
            'practice.score' => ['exclude_without:practice', 'exclude_with:practice.choices', 'required_without:practice.choices', 'integer', 'min:0', 'max:'.$max, 'lte:practice.total'],
            'practice.total' => ['exclude_without:practice', 'exclude_with:practice.choices', 'required_without:practice.choices', 'integer', 'min:0', 'max:'.$max],
            'practice.missed' => ['exclude_with:practice.choices', 'sometimes', 'array', 'max:'.$max],
            'practice.missed.*' => ['integer', 'min:0', 'distinct'],
            'practice.flagged' => ['sometimes', 'array', 'max:'.$max],
            'practice.flagged.*' => ['integer', 'min:0', 'distinct'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'pinned.required_without' => 'Pilih simpan atau lepas dari catatan.',
            'pinned.boolean' => 'Nilai pinned harus true atau false.',
            'practice.required' => 'Hasil latihan tidak boleh kosong.',
            'practice.array' => 'Hasil latihan tidak valid.',
            'practice.choices.array' => 'Pilihan jawaban tidak valid.',
            'practice.choices.max' => 'Terlalu banyak pilihan jawaban.',
            'practice.choices.*.integer' => 'Pilihan jawaban harus berupa angka.',
            'practice.choices.*.min' => 'Pilihan jawaban tidak valid.',
            'practice.choices.*.max' => 'Pilihan jawaban tidak valid.',
            'practice.score.required_without' => 'Kirim skor latihan atau pilihan jawabannya.',
            'practice.score.integer' => 'Skor harus berupa angka.',
            'practice.score.min' => 'Skor tidak valid.',
            'practice.score.max' => 'Skor tidak valid.',
            'practice.score.lte' => 'Skor tidak boleh melebihi jumlah soal.',
            'practice.total.required_without' => 'Kirim jumlah soal latihan.',
            'practice.total.integer' => 'Jumlah soal harus berupa angka.',
            'practice.total.min' => 'Jumlah soal tidak valid.',
            'practice.total.max' => 'Jumlah soal tidak valid.',
            'practice.missed.array' => 'Daftar soal yang salah tidak valid.',
            'practice.missed.max' => 'Daftar soal yang salah terlalu panjang.',
            'practice.missed.*.integer' => 'Nomor soal harus berupa angka.',
            'practice.missed.*.min' => 'Nomor soal tidak valid.',
            'practice.missed.*.distinct' => 'Nomor soal tidak boleh berulang.',
            'practice.flagged.array' => 'Daftar soal yang ditandai tidak valid.',
            'practice.flagged.max' => 'Daftar soal yang ditandai terlalu panjang.',
            'practice.flagged.*.integer' => 'Nomor soal harus berupa angka.',
            'practice.flagged.*.min' => 'Nomor soal tidak valid.',
            'practice.flagged.*.distinct' => 'Nomor soal tidak boleh berulang.',
        ];
    }

    public function hasPinned(): bool
    {
        return $this->has('pinned');
    }

    /**
     * @return array{score?: int, total?: int, missed?: list<int>, choices?: list<int|null>, flagged?: list<int>}|null
     */
    public function practice(): ?array
    {
        $practice = $this->validated('practice');

        if (! is_array($practice) || $practice === []) {
            return null;
        }

        $ints = static fn (mixed $list): array => array_values(array_map('intval', (array) $list));

        return array_filter([
            'score' => isset($practice['score']) ? (int) $practice['score'] : null,
            'total' => isset($practice['total']) ? (int) $practice['total'] : null,
            'missed' => isset($practice['missed']) ? $ints($practice['missed']) : null,
            'flagged' => isset($practice['flagged']) ? $ints($practice['flagged']) : null,
            'choices' => isset($practice['choices'])
                ? array_values(array_map(static fn (mixed $choice): ?int => $choice === null ? null : (int) $choice, (array) $practice['choices']))
                : null,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
