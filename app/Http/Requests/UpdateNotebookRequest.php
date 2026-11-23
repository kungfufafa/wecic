<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class UpdateNotebookRequest extends ApiFormRequest
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
             * Judul baru notebook. Wajib bila `context_source_ids` tidak dikirim.
             *
             * @example "Persiapan UAS Basis Data"
             */
            'title' => [Rule::requiredIf(fn (): bool => ! $this->has('context_source_ids')), 'string', 'max:120'],
            /**
             * Sumber yang dicentang sebagai konteks AI, disimpan di server agar web dan aplikasi membuka pilihan yang sama.
             * `null` berarti semua sumber. Setiap id harus sumber notebook ini; sumber yang dihapus kemudian dibuang sendiri,
             * dan sumber baru ikut tercentang.
             *
             * @example [11, 12]
             */
            'context_source_ids' => ['sometimes', 'present', 'nullable', 'array', 'max:200'],
            'context_source_ids.*' => ['integer', 'min:1', 'distinct'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => 'Beri judul notebook.',
            'context_source_ids.array' => 'Pilihan sumber tidak valid.',
            'context_source_ids.max' => 'Terlalu banyak sumber dipilih.',
            'context_source_ids.*.integer' => 'Id sumber harus berupa angka.',
            'context_source_ids.*.min' => 'Id sumber tidak valid.',
            'context_source_ids.*.distinct' => 'Id sumber tidak boleh berulang.',
            'title.string' => 'Judul notebook tidak valid.',
            'title.max' => 'Judul notebook maksimal 120 karakter.',
        ];
    }

    public function hasTitle(): bool
    {
        return $this->has('title');
    }

    public function hasContextSourceIds(): bool
    {
        return $this->has('context_source_ids');
    }

    /**
     * @return list<int>|null
     */
    public function contextSourceIds(): ?array
    {
        $ids = $this->validated('context_source_ids');

        return is_array($ids) ? array_values(array_map('intval', $ids)) : null;
    }
}
