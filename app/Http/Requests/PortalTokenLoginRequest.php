<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * The mobile app's login: the web login's fields, plus a name for the
 * device the token is for.
 */
class PortalTokenLoginRequest extends PortalLoginRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            /**
             * Nama perangkat untuk token ini, mis. model ponsel.
             *
             * @example "Pixel 8"
             */
            'device_name' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'device_name.string' => 'Nama perangkat tidak valid.',
            'device_name.max' => 'Nama perangkat maksimal 100 karakter.',
        ];
    }
}
