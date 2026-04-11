<?php

declare(strict_types=1);

namespace App\Http\Requests;

class SipandaiExamDetailRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /**
             * Diisi otomatis dari placeholder path `{id}` pada route proxied ujian.
             *
             * @ignoreParam
             */
            'id' => ['required', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'id.required' => 'Parameter id wajib diisi.',
            'id.integer' => 'Parameter id harus berupa angka.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'id' => $this->route('id', $this->input('id')),
        ]);
    }

    public function examId(): int
    {
        return (int) $this->validated('id');
    }
}
