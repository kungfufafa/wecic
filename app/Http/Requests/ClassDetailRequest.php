<?php

declare(strict_types=1);

namespace App\Http\Requests;

class ClassDetailRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /**
             * Identifier `id` dari URL portal `act=download`.
             *
             * @example 2265
             */
            'id' => ['required', 'integer', 'min:1', 'digits_between:1,10'],
            /**
             * Identifier dosen dari URL portal `act=download`.
             *
             * Opsional jika API diminta melakukan resolusi parameter otomatis berdasarkan `id`.
             *
             * @example 5240
             */
            'dosen_id' => ['sometimes', 'nullable', 'integer', 'min:1', 'required_with:course_id'],
            /**
             * Identifier mata kuliah dari URL portal `act=download`.
             *
             * Opsional jika API diminta melakukan resolusi parameter otomatis berdasarkan `id`.
             *
             * @example 8810
             */
            'course_id' => ['sometimes', 'nullable', 'integer', 'min:1', 'digits_between:1,10', 'required_with:dosen_id'],
        ];
    }

    public function messages(): array
    {
        return [
            'id.required' => 'Parameter id wajib diisi.',
            'id.integer' => 'Parameter id harus berupa angka.',
            'id.min' => 'Parameter id harus berupa angka positif yang valid.',
            'id.digits_between' => 'Parameter id harus berupa angka positif yang valid.',
            'dosen_id.required_with' => 'Parameter dosen_id wajib diisi jika course_id dikirim.',
            'dosen_id.integer' => 'Parameter dosen_id harus berupa angka.',
            'dosen_id.min' => 'Parameter dosen_id harus berupa angka positif yang valid.',
            'course_id.required_with' => 'Parameter course_id wajib diisi jika dosen_id dikirim.',
            'course_id.integer' => 'Parameter course_id harus berupa angka.',
            'course_id.min' => 'Parameter course_id harus berupa angka positif yang valid.',
            'course_id.digits_between' => 'Parameter course_id harus berupa angka positif yang valid.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'id' => $this->route('id', $this->input('id')),
        ]);
    }
}
