<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * Deleting the whole archive cannot be undone, so it must be confirmed.
 */
class PortalArchiveDestroyRequest extends ApiFormRequest
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
             * Konfirmasi penghapusan seluruh arsip.
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
            'confirm.required' => 'Konfirmasi dulu sebelum menghapus arsip.',
            'confirm.accepted' => 'Konfirmasi dulu sebelum menghapus arsip.',
        ];
    }
}
