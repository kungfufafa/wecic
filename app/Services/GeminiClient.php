<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\NotebookAiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Minimal client for the Gemini `generateContent` endpoint
 * (`services.gemini`). One retry on overload, then the fallback model, all
 * within one wall-clock budget: a web request must answer (even with an
 * error) before the web server's own timeout, so a slow model is given up
 * in time instead of becoming a bare 504.
 *
 * @phpstan-type GeminiPart array{text?: string, inlineData?: array{mimeType: string, data: string}}
 * @phpstan-type GeminiContent array{role: 'user'|'model', parts: list<GeminiPart>}
 */
class GeminiClient
{
    private const RETRYABLE_STATUSES = [429, 500, 502, 503, 504];

    /**
     * A retry or a fallback model is only worth starting with this much of
     * the budget left.
     */
    private const MIN_RETRY_SECONDS = 8;

    public function isConfigured(): bool
    {
        return is_string(config('services.gemini.key')) && trim((string) config('services.gemini.key')) !== '';
    }

    /**
     * @param  list<GeminiContent>  $contents
     * @param  array<string, mixed>  $generationConfig
     * @param  int|null  $budgetSeconds  Wall-clock budget for every attempt together; `services.gemini.request_budget` by default.
     * @return array{text: string, finish_reason: string|null, model: string}
     */
    public function generate(array $contents, ?string $systemInstruction = null, array $generationConfig = [], ?int $budgetSeconds = null): array
    {
        if (! $this->isConfigured()) {
            throw NotebookAiException::notConfigured();
        }

        $payload = array_filter([
            'systemInstruction' => $systemInstruction !== null ? ['parts' => [['text' => $systemInstruction]]] : null,
            'contents' => $contents,
            'generationConfig' => $generationConfig !== [] ? $generationConfig : null,
        ], static fn (mixed $value): bool => $value !== null);

        $models = array_values(array_unique(array_filter([
            (string) config('services.gemini.model'),
            (string) config('services.gemini.fallback_model'),
        ])));
        $deadline = microtime(true) + max(1, $budgetSeconds ?? (int) config('services.gemini.request_budget', 50));

        foreach ($models as $index => $model) {
            $response = $this->send($model, $payload, $deadline);

            if ($response !== null && $response->successful()) {
                return $this->parse($response, $model);
            }

            $status = $response?->status();
            Log::warning('Gemini request failed', [
                'model' => $model,
                'status' => $status,
                'error' => $response?->json('error.message'),
            ]);

            // A bad request will not get better with another model.
            if ($status === 400 || $status === 401 || $status === 403) {
                throw NotebookAiException::unavailable();
            }

            if ($index === count($models) - 1 || $this->remaining($deadline) < self::MIN_RETRY_SECONDS) {
                break;
            }
        }

        throw NotebookAiException::unavailable();
    }

    /**
     * One attempt, and a second one on overload while the budget allows.
     *
     * @param  array<string, mixed>  $payload
     */
    private function send(string $model, array $payload, float $deadline): ?Response
    {
        $url = rtrim((string) config('services.gemini.base_url'), '/').'/models/'.rawurlencode($model).':generateContent';
        $response = null;

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $timeout = min(max(10, (int) config('services.gemini.timeout', 60)), max(1, (int) ceil($this->remaining($deadline))));

            try {
                $response = Http::withHeaders(['x-goog-api-key' => (string) config('services.gemini.key')])
                    ->acceptJson()
                    ->timeout($timeout)
                    ->post($url, $payload);
            } catch (ConnectionException $exception) {
                Log::warning('Gemini is unreachable', ['model' => $model, 'error' => $exception->getMessage()]);
                $response = null;
            }

            if ($response !== null && ! in_array($response->status(), self::RETRYABLE_STATUSES, true)) {
                return $response;
            }

            $delay = max(0, (int) config('services.gemini.retry_delay_ms', 1200)) / 1000;

            if ($attempt === 2 || $this->remaining($deadline) - $delay < self::MIN_RETRY_SECONDS) {
                break;
            }

            usleep((int) ($delay * 1_000_000));
        }

        return $response;
    }

    private function remaining(float $deadline): float
    {
        return $deadline - microtime(true);
    }

    /**
     * @return array{text: string, finish_reason: string|null, model: string}
     */
    private function parse(Response $response, string $model): array
    {
        if ($response->json('promptFeedback.blockReason') !== null) {
            throw NotebookAiException::blocked();
        }

        $candidate = $response->json('candidates.0');
        $finishReason = is_array($candidate) ? ($candidate['finishReason'] ?? null) : null;
        $parts = is_array($candidate['content']['parts'] ?? null) ? $candidate['content']['parts'] : [];
        $text = trim(implode('', array_map(
            static fn (mixed $part): string => is_array($part) && is_string($part['text'] ?? null) && ! ($part['thought'] ?? false) ? $part['text'] : '',
            $parts
        )));

        if ($text === '') {
            throw $finishReason === 'SAFETY' ? NotebookAiException::blocked() : NotebookAiException::emptyAnswer();
        }

        return ['text' => $text, 'finish_reason' => is_string($finishReason) ? $finishReason : null, 'model' => $model];
    }
}
