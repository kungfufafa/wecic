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
            'domains.*' => ['required', 'string', Rule::in(array_keys(config('portal.realtime.domains', [])))],
        ];
    }

    /**
     * @return list<string>
     */
    public function domains(): array
    {
        return array_values(array_unique($this->validated('domains')));
    }
}
