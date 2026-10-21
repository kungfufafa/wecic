<?php

declare(strict_types=1);

namespace App\Http\Requests;

class PortalLoginRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /**
             * NIM atau username mahasiswa pada portal kampus.
             *
             * @example "20990000001"
             */
            'username' => ['required', 'string', 'max:255'],
            /**
             * Kata sandi akun portal kampus.
             *
             * @example "********"
             */
            'password' => ['required', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'username.required' => 'Username dan password harus diisi',
            'password.required' => 'Username dan password harus diisi',
        ];
    }
}
