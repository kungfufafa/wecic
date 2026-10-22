<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * The class whose materials are archived; its portal link parameters are
 * looked up in the student's own class list, never taken from the client.
 */
class PortalArchiveClassRequest extends ApiFormRequest
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
             * Id kelas dari daftar kelas (`/api/class`), sama dengan `id` di `/class/{id}`.
             *
             * @example 2265
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
            'id.required' => 'Pilih kelas yang materinya ingin diarsipkan.',
            'id.integer' => 'Id kelas harus berupa angka.',
            'id.min' => 'Id kelas tidak valid.',
        ];
    }

    public function classId(): string
    {
        return (string) $this->validated('id');
    }
}
