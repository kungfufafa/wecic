<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * Versions of one archived dataset, optionally for one parameter set (one
 * class, one meeting, one KRS).
 */
class PortalArchiveVersionIndexRequest extends ApiFormRequest
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
             * Penanda parameter dataset (kelas/pertemuan/KRS tertentu), dari `groups[].params_hash`.
             *
             * @example "d751713988987e9331980363e24189ce"
             */
            'params_hash' => ['sometimes', 'string', 'regex:/^[0-9a-f]{32}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'params_hash.string' => 'Penanda parameter tidak valid.',
            'params_hash.regex' => 'Penanda parameter tidak valid.',
        ];
    }

    public function paramsHash(): ?string
    {
        $paramsHash = $this->validated('params_hash');

        return is_string($paramsHash) ? $paramsHash : null;
    }
}
