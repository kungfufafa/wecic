<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\NotebookAiSetting;
use App\Services\PortalDataOwner;

/**
 * The Notebook AI settings of the student a request or queue job works for
 * (NotebookAiSetting), applied over the server's: a provider the student
 * chose, their own keys, and their own OpenAI-compatible endpoint. Scoped
 * to one request or job.
 *
 * The student is the one whose campus session the request carries, or the
 * one a job named with actAs() (UseNotebookOwnerAiSettings). Nobody (a page
 * without a session, a job for no student) means the server's settings.
 */
class NotebookAiOwnerSettings
{
    private ?string $actingOwner = null;

    /**
     * Each student's settings this scope already read (null: none saved).
     *
     * @var array<string, NotebookAiSetting|null>
     */
    private array $loaded = [];

    public function __construct(private readonly PortalDataOwner $owner) {}

    /**
     * Use this student's settings for the rest of the job (null: the
     * session's student again).
     */
    public function actAs(?string $ownerHash): void
    {
        $this->actingOwner = $ownerHash !== null && $ownerHash !== '' ? $ownerHash : null;
    }

    public function ownerHash(): ?string
    {
        if ($this->actingOwner !== null) {
            return $this->actingOwner;
        }

        $request = request();

        if (! $request->hasSession()) {
            return null;
        }

        $sessionToken = $request->session()->get('campus_session_token');

        return is_string($sessionToken) && $sessionToken !== '' ? $this->owner->key($sessionToken) : null;
    }

    public function current(): ?NotebookAiSetting
    {
        $ownerHash = $this->ownerHash();

        if ($ownerHash === null) {
            return null;
        }

        if (! array_key_exists($ownerHash, $this->loaded)) {
            try {
                $this->loaded[$ownerHash] = NotebookAiSetting::query()->forOwner($ownerHash)->first();
            } catch (\Throwable $throwable) {
                report($throwable);

                return null;
            }
        }

        return $this->loaded[$ownerHash];
    }

    /**
     * Read the student's settings again (after they were saved).
     */
    public function forget(): void
    {
        $this->loaded = [];
    }

    /**
     * The provider the student chose, or null to follow the server.
     */
    public function provider(): ?string
    {
        $provider = $this->current()?->provider;

        return in_array($provider, NotebookAiSetting::PROVIDERS, true) ? $provider : null;
    }

    /**
     * The settings of a driver (NotebookAiClient::driver()) the student's
     * replace, by name under `services.{provider}`. A student's own
     * OpenAI-compatible endpoint replaces everything about where requests
     * go, so the server's key, fallback model and capabilities never apply
     * to it.
     *
     * @return array<string, mixed>
     */
    public function overrides(string $driver): array
    {
        $settings = $this->current();

        if ($settings === null) {
            return [];
        }

        $key = $settings->apiKey($driver);
        $overrides = $key !== null ? ['key' => $key] : [];

        if ($driver !== 'openai') {
            return $overrides;
        }

        $model = is_string($settings->openai_model) && trim($settings->openai_model) !== '' ? trim($settings->openai_model) : null;

        if ($model !== null) {
            $overrides += ['model' => $model, 'fallback_model' => null];
        }

        if (! $settings->hasOwnOpenAiEndpoint()) {
            return $overrides;
        }

        $host = strtolower((string) parse_url((string) $settings->openai_base_url, PHP_URL_HOST));

        return [
            'key' => $key ?? '',
            'base_url' => trim((string) $settings->openai_base_url),
            'model' => $model ?? '',
            'fallback_model' => null,
            'label' => $host === 'api.openai.com' ? 'OpenAI' : "layanan AI di {$host}",
            // What the endpoint keeps or reads is unknown: stay on the safe side.
            'free_tier' => $host !== 'api.openai.com',
            'image_input' => false,
            'pdf_input' => false,
            'structured_output' => 'json_object',
        ];
    }

    /**
     * Whether requests of this driver go to an endpoint the student typed.
     */
    public function usesOwnEndpoint(string $driver): bool
    {
        return $driver === 'openai' && ($this->current()?->hasOwnOpenAiEndpoint() ?? false);
    }

    /**
     * Whether the server's own settings (`services.*`) make a driver work
     * without anything of the student's.
     */
    public static function serverConfigures(string $driver): bool
    {
        return match ($driver) {
            'gemini' => trim((string) config('services.gemini.key')) !== '',
            'anthropic' => trim((string) config('services.anthropic.key')) !== '',
            'openai' => self::serverOpenAiEndpoint() && (trim((string) config('services.openai_compatible.key')) !== '' || self::serverOpenAiKeyless()),
            default => false,
        };
    }

    /**
     * The server names an OpenAI-compatible endpoint and model.
     */
    public static function serverOpenAiEndpoint(): bool
    {
        return trim((string) config('services.openai_compatible.base_url')) !== '' && trim((string) config('services.openai_compatible.model')) !== '';
    }

    /**
     * The server's OpenAI-compatible endpoint takes no key (this machine or
     * a `*.test` host, as OpenAiCompatibleDriver::isConfigured() allows).
     */
    private static function serverOpenAiKeyless(): bool
    {
        $host = (string) parse_url((string) config('services.openai_compatible.base_url'), PHP_URL_HOST);

        return preg_match('/^(localhost|127\.0\.0\.1|\[::1\]|::1)$|\.(localhost|test)$/i', $host) === 1;
    }

    /**
     * Whether this driver answers with the student's own key.
     */
    public function usesOwnKey(string $driver): bool
    {
        return $this->current()?->apiKey($driver) !== null;
    }
}
