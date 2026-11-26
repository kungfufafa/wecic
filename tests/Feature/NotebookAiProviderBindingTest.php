<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Ai\AnthropicDriver;
use App\Services\Ai\GeminiDriver;
use App\Services\Ai\LazyNotebookAiClient;
use App\Services\Ai\NotebookAiClient;
use App\Services\Ai\NotebookAiOwnerSettings;
use App\Services\Ai\OpenAiCompatibleDriver;
use App\Services\NotebookAssistant;
use App\Services\NotebookLibrary;
use App\Services\NotebookSourceReader;
use App\Support\Ai\AiCapabilities;
use App\Support\Ai\AiRequest;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NotebookAiProviderBindingTest extends TestCase
{
    public function test_gemini_is_the_default_provider(): void
    {
        $this->assertSame('gemini', config('notebook.ai.provider'));
        $this->assertNull(config('notebook.ai.ingest_provider'));

        $client = app(NotebookAiClient::class);

        $this->assertInstanceOf(GeminiDriver::class, $this->driverOf($client));
        $this->assertSame('gemini', $client->driver());
        $this->assertSame('Google Gemini', $client->label());
        $this->assertTrue($client->capabilities()->pdfInput);
        $this->assertSame(AiCapabilities::STRUCTURED_JSON_SCHEMA, $client->capabilities()->structuredOutput);
    }

    public function test_the_notebook_services_resolve_with_the_configured_driver(): void
    {
        config(['notebook.ai.provider' => 'Gemini']);

        $this->assertInstanceOf(NotebookAssistant::class, app(NotebookAssistant::class));
        $this->assertInstanceOf(NotebookSourceReader::class, app(NotebookSourceReader::class));
        $this->assertInstanceOf(GeminiDriver::class, $this->driverOf(app(NotebookAiClient::class)));
    }

    /**
     * @return array<string, array{string, class-string<NotebookAiClient>}>
     */
    public static function providers(): array
    {
        return [
            'gemini' => ['gemini', GeminiDriver::class],
            'anthropic' => ['anthropic', AnthropicDriver::class],
            'openai' => ['openai', OpenAiCompatibleDriver::class],
            'openai_compatible' => ['openai_compatible', OpenAiCompatibleDriver::class],
        ];
    }

    /**
     * @param  class-string<NotebookAiClient>  $driver
     */
    #[DataProvider('providers')]
    public function test_each_provider_resolves_to_its_driver(string $provider, string $driver): void
    {
        config(['notebook.ai.provider' => $provider]);

        $this->assertInstanceOf($driver, $this->driverOf(app(NotebookAiClient::class)));
    }

    public function test_the_source_reader_may_read_with_gemini_while_another_provider_answers(): void
    {
        config(['notebook.ai.provider' => 'anthropic', 'notebook.ai.ingest_provider' => 'gemini']);

        $this->assertInstanceOf(AnthropicDriver::class, $this->driverOf(app(NotebookAiClient::class)));
        $this->assertInstanceOf(GeminiDriver::class, $this->driverOf($this->readerClient(app(NotebookSourceReader::class))));
    }

    public function test_an_unknown_provider_fails_with_a_clear_message(): void
    {
        config(['notebook.ai.provider' => 'skynet']);

        // Building it reads nothing; the first use names the problem.
        $client = app(NotebookAiClient::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown notebook AI provider [skynet]');

        $client->driver();
    }

    public function test_the_source_reader_follows_the_ingest_provider_when_one_is_set(): void
    {
        config(['notebook.ai.ingest_provider' => 'skynet']);

        // Chat keeps the main provider.
        $this->assertInstanceOf(GeminiDriver::class, $this->driverOf(app(NotebookAiClient::class)));
        $reader = $this->readerClient(app(NotebookSourceReader::class));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown notebook AI provider [skynet]');

        $reader->driver();
    }

    public function test_building_the_notebook_services_reads_no_ai_settings(): void
    {
        // A controller built with a notebook service it never calls (the dashboard's) must not read the student's AI settings.
        $settings = Mockery::mock(NotebookAiOwnerSettings::class);
        $settings->shouldNotReceive('current', 'provider', 'overrides', 'usesOwnEndpoint', 'usesOwnKey');
        $this->instance(NotebookAiOwnerSettings::class, $settings);

        app(NotebookAssistant::class);
        app(NotebookSourceReader::class);
        app(NotebookLibrary::class);
        app(NotebookAiClient::class);

        $this->assertInstanceOf(LazyNotebookAiClient::class, app(NotebookAiClient::class));
    }

    private function driverOf(NotebookAiClient $client): NotebookAiClient
    {
        return $client instanceof LazyNotebookAiClient ? $client->resolved() : $client;
    }

    private function readerClient(NotebookSourceReader $reader): NotebookAiClient
    {
        $client = (new \ReflectionProperty(NotebookSourceReader::class, 'ai'))->getValue($reader);
        $this->assertInstanceOf(NotebookAiClient::class, $client);

        return $client;
    }

    public function test_the_budgets_fall_back_to_the_older_gemini_settings(): void
    {
        config(['services.gemini.request_budget' => 42, 'services.gemini.ingest_budget' => 300]);

        $this->assertSame(42, AiRequest::requestBudgetSeconds());
        $this->assertSame(300, AiRequest::ingestBudgetSeconds());

        config(['notebook.ai.request_budget' => 30, 'notebook.ai.ingest_budget' => 200]);

        $this->assertSame(30, AiRequest::requestBudgetSeconds());
        $this->assertSame(200, AiRequest::ingestBudgetSeconds());
    }
}
