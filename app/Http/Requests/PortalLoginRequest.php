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
            /**
             * Nyalakan (`true`) atau matikan (`false`) sinkronisasi di background dengan login ini.
             * Hanya saat menyala password disimpan terenkripsi di server. Jika tidak dikirim, pilihan
             * sebelumnya tetap berlaku (bawaan: mati).
             *
             * @example false
             */
            'background_sync' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'username.required' => 'Username dan password harus diisi',
            'password.required' => 'Username dan password harus diisi',
            'background_sync.boolean' => 'Pilihan sinkronisasi background tidak valid.',
        ];
    }
}
