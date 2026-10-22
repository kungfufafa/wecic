<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * Switch background sync (and the stored credentials it needs) on or off.
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
        ];
    }
}
