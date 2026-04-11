<?php

declare(strict_types=1);

namespace App\Http\Requests;

class PortalClassDetailByIdRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /**
             * Identifier `id` dari link kelas pada portal SIPANDAI.
             *
             * Endpoint helper ini akan melengkapi `dosen_id` dan `course_id` dari daftar kelas aktif bila diperlukan.
             *
             * @example "2265"
             */
            'id' => ['required', 'string', 'max:255'],
        ];
    }
}
