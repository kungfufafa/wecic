<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Exceptions\NotebookAiException;
use App\Models\NotebookAiConsent;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * The providers Notebook content goes to: the one that answers questions
 * and writes study material (`notebook.ai.provider`), and the one that
 * reads scanned PDFs and images (`notebook.ai.ingest_provider`, the same
 * one when unset). Students consent to, and are told about, both.
 * Resolved anew on every call, so a changed setting takes effect at once.
 */
class NotebookAiProviders
{
    /**
     * What an old consent (stored before consents named a provider) was
     * given for: Google Gemini's own API on its free tier, as the consent
     * text then said.
     */
    private const LEGACY_PROVIDER = 'gemini:free';

    /**
     * Marks a recipient in a consent key whose free tier the student was
     * told about.
     */
    private const FREE_TIER_MARK = ':free';

    public function __construct(private readonly Container $container) {}

    /**
     * @throws InvalidArgumentException For a provider this application has no driver for.
     */
    private function resolve(string $provider): NotebookAiClient
    {
        return match (strtolower(trim($provider))) {
            'gemini' => $this->container->make(GeminiDriver::class),
            'anthropic' => $this->container->make(AnthropicDriver::class),
            'openai', 'openai_compatible' => $this->container->make(OpenAiCompatibleDriver::class),
            default => throw new InvalidArgumentException(
                "Unknown notebook AI provider [{$provider}]: set NOTEBOOK_AI_PROVIDER (and NOTEBOOK_AI_INGEST_PROVIDER) to gemini, anthropic or openai."
            ),
        };
    }

    /**
     * The provider that answers questions and writes study material.
     */
    public function chat(): NotebookAiClient
    {
        return $this->resolve($this->chatProvider());
    }

    /**
     * The provider the student chose (NotebookAiOwnerSettings), else the
     * server's.
     */
    private function chatProvider(): string
    {
        return $this->ownerSettings()->provider() ?? (string) config('notebook.ai.provider', 'gemini');
    }

    private function ownerSettings(): NotebookAiOwnerSettings
    {
        return $this->container->make(NotebookAiOwnerSettings::class);
    }

    /**
     * The provider that reads scanned PDFs and images.
     */
    public function ingest(): NotebookAiClient
    {
        return $this->resolve((string) (config('notebook.ai.ingest_provider') ?: $this->chatProvider()));
    }

    /**
     * Every provider Notebook content may reach, the chat provider first.
     *
     * @return list<NotebookAiClient>
     */
    private function active(): array
    {
        $chat = $this->chat();
        $ingest = $this->ingest();

        return $ingest->driver() === $chat->driver() ? [$chat] : [$chat, $ingest];
    }

    /**
     * What a consent is given for: each active recipient
     * (NotebookAiClient::recipient()), marked `:free` when it runs on its
     * free tier, e.g. `gemini:free`, `anthropic+gemini:free` when another
     * provider reads files, or `openai@llm.example.com`. covers() says
     * whether a stored key still holds.
     */
    public function consentKey(): string
    {
        return implode('+', array_map(
            static fn (NotebookAiClient $client): string => $client->recipient().($client->freeTier() ? self::FREE_TIER_MARK : ''),
            $this->active()
        ));
    }

    /**
     * Whether a consent given for this key (null: one stored before keys
     * existed) covers where content goes now: the same recipients, and none
     * now on a free tier the student was not told about. A recipient that
     * left its free tier needs no new consent.
     */
    public function covers(?string $givenKey): bool
    {
        $given = self::parseKey($givenKey ?? self::LEGACY_PROVIDER);
        $current = self::parseKey($this->consentKey());

        if (array_keys($given) !== array_keys($current)) {
            return false;
        }

        foreach ($current as $recipient => $freeTier) {
            if ($freeTier && ! $given[$recipient]) {
                return false;
            }
        }

        return true;
    }

    /**
     * Recipients that received Notebook content under an earlier setting
     * and no longer do, as students read them: what was sent stays with
     * them, so the privacy and deletion pages keep naming them. Read from
     * the consents given (an old one without a key was for Google Gemini).
     *
     * @return list<string>
     */
    public function pastRecipients(): array
    {
        try {
            $keys = NotebookAiConsent::query()->distinct()->pluck('provider')->all();
        } catch (\Throwable $throwable) {
            report($throwable);

            return [];
        }

        $current = array_keys(self::parseKey($this->consentKey()));
        $past = [];

        foreach ($keys as $key) {
            foreach (array_keys(self::parseKey(is_string($key) && $key !== '' ? $key : self::LEGACY_PROVIDER)) as $recipient) {
                if (! in_array($recipient, $current, true)) {
                    $past[$recipient] = self::recipientLabel($recipient);
                }
            }
        }

        ksort($past);

        return array_values(array_unique($past));
    }

    /**
     * Each recipient of a consent key, with whether it was on its free tier.
     *
     * @return array<string, bool>
     */
    private static function parseKey(string $key): array
    {
        $recipients = [];

        foreach (explode('+', $key) as $part) {
            $part = trim($part);

            if ($part === '') {
                continue;
            }

            $free = str_ends_with($part, self::FREE_TIER_MARK);
            $recipients[$free ? substr($part, 0, -strlen(self::FREE_TIER_MARK)) : $part] = $free;
        }

        return $recipients;
    }

    /**
     * A recipient no longer configured, as students read it, e.g. `Google
     * Gemini` or `layanan AI di llm.example.com`.
     */
    private static function recipientLabel(string $recipient): string
    {
        [$driver, $host] = array_pad(explode('@', $recipient, 2), 2, null);

        return match (true) {
            $driver === 'openai' && ($host === null || $host === 'api.openai.com') => 'OpenAI',
            $driver === 'openai' => "layanan AI di {$host}",
            $driver === 'gemini' => 'Google Gemini'.($host !== null ? " (lewat {$host})" : ''),
            $driver === 'anthropic' => 'Anthropic Claude'.($host !== null ? " (lewat {$host})" : ''),
            default => $recipient,
        };
    }

    /**
     * The providers as students read them, e.g. `Google Gemini` or
     * `Anthropic Claude dan Google Gemini`.
     */
    public function label(): string
    {
        return implode(' dan ', array_map(static fn (NotebookAiClient $client): string => $client->label(), $this->active()));
    }

    /**
     * Whether any active provider runs on its free tier, under which what
     * is sent may be kept and used to improve its products.
     */
    public function freeTier(): bool
    {
        foreach ($this->active() as $client) {
            if ($client->freeTier()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The `ai_provider` the notebook payloads carry: `consent_key` is what
     * a consent given now is for (sent back with it, so a consent never
     * covers a provider the student was not shown), and `processors` says
     * which of them runs on a free tier.
     *
     * @return array{driver: string, label: string, free_tier: bool, consent_key: string, processors: list<array{label: string, free_tier: bool}>}
     */
    public function describe(): array
    {
        return [
            'driver' => $this->chat()->driver(),
            'label' => $this->label(),
            'free_tier' => $this->freeTier(),
            'consent_key' => $this->consentKey(),
            'processors' => array_map(static fn (NotebookAiClient $client): array => [
                'label' => $client->label(),
                'free_tier' => $client->freeTier(),
            ], $this->active()),
        ];
    }

    /**
     * Each active provider for the privacy pages, and the settings an
     * administrator would check for the chat provider (the notebook
     * banners name them).
     *
     * @return array{driver: string, label: string, free_tier: bool, consent_key: string, label_en: string, processors: list<array{driver: string, label: string, free_tier: bool}>, past_processors: list<string>, key_setting: string, model_settings: list<string>, own_settings: bool}
     */
    public function forPages(): array
    {
        $chat = $this->chat();

        return [
            ...$this->describe(),
            'past_processors' => $this->pastRecipients(),
            'label_en' => implode(' and ', array_map(static fn (NotebookAiClient $client): string => $client->label(), $this->active())),
            'processors' => array_map(static fn (NotebookAiClient $client): array => [
                'driver' => $client->driver(),
                'label' => $client->label(),
                'free_tier' => $client->freeTier(),
            ], $this->active()),
            'key_setting' => $chat->settingNames(NotebookAiException::CAUSE_KEY)[0] ?? '',
            'model_settings' => $chat->settingNames(NotebookAiException::CAUSE_MODEL),
            // The chat provider answers with the student's own key or endpoint
            // (Pengaturan AI), so a rejected key is theirs to fix.
            'own_settings' => $this->ownerSettings()->usesOwnKey($chat->driver()) || $this->ownerSettings()->usesOwnEndpoint($chat->driver()),
        ];
    }

    /**
     * What the pages show when the provider setting names no driver: a
     * generic provider, with the free-tier warning kept to stay on the safe
     * side.
     *
     * @return array{driver: string, label: string, free_tier: bool, consent_key: string, label_en: string, processors: list<array{driver: string, label: string, free_tier: bool}>, past_processors: list<string>, key_setting: string, model_settings: list<string>, own_settings: bool}
     */
    public static function unknown(): array
    {
        return [
            'driver' => 'unknown',
            'label' => 'penyedia AI',
            'free_tier' => true,
            'consent_key' => 'unknown',
            'label_en' => 'the AI provider',
            'processors' => [['driver' => 'unknown', 'label' => 'penyedia AI', 'free_tier' => true]],
            'past_processors' => [],
            'key_setting' => 'NOTEBOOK_AI_PROVIDER',
            'model_settings' => ['NOTEBOOK_AI_PROVIDER'],
            'own_settings' => false,
        ];
    }
}
