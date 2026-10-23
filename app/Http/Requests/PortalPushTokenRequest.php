<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\PortalPushToken;
use Illuminate\Validation\Rule;

/**
 * Register (or forget) the Expo push token of the app install the request
 * comes from.
 */
class PortalPushTokenRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $rules = [
            /**
             * Token push Expo perangkat ini, dari `getExpoPushTokenAsync()`.
             *
             * @example "ExponentPushToken[xxxxxxxxxxxxxxxxxxxxxx]"
             */
            'token' => ['required', 'string', 'max:255', 'regex:'.PortalPushToken::TOKEN_PATTERN],
        ];

        if ($this->isMethod('POST')) {
            /**
             * Platform perangkat.
             *
             * @example "android"
             */
            $rules['platform'] = ['nullable', 'string', Rule::in(PortalPushToken::PLATFORMS)];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'token.required' => 'Token push perangkat wajib diisi.',
            'token.string' => 'Token push perangkat tidak valid.',
            'token.max' => 'Token push perangkat tidak valid.',
            'token.regex' => 'Token push harus berupa token Expo (ExponentPushToken[...]).',
            'platform.in' => 'Platform harus ios, android, atau web.',
        ];
    }

    public function pushToken(): string
    {
        return (string) $this->validated('token');
    }

    public function platform(): ?string
    {
        $platform = $this->validated('platform');

        return is_string($platform) ? $platform : null;
    }
}
