<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * Optional target-IPK simulation on the transcript (F16): the IPS needed over
 * the next semester's SKS to reach a target IPK.
 */
class PortalTranscriptRequest extends ApiFormRequest
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
             * Target IPK yang ingin dicapai (0–4). Wajib bersama `next_sks`.
             *
             * @example 3.5
             */
            'target_ipk' => ['nullable', 'numeric', 'between:0,4', 'required_with:next_sks'],
            /**
             * Rencana SKS semester depan (1–24). Wajib bersama `target_ipk`.
             *
             * @example 20
             */
            'next_sks' => ['nullable', 'integer', 'between:1,24', 'required_with:target_ipk'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'target_ipk.numeric' => 'Target IPK harus berupa angka.',
            'target_ipk.between' => 'Target IPK harus di antara 0 dan 4.',
            'target_ipk.required_with' => 'Target IPK wajib diisi bersama rencana SKS.',
            'next_sks.integer' => 'Rencana SKS harus berupa angka bulat.',
            'next_sks.between' => 'Rencana SKS harus di antara 1 dan 24.',
            'next_sks.required_with' => 'Rencana SKS wajib diisi bersama target IPK.',
        ];
    }

    /**
     * @return array{target_ipk: float, next_sks: int}|null
     */
    public function simulation(): ?array
    {
        $targetIpk = $this->validated('target_ipk');
        $nextSks = $this->validated('next_sks');

        if ($targetIpk === null || $nextSks === null) {
            return null;
        }

        return [
            'target_ipk' => (float) $targetIpk,
            'next_sks' => (int) $nextSks,
        ];
    }
}
