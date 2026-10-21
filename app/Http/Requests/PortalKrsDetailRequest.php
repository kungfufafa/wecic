<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * KRS id from the student's own Histori KRS (F14).
 *
 * The id has no digit limit (portal ids are already 5 digits). Whether it
 * belongs to the student is checked against the advising snapshot by the
 * controller, which answers 404 for any other id (anti-IDOR).
 */
class PortalKrsDetailRequest extends ApiFormRequest
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
             * Diisi otomatis dari placeholder path `{id}`: `krs_id` dari `GET /api/academic/advising` (`krs_history[].krs_id`).
             *
             * @ignoreParam
             */
            'id' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'id.required' => 'Parameter id KRS wajib diisi.',
            'id.integer' => 'Parameter id KRS harus berupa angka.',
            'id.min' => 'Parameter id KRS harus berupa angka positif.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'id' => $this->route('id', $this->input('id')),
        ]);
    }

    public function krsId(): int
    {
        return (int) $this->validated('id');
    }
}
