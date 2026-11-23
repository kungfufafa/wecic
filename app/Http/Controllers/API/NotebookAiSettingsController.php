<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Requests\UpdateNotebookAiSettingsRequest;
use App\Models\NotebookAiSetting;
use App\Services\Ai\NotebookAiOwnerSettings;
use App\Services\Ai\NotebookAiProviders;
use App\Services\NotebookAiConsents;
use App\Services\PortalDataOwner;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

#[Group('Notebook', weight: 18)]
class NotebookAiSettingsController extends CampusApiController
{
    private const PROVIDER_STATE = 'array{has_key: bool, key_preview: string|null, server_configured: bool, usable: bool}';

    private const EFFECTIVE_STATE = "array{provider: 'gemini'|'anthropic'|'openai'|'unknown', label: string, key_source: 'own'|'server'|'none', endpoint: 'own'|'server'|null, endpoint_host: string|null, ready: bool}";

    private const INGEST_STATE = "array{provider: string, label: string, key_source: 'own'|'server'|'none'}|null";

    private const SETTINGS_STATE = "array{provider: 'gemini'|'anthropic'|'openai'|null, active_provider: string, server_provider: string, server_provider_label: string|null, server_ready: bool, effective: ".self::EFFECTIVE_STATE.', ingest: '.self::INGEST_STATE.', providers: array{gemini: '.self::PROVIDER_STATE.', anthropic: '.self::PROVIDER_STATE.', openai: array{has_key: bool, key_preview: string|null, server_configured: bool, usable: bool, server_endpoint: bool, base_url: string|null, model: string|null}}}';

    private const CONSENT_STATE = 'array{granted: bool, granted_at: string|null, withdrawn_at: string|null, provider: string, free_tier: bool, consent_key: string, processors: list<array{label: string, free_tier: bool}>, past_processors: list<string>}';

    private const SETTINGS_RESPONSE = "array{status: 'success', message: string, data: array{settings: ".self::SETTINGS_STATE.', consent: '.self::CONSENT_STATE.'}, meta: array{timestamp: string, campus_session_valid: true}}';

    public function __construct(
        private readonly NotebookAiOwnerSettings $ownerSettings,
        private readonly NotebookAiProviders $providers,
        private readonly NotebookAiConsents $consents,
        private readonly PortalDataOwner $owner,
    ) {}

    /**
     * Pengaturan AI Notebook milikmu: penyedia yang dipakai dan API key-mu sendiri.
     *
     * API key tidak pernah dikirim balik utuh: `key_preview` hanya menampilkan empat karakter terakhirnya.
     * `server_configured` berarti server WeCIC sudah punya kunci untuk penyedia itu, yang dipakai bila kamu tidak mengisi
     * kunci sendiri; `usable` berarti penyedia itu bisa dipakai dengan pengaturanmu sekarang. `effective` menyebut yang
     * benar-benar dipakai: penyedia, kunci siapa (`key_source`), dan untuk OpenAI-compatible layanan siapa (`endpoint`).
     * `ingest` menyebut penyedia terpisah yang membaca PDF pindaian dan gambar, bila ada. `consent` adalah status
     * persetujuan AI untuk penyedia yang berlaku sekarang.
     */
    #[Response(description: 'Pengaturan AI.', type: self::SETTINGS_RESPONSE)]
    #[Response(401, description: 'Endpoint ini membutuhkan sesi kampus yang aktif.', type: CampusApiController::SCRAMBLE_SESSION_ERROR_RESPONSE)]
    public function show(): JsonResponse
    {
        return $this->withCampusSession('AI settings read failed', 'Gagal memuat pengaturan AI', fn (string $sessionToken): JsonResponse => $this->statusSuccess('AI settings retrieved', [
            'data' => $this->state($this->owner->key($sessionToken)),
        ], campusSessionValid: true));
    }

    /**
     * Simpan pengaturan AI Notebook milikmu.
     *
     * API key yang dikosongkan tetap memakai kunci yang tersimpan; kirim `clear_gemini_api_key` (dan seterusnya) untuk
     * menghapusnya. Alamat layanan OpenAI-compatible milikmu harus HTTPS dan bisa dijangkau dari internet, dan perlu API key
     * serta model. Bila penyedia berubah, persetujuan AI perlu diberikan lagi.
     */
    #[Response(description: 'Pengaturan disimpan.', type: self::SETTINGS_RESPONSE)]
    #[Response(422, description: 'Isian tidak valid.', type: CampusApiController::SCRAMBLE_VALIDATION_ERROR_RESPONSE)]
    #[Response(401, description: 'Endpoint ini membutuhkan sesi kampus yang aktif.', type: CampusApiController::SCRAMBLE_SESSION_ERROR_RESPONSE)]
    public function update(UpdateNotebookAiSettingsRequest $request): JsonResponse
    {
        return $this->withCampusSession('AI settings save failed', 'Gagal menyimpan pengaturan AI', function (string $sessionToken) use ($request): JsonResponse {
            $ownerHash = $this->owner->key($sessionToken);
            $saved = NotebookAiSetting::query()->forOwner($ownerHash)->first();
            $attributes = $request->attributesFor($saved);

            if (array_filter($attributes, static fn (?string $value): bool => $value !== null) === []) {
                $saved?->delete();
            } else {
                NotebookAiSetting::query()->updateOrCreate(['owner_hash' => $ownerHash], $attributes);
            }

            $this->ownerSettings->forget();
            $state = $this->state($ownerHash);

            return $this->statusSuccess($state['consent']['granted']
                ? 'Pengaturan AI disimpan.'
                : "Pengaturan AI disimpan. Notebook kini memakai {$state['consent']['provider']}; setujui dulu pengirimannya sebelum memakai AI.", [
                    'data' => $state,
                ], campusSessionValid: true);
        });
    }

    /**
     * Hapus semua pengaturan AI milikmu (penyedia dan API key); Notebook kembali memakai pengaturan server.
     */
    #[Response(description: 'Pengaturan dihapus.', type: self::SETTINGS_RESPONSE)]
    #[Response(401, description: 'Endpoint ini membutuhkan sesi kampus yang aktif.', type: CampusApiController::SCRAMBLE_SESSION_ERROR_RESPONSE)]
    public function destroy(): JsonResponse
    {
        return $this->withCampusSession('AI settings clear failed', 'Gagal menghapus pengaturan AI', function (string $sessionToken): JsonResponse {
            $ownerHash = $this->owner->key($sessionToken);
            NotebookAiSetting::query()->forOwner($ownerHash)->delete();
            $this->ownerSettings->forget();

            return $this->statusSuccess('Pengaturan AI milikmu dihapus. Notebook kembali memakai pengaturan server WeCIC.', [
                'data' => $this->state($ownerHash),
            ], campusSessionValid: true);
        });
    }

    /**
     * @return array{settings: array<string, mixed>, consent: array<string, mixed>}
     */
    private function state(string $ownerHash): array
    {
        $settings = NotebookAiSetting::query()->forOwner($ownerHash)->first();
        $providers = [];

        foreach (array_keys(NotebookAiSetting::KEY_COLUMNS) as $provider) {
            $key = $settings?->apiKey($provider);
            $providers[$provider] = [
                'has_key' => $key !== null,
                'key_preview' => NotebookAiSetting::mask($key),
                'server_configured' => NotebookAiOwnerSettings::serverConfigures($provider),
                'usable' => NotebookAiOwnerSettings::usable($settings, $provider),
            ];
        }

        $providers['openai'] += [
            // Only whether the server has one: its address and model stay on the server.
            'server_endpoint' => NotebookAiOwnerSettings::serverOpenAiEndpoint(),
            'base_url' => $settings?->openai_base_url,
            'model' => $settings?->openai_model,
        ];

        $serverProvider = strtolower(trim((string) config('notebook.ai.provider', 'gemini')));
        $activeProvider = $this->activeProvider();

        return [
            'settings' => [
                'provider' => $settings?->provider,
                'active_provider' => $activeProvider,
                'server_provider' => $serverProvider,
                'server_provider_label' => NotebookAiSetting::LABELS[$serverProvider] ?? null,
                'server_ready' => NotebookAiOwnerSettings::serverConfigures($serverProvider),
                'effective' => $this->effective($activeProvider, $settings),
                'ingest' => $this->ingest($activeProvider),
                'providers' => $providers,
            ],
            'consent' => $this->consentState($ownerHash),
        ];
    }

    /**
     * The driver that answers the student now, or `unknown` when the
     * server's provider setting names none.
     */
    private function activeProvider(): string
    {
        try {
            return $this->providers->chat()->driver();
        } catch (\InvalidArgumentException $exception) {
            report($exception);

            return 'unknown';
        }
    }

    /**
     * What answers the student now: the provider, whose key, and for an
     * OpenAI-compatible provider whose endpoint (only the student's own
     * host is named).
     *
     * @return array{provider: string, label: string, key_source: 'own'|'server'|'none', endpoint: 'own'|'server'|null, endpoint_host: string|null, ready: bool}
     */
    private function effective(string $provider, ?NotebookAiSetting $settings): array
    {
        if (! array_key_exists($provider, NotebookAiSetting::LABELS)) {
            return ['provider' => 'unknown', 'label' => 'Penyedia server tidak dikenali', 'key_source' => 'none', 'endpoint' => null, 'endpoint_host' => null, 'ready' => false];
        }

        $ownEndpoint = $this->ownerSettings->usesOwnEndpoint($provider);

        return [
            'provider' => $provider,
            'label' => NotebookAiSetting::LABELS[$provider],
            'key_source' => $this->keySource($provider),
            'endpoint' => $provider === 'openai' ? ($ownEndpoint ? 'own' : 'server') : null,
            'endpoint_host' => $ownEndpoint ? (parse_url((string) $settings?->openai_base_url, PHP_URL_HOST) ?: null) : null,
            'ready' => NotebookAiOwnerSettings::usable($settings, $provider),
        ];
    }

    /**
     * The separate provider that reads scanned PDFs and images, when the
     * server names one other than the provider that answers.
     *
     * @return array{provider: string, label: string, key_source: 'own'|'server'|'none'}|null
     */
    private function ingest(string $chatProvider): ?array
    {
        try {
            $provider = $this->providers->ingest()->driver();
        } catch (\InvalidArgumentException) {
            return null;
        }

        if ($provider === $chatProvider) {
            return null;
        }

        return [
            'provider' => $provider,
            'label' => NotebookAiSetting::LABELS[$provider] ?? $provider,
            'key_source' => $this->keySource($provider),
        ];
    }

    /**
     * @return 'own'|'server'|'none'
     */
    private function keySource(string $provider): string
    {
        return match (true) {
            $this->ownerSettings->usesOwnKey($provider) => 'own',
            NotebookAiOwnerSettings::serverConfigures($provider) && ! $this->ownerSettings->usesOwnEndpoint($provider) => 'server',
            default => 'none',
        };
    }

    /**
     * The consent state, or a not-granted one naming a generic provider
     * when the server's provider setting names none (nothing can be sent).
     *
     * @return array<string, mixed>
     */
    private function consentState(string $ownerHash): array
    {
        try {
            return $this->consents->state($ownerHash);
        } catch (\InvalidArgumentException) {
            $unknown = NotebookAiProviders::unknown();

            return [
                'granted' => false,
                'granted_at' => null,
                'withdrawn_at' => null,
                'provider' => $unknown['label'],
                'free_tier' => $unknown['free_tier'],
                'consent_key' => $unknown['consent_key'],
                'processors' => [['label' => $unknown['label'], 'free_tier' => $unknown['free_tier']]],
                'past_processors' => [],
            ];
        }
    }
}
