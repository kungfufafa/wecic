<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Exceptions\NotebookAiException;
use App\Support\Ai\AiRequest;
use App\Support\Ai\AiResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The provider-independent half of a notebook model client over HTTP. One
 * retry on overload, then the fallback model, all within one wall-clock
 * budget: a web request must answer (even with an error) before the web
 * server's own timeout, so a slow model is given up in time instead of
 * becoming a bare 504.
 *
 * An answer cut at its output limit (which a thinking model's reasoning
 * counts against) is asked for once more with a larger limit, within the
 * same budget; one still cut comes back marked `truncated`. A key or model
 * id the API rejects is remembered for a while (per configuration), so the
 * notebook can say so instead of calling the model again.
 *
 * A driver says how to build its payload, where to send it, how to read
 * the answer and which errors are about its settings; its settings live
 * under settingsKey() (`key`, `model`, `fallback_model`, `base_url`,
 * `timeout`, `retry_delay_ms`).
 */
abstract class HttpAiClient implements NotebookAiClient
{
    /**
     * A retry or a fallback model is only worth starting with this much of
     * the budget left.
     */
    protected const MIN_RETRY_SECONDS = 8;

    /**
     * How long a rejected key or model id is remembered: the notebook says
     * so meanwhile, and the next request after it tries the model again.
     * A changed key or model setting is tried at once.
     */
    protected const MISCONFIGURATION_TTL_SECONDS = 600;

    /**
     * Where the driver's settings live, e.g. `services.gemini`.
     */
    abstract protected function settingsKey(): string;

    /**
     * The request in the provider's own shape.
     *
     * @return array<string, mixed>
     */
    abstract protected function payload(AiRequest $request): array;

    /**
     * The URL a request for this model is sent to.
     */
    abstract protected function endpoint(string $model): string;

    /**
     * @return array<string, string>
     */
    abstract protected function headers(): array;

    /**
     * The answer of a successful response. An answer cut at the output
     * limit may have no text yet; any other empty answer fails.
     *
     * @throws NotebookAiException
     */
    abstract protected function parse(Response $response, string $model): AiResult;

    /**
     * What a failed response says is wrong with the settings: the key, the
     * model settings, or nothing (null).
     *
     * @return NotebookAiException::CAUSE_*|null
     */
    abstract protected function configurationProblem(Response $response): ?string;

    /**
     * The output limit a payload asks for, or null when it leaves the
     * model's default.
     *
     * @param  array<string, mixed>  $payload
     */
    abstract protected function outputLimit(array $payload): ?int;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    abstract protected function withOutputLimit(array $payload, int $limit): array;

    /**
     * The settings to check when the API rejected the key or the model,
     * for the log (e.g. `GEMINI_MODEL, GEMINI_FALLBACK_MODEL and NOTEBOOK_THINKING_BUDGET`).
     *
     * @param  NotebookAiException::CAUSE_*  $cause
     */
    protected function settingsHint(string $cause): string
    {
        $names = $this->settingNames($cause);
        $last = array_pop($names);

        return $names === [] ? (string) $last : implode(', ', $names).' and '.$last;
    }

    public function recipient(): string
    {
        $host = strtolower((string) parse_url((string) $this->setting('base_url'), PHP_URL_HOST));

        return $host === '' || $host === $this->ownHost() ? $this->driver() : $this->driver().'@'.$host;
    }

    /**
     * The host of the provider's own API, or null for a driver whose
     * endpoint always says who receives the data (OpenAI-compatible).
     */
    protected function ownHost(): ?string
    {
        return null;
    }

    public function isConfigured(): bool
    {
        return trim($this->key()) !== '';
    }

    public function isUsable(): bool
    {
        return $this->isConfigured() && $this->misconfiguration() === null;
    }

    public function misconfiguration(): ?string
    {
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $cause = Cache::get($this->misconfigurationKey());
        } catch (\Throwable) {
            return null;
        }

        return in_array($cause, [NotebookAiException::CAUSE_KEY, NotebookAiException::CAUSE_MODEL], true) ? $cause : null;
    }

    public function generate(AiRequest $request): AiResult
    {
        if (! $this->isConfigured()) {
            throw NotebookAiException::notConfigured($this->label());
        }

        $remembered = $this->misconfiguration();

        if ($remembered !== null) {
            throw NotebookAiException::misconfigured($remembered, $this->label());
        }

        $this->guardInput($request);

        $payload = $this->payload($request);
        $models = $this->models();
        $deadline = microtime(true) + max(1, $request->budgetSeconds ?? AiRequest::requestBudgetSeconds());
        $attemptTimeout = max(10, $request->attemptTimeoutSeconds ?? (int) $this->setting('timeout', 60));
        $attempted = 0;
        $causes = [];
        $timedOut = false;

        foreach ($models as $index => $model) {
            $attempted++;
            ['response' => $response, 'timed_out' => $timedOut] = $this->send($model, $payload, $deadline, $attemptTimeout);

            if ($response !== null && $response->successful()) {
                $result = $this->parse($response, $model);

                if ($result->truncated) {
                    $result = $this->askForMore($model, $payload, $result, $deadline, $attemptTimeout);
                }

                return $this->complete($result, $request);
            }

            $status = $response?->status();
            Log::warning($this->label().' request failed', [
                'model' => $model,
                'status' => $status,
                'timed_out' => $timedOut,
                'error' => $response !== null ? $this->errorMessage($response) : null,
            ]);

            $cause = $response !== null ? $this->configurationProblem($response) : null;

            if ($cause !== null) {
                $causes[] = $cause;

                // A wrong key fails for every model; an unknown model id may not.
                if ($cause === NotebookAiException::CAUSE_KEY) {
                    break;
                }
            } elseif ($status === 400) {
                // A bad request will not get better with another model.
                throw NotebookAiException::rejected();
            }

            if ($index === count($models) - 1 || $this->remaining($deadline) < self::MIN_RETRY_SECONDS) {
                break;
            }
        }

        if ($causes !== [] && count($causes) === $attempted) {
            $cause = in_array(NotebookAiException::CAUSE_KEY, $causes, true) ? NotebookAiException::CAUSE_KEY : NotebookAiException::CAUSE_MODEL;
            $this->rememberMisconfiguration($cause);
            Log::error($cause === NotebookAiException::CAUSE_KEY
                ? $this->label().' rejected the API key: check '.$this->settingsHint($cause)
                : $this->label().' rejected the model settings: check '.$this->settingsHint($cause), ['models' => $models]);

            throw NotebookAiException::misconfigured($cause, $this->label());
        }

        throw $timedOut ? NotebookAiException::timedOut() : NotebookAiException::unavailable();
    }

    /**
     * The payload for one model: a driver that names the model in the body
     * rather than in the URL puts it there.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function forModel(array $payload, string $model): array
    {
        return $payload;
    }

    /**
     * Statuses worth a second attempt: overload and server errors.
     *
     * @return list<int>
     */
    protected function retryableStatuses(): array
    {
        return [429, 500, 502, 503, 504];
    }

    /**
     * A reasoning setting that is part of the configuration a rejection is
     * remembered for (e.g. Gemini's thinking budget), or null.
     */
    protected function reasoningSetting(): mixed
    {
        return null;
    }

    /**
     * The error text of a failed response, for the log.
     */
    protected function errorMessage(Response $response): mixed
    {
        return $response->json('error.message');
    }

    /**
     * A setting of the driver: the student's own (NotebookAiOwnerSettings)
     * when they saved one, else the server's under settingsKey().
     */
    protected function setting(string $name, mixed $default = null): mixed
    {
        $overrides = $this->ownerSettings()->overrides($this->driver());

        return array_key_exists($name, $overrides) ? $overrides[$name] : config($this->settingsKey().'.'.$name, $default);
    }

    protected function ownerSettings(): NotebookAiOwnerSettings
    {
        return app(NotebookAiOwnerSettings::class);
    }

    protected function key(): string
    {
        $key = $this->setting('key');

        return is_string($key) ? $key : '';
    }

    /**
     * The model, then the fallback model when one is set.
     *
     * @return list<string>
     */
    protected function models(): array
    {
        return array_values(array_unique(array_filter([
            (string) $this->setting('model'),
            (string) $this->setting('fallback_model'),
        ])));
    }

    /**
     * One attempt, and a second one on overload while the budget allows.
     * `timed_out` tells whether the last attempt was cut by its timeout.
     *
     * @param  array<string, mixed>  $payload
     * @return array{response: Response|null, timed_out: bool}
     */
    private function send(string $model, array $payload, float $deadline, int $attemptTimeout): array
    {
        $url = $this->endpoint($model);
        $response = null;
        $timedOut = false;
        $ownEndpoint = $this->ownerSettings()->usesOwnEndpoint($this->driver());

        if ($ownEndpoint && ! app(PublicAiEndpoint::class)->allows($url)) {
            Log::warning($this->label().' is not a public HTTPS endpoint; the request was not sent', ['model' => $model]);

            throw NotebookAiException::misconfigured(NotebookAiException::CAUSE_MODEL, $this->label());
        }

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $timeout = min($attemptTimeout, max(1, (int) ceil($this->remaining($deadline))));
            $timedOut = false;

            try {
                $response = Http::withHeaders($this->headers())
                    ->acceptJson()
                    ->timeout($timeout)
                    ->when($ownEndpoint, fn (PendingRequest $request): PendingRequest => $request->withoutRedirecting())
                    ->post($url, $this->forModel($payload, $model));
            } catch (ConnectionException $exception) {
                $timedOut = $this->isTimeout($exception);
                Log::warning($this->label().' is unreachable', ['model' => $model, 'timeout' => $timeout, 'error' => $exception->getMessage()]);
                $response = null;
            }

            if ($response !== null && ! in_array($response->status(), $this->retryableStatuses(), true)) {
                return ['response' => $response, 'timed_out' => false];
            }

            $delay = max(0, (int) $this->setting('retry_delay_ms', 1200)) / 1000;

            if ($attempt === 2 || $this->remaining($deadline) - $delay < self::MIN_RETRY_SECONDS) {
                break;
            }

            usleep((int) ($delay * 1_000_000));
        }

        return ['response' => $response, 'timed_out' => $timedOut];
    }

    /**
     * The answer stopped at its output limit: ask the same model once more
     * with room for what it spent thinking and twice the answer, while the
     * budget allows. Without a larger answer, the cut one stands: a retry
     * that failed, thought through the larger limit without writing, or
     * was cut again with less text than the first does not replace it.
     *
     * @param  array<string, mixed>  $payload
     */
    private function askForMore(string $model, array $payload, AiResult $cut, float $deadline, int $attemptTimeout): AiResult
    {
        $limit = $this->outputLimit($payload);
        $larger = $limit !== null ? min($this->capabilities()->maxOutputTokens, 2 * $limit + (int) $cut->thoughtsTokens) : null;
        $retry = $larger !== null && $larger > $limit && $this->remaining($deadline) >= self::MIN_RETRY_SECONDS;

        Log::info($this->label().' answer stopped at its output limit', [
            'model' => $model,
            'max_output_tokens' => $limit,
            'thoughts_tokens' => $cut->thoughtsTokens,
            'output_tokens' => $cut->outputTokens,
            'retry_max_output_tokens' => $retry ? $larger : null,
        ]);

        if (! $retry) {
            return $cut;
        }

        ['response' => $response] = $this->send($model, $this->withOutputLimit($payload, $larger), $deadline, $attemptTimeout);

        if ($response === null || ! $response->successful()) {
            Log::warning($this->label().' retry with a larger output limit failed', ['model' => $model, 'status' => $response?->status()]);

            return $cut;
        }

        try {
            $more = $this->parse($response, $model);
        } catch (NotebookAiException $exception) {
            if ($exception->is(NotebookAiException::REASON_BLOCKED)) {
                throw $exception;
            }

            return $cut;
        }

        if ($more->truncated && mb_strlen($more->text) < mb_strlen($cut->text)) {
            Log::info($this->label().' retry with a larger output limit gave less text', [
                'model' => $model,
                'thoughts_tokens' => $more->thoughtsTokens,
                'output_tokens' => $more->outputTokens,
            ]);

            return $cut;
        }

        return $more;
    }

    /**
     * A file the model cannot read, or one over its size limit
     * (capabilities()), is refused before anything is sent.
     *
     * @throws NotebookAiException
     */
    private function guardInput(AiRequest $request): void
    {
        $capabilities = $this->capabilities();

        foreach ($request->messages as $message) {
            foreach ($message['parts'] as $part) {
                if ($part['type'] !== 'file') {
                    continue;
                }

                $readable = str_starts_with(strtolower($part['mime']), 'image/') ? $capabilities->imageInput : $capabilities->pdfInput;

                if (! $readable) {
                    throw NotebookAiException::unsupportedInput();
                }

                if (intdiv(strlen($part['base64']) * 3, 4) > $capabilities->maxBytesFor($part['mime'])) {
                    throw NotebookAiException::rejected();
                }
            }
        }
    }

    /**
     * Whether text written before the model declined to go on is thrown
     * away (the request fails as blocked) unless the request keeps it
     * (AiRequest::$keepBlockedText).
     */
    protected function discardsBlockedText(): bool
    {
        return true;
    }

    /**
     * An answer cut before any text is an error, not an empty answer; one
     * the model declined to finish is blocked, unless the request takes
     * what was written (a transcription).
     */
    private function complete(AiResult $result, AiRequest $request): AiResult
    {
        if ($result->stop === AiResult::STOP_BLOCKED && ! $request->keepBlockedText && $this->discardsBlockedText()) {
            throw NotebookAiException::blocked();
        }

        if ($result->text === '') {
            throw NotebookAiException::outputExhausted();
        }

        return $result;
    }

    /**
     * @param  NotebookAiException::CAUSE_*  $cause
     */
    private function rememberMisconfiguration(string $cause): void
    {
        try {
            Cache::put($this->misconfigurationKey(), $cause, self::MISCONFIGURATION_TTL_SECONDS);
        } catch (\Throwable $throwable) {
            Log::warning($this->label().' misconfiguration could not be remembered', ['error' => $throwable->getMessage()]);
        }
    }

    /**
     * One key per driver and configuration: fixing the key, a model id,
     * the endpoint or the reasoning setting forgets the rejection at once.
     */
    private function misconfigurationKey(): string
    {
        return 'notebook-ai:misconfigured:'.$this->driver().':'.sha1((string) json_encode([
            $this->key(),
            (string) $this->setting('model'),
            (string) $this->setting('fallback_model'),
            (string) $this->setting('base_url'),
            $this->reasoningSetting(),
        ]));
    }

    /**
     * The model did not answer within the attempt's timeout (cURL 28
     * "Operation timed out after N milliseconds with M bytes received"). A
     * connection or name lookup that timed out ("Connection timed out
     * after", "Resolving timed out after", or "Failed to connect … :
     * Operation timed out" with the system's own wording) means the model
     * could not be reached at all, which is not the request's fault.
     */
    private function isTimeout(ConnectionException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'operation timed out after') && ! str_contains($message, 'failed to connect');
    }

    private function remaining(float $deadline): float
    {
        return $deadline - microtime(true);
    }
}
