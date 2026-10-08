<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PortalRefreshRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->session()->has('campus_session_token');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'domains' => ['required', 'array', 'min:1'],
            'domains.*' => ['required', 'string', Rule::in($this->refreshableDatasets())],
        ];
    }

    /**
     * Datasets that can be refreshed without parameters.
     *
     * @return list<string>
     */
    private function refreshableDatasets(): array
    {
        return array_keys(array_filter(
            config('portal.datasets', []),
            static fn (array $dataset): bool => ! ($dataset['parameterized'] ?? false)
        ));
    }

    /**
     * @return list<string>
     */
    public function domains(): array
    {
        return array_values(array_unique($this->validated('domains')));
    }
}
