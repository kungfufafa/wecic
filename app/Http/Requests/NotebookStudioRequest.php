<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\NotebookAssistant;
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
}
