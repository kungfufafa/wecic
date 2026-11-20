<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * Deleting the account and everything WeCIC keeps for it cannot be undone,
 * so it must be confirmed.
 */
class PortalAccountDestroyRequest extends ApiFormRequest
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
             * Konfirmasi penghapusan akun dan seluruh datanya.
             *
             * @example true
             */
            'confirm' => ['required', 'accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'confirm.required' => 'Konfirmasi dulu sebelum menghapus akun.',
            'confirm.accepted' => 'Konfirmasi dulu sebelum menghapus akun.',
        ];
    }
}
