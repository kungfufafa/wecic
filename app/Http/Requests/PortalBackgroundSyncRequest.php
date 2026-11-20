<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * Switch background sync (and the stored credentials it needs) on or off.
 * Switching it on needs the portal password whenever none is stored yet,
 * which the controller checks (PortalArchiveController::backgroundSync()).
 */
class PortalBackgroundSyncRequest extends ApiFormRequest
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
             * Aktifkan sinkronisasi di background. Mematikannya menghapus password yang tersimpan.
             *
             * @example true
             */
            'enabled' => ['required', 'boolean'],
            /**
             * Password portal kampus. Wajib saat menyalakan sinkronisasi dan belum ada password tersimpan;
             * diabaikan saat mematikan.
             *
             * @example "********"
             */
            'password' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'enabled.required' => 'Pilih aktif atau nonaktif.',
            'enabled.boolean' => 'Pilih aktif atau nonaktif.',
            'password.string' => 'Password tidak valid.',
            'password.max' => 'Password maksimal 255 karakter.',
        ];
    }
}
