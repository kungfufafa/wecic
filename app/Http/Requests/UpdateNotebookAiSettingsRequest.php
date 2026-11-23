<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\NotebookAiSetting;
use App\Services\Ai\NotebookAiOwnerSettings;
use App\Services\Ai\PublicAiEndpoint;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A student's own Notebook AI settings. A key left empty keeps the saved
 * one (the page never has it in full); `clear_*_api_key` removes it. The
 * endpoint and model are sent as they should be, empty to remove them.
 */
class UpdateNotebookAiSettingsRequest extends ApiFormRequest
{
    private const PROVIDER_LABELS = [
        'gemini' => 'Google Gemini',
        'anthropic' => 'Anthropic Claude',
        'openai' => 'OpenAI-compatible',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $key = ['sometimes', 'nullable', 'string', 'max:512', 'regex:/^\S+$/'];

        return [
            /**
             * Penyedia AI untuk Notebook-mu: `gemini`, `anthropic`, atau `openai` (OpenAI-compatible). Kosong mengikuti server.
             *
             * @example "anthropic"
             */
            'provider' => ['sometimes', 'nullable', 'string', Rule::in(NotebookAiSetting::PROVIDERS)],
            /**
             * API key Google Gemini milikmu. Kosong mempertahankan kunci yang tersimpan.
             */
            'gemini_api_key' => $key,
            /**
             * API key Anthropic milikmu. Kosong mempertahankan kunci yang tersimpan.
             */
            'anthropic_api_key' => $key,
            /**
             * API key layanan OpenAI-compatible milikmu. Kosong mempertahankan kunci yang tersimpan.
             */
            'openai_api_key' => $key,
            /**
             * Alamat dasar layanan OpenAI-compatible (HTTPS, mis. `https://api.openai.com/v1`). Kosong memakai alamat server.
             *
             * @example "https://api.openai.com/v1"
             */
            'openai_base_url' => ['sometimes', 'nullable', 'string', 'max:2048', 'url:https', $this->publicEndpoint()],
            /**
             * Model di layanan OpenAI-compatible, mis. `gpt-5-mini`. Kosong memakai model server.
             *
             * @example "gpt-5-mini"
             */
            'openai_model' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:/^\S+$/'],
            'clear_gemini_api_key' => ['sometimes', 'boolean'],
            'clear_anthropic_api_key' => ['sometimes', 'boolean'],
            'clear_openai_api_key' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $settings = new NotebookAiSetting($this->attributesFor(app(NotebookAiOwnerSettings::class)->current()));

                if ($settings->hasOwnOpenAiEndpoint()) {
                    if ($settings->apiKey('openai') === null) {
                        $validator->errors()->add('openai_api_key', 'Isi API key untuk layanan OpenAI-compatible milikmu.');
                    }

                    if (trim((string) $settings->openai_model) === '') {
                        $validator->errors()->add('openai_model', 'Isi nama model untuk layanan OpenAI-compatible milikmu.');
                    }

                    return;
                }

                if ($settings->provider !== null && ! $this->usable($settings, $settings->provider)) {
                    $validator->errors()->add('provider', 'Server WeCIC belum punya API key '.self::PROVIDER_LABELS[$settings->provider].'. Isi API key milikmu dulu untuk memakai penyedia ini.');
                }
            },
        ];
    }

    /**
     * The settings to store over the saved ones (null: none saved yet).
     *
     * @return array{provider: string|null, gemini_api_key: string|null, anthropic_api_key: string|null, openai_api_key: string|null, openai_base_url: string|null, openai_model: string|null}
     */
    public function attributesFor(?NotebookAiSetting $saved): array
    {
        $attributes = [
            'provider' => $saved?->provider,
            'openai_base_url' => $saved?->openai_base_url,
            'openai_model' => $saved?->openai_model,
        ];

        foreach (['provider', 'openai_base_url', 'openai_model'] as $field) {
            if ($this->has($field)) {
                $value = $this->input($field);
                $attributes[$field] = is_string($value) && trim($value) !== '' ? trim($value) : null;
            }
        }

        foreach (NotebookAiSetting::KEY_COLUMNS as $provider => $column) {
            $value = $this->input($column);

            $attributes[$column] = match (true) {
                $this->boolean('clear_'.$column) => null,
                is_string($value) && trim($value) !== '' => trim($value),
                default => $saved?->apiKey($provider),
            };
        }

        return $attributes;
    }

    /**
     * Whether a provider can answer with these settings, the server's
     * filling in what the student left empty.
     */
    private function usable(NotebookAiSetting $settings, string $provider): bool
    {
        if (NotebookAiOwnerSettings::serverConfigures($provider)) {
            return true;
        }

        if ($settings->apiKey($provider) === null) {
            return false;
        }

        return $provider !== 'openai'
            || (trim((string) config('services.openai_compatible.base_url')) !== '' && (trim((string) $settings->openai_model) !== '' || trim((string) config('services.openai_compatible.model')) !== ''));
    }

    private function publicEndpoint(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && trim($value) !== '' && ! app(PublicAiEndpoint::class)->allows($value)) {
                $fail('Alamat layanan harus HTTPS dan bisa dijangkau dari internet (bukan localhost atau jaringan lokal).');
            }
        };
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'provider.in' => 'Pilih penyedia AI yang tersedia: Google Gemini, Anthropic Claude, atau OpenAI-compatible.',
            '*.max' => 'Isian ini terlalu panjang.',
            '*_api_key.regex' => 'API key tidak boleh berisi spasi.',
            '*_api_key.string' => 'API key tidak valid.',
            'openai_base_url.url' => 'Alamat layanan harus berupa URL HTTPS, mis. https://api.openai.com/v1.',
            'openai_model.regex' => 'Nama model tidak boleh berisi spasi.',
            'clear_*.boolean' => 'Pilihan hapus kunci tidak valid.',
        ];
    }
}
