<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\Middleware\UseNotebookOwnerAiSettings;
use App\Models\Notebook;
use App\Models\NotebookAiConsent;
use App\Models\NotebookAiSetting;
use App\Models\NotebookChunk;
use App\Models\NotebookSource;
use App\Services\Ai\NotebookAiClient;
use App\Services\Ai\NotebookAiOwnerSettings;
use App\Services\Ai\NotebookAiProviders;
use App\Services\Ai\PublicAiEndpoint;
use App\Services\PortalDataOwner;
use App\Services\PortalSessionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NotebookAiSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'token-ai-settings';

    private const USERNAME = '20990000077';

    private const GEMINI_URL = 'https://generativelanguage.googleapis.com/v1beta/models/*';

    private const ANTHROPIC_URL = 'https://api.anthropic.com/v1/messages';

    private const OWN_ENDPOINT = 'https://llm.example.com/v1';

    private string $ownerHash;

    /**
     * Addresses the fake resolver gives each host (PublicAiEndpoint).
     *
     * @var array<string, list<string>>
     */
    private array $dns = ['llm.example.com' => ['203.0.113.10'], 'api.openai.com' => ['162.159.140.245']];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'notebook.ai.provider' => 'gemini',
            'notebook.ai.ingest_provider' => null,
            'services.gemini.key' => 'server-gemini-key',
            'services.gemini.model' => 'gemini-test-flash',
            'services.gemini.fallback_model' => null,
            'services.gemini.retry_delay_ms' => 0,
            'services.anthropic.key' => null,
            'services.anthropic.model' => 'claude-opus-5-5',
            'services.anthropic.fallback_model' => null,
            'services.anthropic.base_url' => 'https://api.anthropic.com',
            'services.anthropic.retry_delay_ms' => 0,
            'services.openai_compatible.key' => null,
            'services.openai_compatible.base_url' => 'https://api.openai.com/v1',
            'services.openai_compatible.model' => null,
            'services.openai_compatible.fallback_model' => 'server-fallback-model',
            'services.openai_compatible.retry_delay_ms' => 0,
            'notebook.thinking_budget' => null,
        ]);

        Http::fake([
            self::GEMINI_URL => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'Normalisasi mengurangi redundansi [1].']]], 'finishReason' => 'STOP']]]),
            self::ANTHROPIC_URL => Http::response([
                'id' => 'msg_test',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-opus-5-5',
                'content' => [['type' => 'text', 'text' => 'Normalisasi mengurangi redundansi [1].']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 120, 'output_tokens' => 12],
            ]),
            self::OWN_ENDPOINT.'/chat/completions' => Http::response([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'Normalisasi mengurangi redundansi [1].'], 'finish_reason' => 'stop']],
            ]),
        ]);

        $dns = &$this->dns;
        $this->app->instance(PublicAiEndpoint::class, new class($dns) extends PublicAiEndpoint
        {
            /**
             * @param  array<string, list<string>>  $dns
             */
            public function __construct(private array &$dns) {}

            protected function resolve(string $host): array
            {
                return $this->dns[$host] ?? [];
            }
        });

        $this->instance(PortalSessionManager::class, Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('validateSession')->andReturnTrue();
        }));
        app(PortalDataOwner::class)->remember(self::TOKEN, self::USERNAME, 3600);
        $this->ownerHash = app(PortalDataOwner::class)->keyForUsername(self::USERNAME);
    }

    protected function tearDown(): void
    {
        Cache::flush();
        Mockery::close();

        parent::tearDown();
    }

    public function test_a_student_without_settings_follows_the_server(): void
    {
        $this->sendAsStudent('GET', '/api/v1/ai-settings')
            ->assertOk()
            ->assertJsonPath('data.settings.provider', null)
            ->assertJsonPath('data.settings.active_provider', 'gemini')
            ->assertJsonPath('data.settings.server_provider', 'gemini')
            ->assertJsonPath('data.settings.providers.gemini.has_key', false)
            ->assertJsonPath('data.settings.providers.gemini.server_configured', true)
            ->assertJsonPath('data.settings.providers.anthropic.server_configured', false)
            ->assertJsonPath('data.settings.providers.openai.server_configured', false)
            ->assertJsonPath('data.settings.providers.openai.base_url', null);
    }

    public function test_the_settings_need_a_campus_session(): void
    {
        $this->getJson('/api/v1/ai-settings')->assertUnauthorized();
        $this->withSession(['_token' => 'known-csrf-token'])
            ->putJson('/api/ai-settings', ['provider' => 'gemini'], ['X-CSRF-TOKEN' => 'known-csrf-token'])
            ->assertUnauthorized();
        $this->withSession(['_token' => 'known-csrf-token'])
            ->deleteJson('/api/ai-settings', [], ['X-CSRF-TOKEN' => 'known-csrf-token'])
            ->assertUnauthorized();
        $this->assertDatabaseCount('notebook_ai_settings', 0);
    }

    public function test_a_saved_key_is_stored_encrypted_and_only_shown_masked(): void
    {
        $response = $this->sendAsStudent('PUT', '/api/v1/ai-settings', [
            'provider' => 'anthropic',
            'anthropic_api_key' => 'sk-ant-student-secret-ABCD',
        ])->assertOk()
            ->assertJsonPath('data.settings.provider', 'anthropic')
            ->assertJsonPath('data.settings.active_provider', 'anthropic')
            ->assertJsonPath('data.settings.providers.anthropic.has_key', true)
            ->assertJsonPath('data.settings.providers.anthropic.key_preview', '••••••••ABCD');

        $this->assertStringNotContainsString('sk-ant-student-secret', $response->getContent());

        $raw = DB::table('notebook_ai_settings')->where('owner_hash', $this->ownerHash)->value('anthropic_api_key');
        $this->assertIsString($raw);
        $this->assertStringNotContainsString('sk-ant-student-secret', $raw);
        $this->assertSame('sk-ant-student-secret-ABCD', NotebookAiSetting::query()->forOwner($this->ownerHash)->firstOrFail()->anthropic_api_key);

        $this->assertStringNotContainsString('sk-ant-student-secret', $this->sendAsStudent('GET', '/api/v1/ai-settings')->getContent());
    }

    public function test_an_empty_key_keeps_the_saved_one_and_a_clear_flag_removes_it(): void
    {
        NotebookAiSetting::factory()->forOwner($this->ownerHash)->gemini('student-gemini-key-1234')->create();

        $this->sendAsStudent('PUT', '/api/v1/ai-settings', ['provider' => 'gemini', 'gemini_api_key' => ''])
            ->assertOk()
            ->assertJsonPath('data.settings.providers.gemini.key_preview', '••••••••1234');
        $this->assertSame('student-gemini-key-1234', NotebookAiSetting::query()->forOwner($this->ownerHash)->firstOrFail()->gemini_api_key);

        $this->sendAsStudent('PUT', '/api/v1/ai-settings', ['provider' => 'gemini', 'gemini_api_key' => 'student-gemini-key-5678'])
            ->assertOk()
            ->assertJsonPath('data.settings.providers.gemini.key_preview', '••••••••5678');

        $this->sendAsStudent('PUT', '/api/v1/ai-settings', ['provider' => 'gemini', 'clear_gemini_api_key' => true])
            ->assertOk()
            ->assertJsonPath('data.settings.providers.gemini.has_key', false)
            ->assertJsonPath('data.settings.providers.gemini.key_preview', null);
        $this->assertNull(NotebookAiSetting::query()->forOwner($this->ownerHash)->firstOrFail()->gemini_api_key);
    }

    public function test_settings_left_all_empty_are_removed(): void
    {
        NotebookAiSetting::factory()->forOwner($this->ownerHash)->gemini()->create();

        $this->sendAsStudent('PUT', '/api/v1/ai-settings', ['provider' => null, 'clear_gemini_api_key' => true])->assertOk();

        $this->assertDatabaseCount('notebook_ai_settings', 0);
    }

    public function test_clearing_all_settings_returns_to_the_server(): void
    {
        NotebookAiSetting::factory()->forOwner($this->ownerHash)->anthropic()->create();

        $this->sendAsStudent('DELETE', '/api/v1/ai-settings')
            ->assertOk()
            ->assertJsonPath('data.settings.provider', null)
            ->assertJsonPath('data.settings.active_provider', 'gemini')
            ->assertJsonPath('data.settings.providers.anthropic.has_key', false);

        $this->assertDatabaseCount('notebook_ai_settings', 0);
    }

    public function test_a_provider_needs_a_key_from_the_student_or_the_server(): void
    {
        $this->sendAsStudent('PUT', '/api/v1/ai-settings', ['provider' => 'anthropic'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['provider']);

        $this->sendAsStudent('PUT', '/api/v1/ai-settings', ['provider' => 'gemini'])->assertOk();
        $this->sendAsStudent('PUT', '/api/v1/ai-settings', ['provider' => 'mistral'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['provider']);
    }

    public function test_a_key_with_spaces_is_refused(): void
    {
        $this->sendAsStudent('PUT', '/api/v1/ai-settings', ['gemini_api_key' => 'two words'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['gemini_api_key']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unreachableEndpoints(): array
    {
        return [
            'plain http' => ['http://llm.example.com/v1'],
            'localhost' => ['https://localhost/v1'],
            'loopback address' => ['https://127.0.0.1/v1'],
            'private address' => ['https://10.0.0.5/v1'],
            'metadata address' => ['https://169.254.169.254/latest'],
            'test host' => ['https://wecic.test/v1'],
            'name resolving to a private address' => ['https://internal.example.org/v1'],
            'name that does not resolve' => ['https://nowhere.example.net/v1'],
            'credentials in the url' => ['https://user:pass@llm.example.com/v1'],
            'not a url' => ['llm.example.com'],
        ];
    }

    #[DataProvider('unreachableEndpoints')]
    public function test_an_own_endpoint_must_be_public_https(string $baseUrl): void
    {
        $this->dns['internal.example.org'] = ['192.168.1.20'];

        $this->sendAsStudent('PUT', '/api/v1/ai-settings', [
            'provider' => 'openai',
            'openai_api_key' => 'sk-student',
            'openai_base_url' => $baseUrl,
            'openai_model' => 'student-model',
        ])->assertUnprocessable()->assertJsonValidationErrors(['openai_base_url']);

        $this->assertDatabaseCount('notebook_ai_settings', 0);
    }

    public function test_an_own_endpoint_needs_its_own_key_and_model(): void
    {
        config(['services.openai_compatible.key' => 'server-openai-key', 'services.openai_compatible.model' => 'server-model']);

        $this->sendAsStudent('PUT', '/api/v1/ai-settings', ['provider' => 'openai', 'openai_base_url' => self::OWN_ENDPOINT])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['openai_api_key', 'openai_model']);
    }

    public function test_questions_use_the_students_own_anthropic_key(): void
    {
        $notebook = $this->notebookWithReadySource();

        $this->sendAsStudent('PUT', '/api/v1/ai-settings', ['provider' => 'anthropic', 'anthropic_api_key' => 'sk-ant-student-key'])
            ->assertOk()
            ->assertJsonPath('data.consent.granted', false)
            ->assertJsonPath('data.consent.provider', 'Anthropic Claude');
        $this->grantConsent();

        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/messages", ['question' => 'Apa itu normalisasi?'])->assertCreated();

        Http::assertSent(fn (Request $request): bool => $request->url() === self::ANTHROPIC_URL && $request->header('x-api-key') === ['sk-ant-student-key']);
        Http::assertNotSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://generativelanguage.googleapis.com'));
    }

    public function test_questions_use_the_students_own_gemini_key_and_the_servers_without_one(): void
    {
        $notebook = $this->notebookWithReadySource();
        $this->grantConsent();

        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/messages", ['question' => 'Apa itu normalisasi?'])->assertCreated();
        Http::assertSent(fn (Request $request): bool => $request->header('x-goog-api-key') === ['server-gemini-key']);

        $this->sendAsStudent('PUT', '/api/v1/ai-settings', ['gemini_api_key' => 'student-gemini-key'])->assertOk()->assertJsonPath('data.consent.granted', true);
        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/messages", ['question' => 'Lalu denormalisasi?'])->assertCreated();
        Http::assertSent(fn (Request $request): bool => $request->header('x-goog-api-key') === ['student-gemini-key']);
    }

    public function test_questions_go_to_the_students_own_openai_compatible_endpoint(): void
    {
        config(['services.openai_compatible.key' => 'server-openai-key', 'services.openai_compatible.model' => 'server-model']);
        $notebook = $this->notebookWithReadySource();

        $this->sendAsStudent('PUT', '/api/v1/ai-settings', [
            'provider' => 'openai',
            'openai_api_key' => 'sk-student-openai',
            'openai_base_url' => self::OWN_ENDPOINT,
            'openai_model' => 'student-model',
        ])->assertOk()
            ->assertJsonPath('data.settings.providers.openai.base_url', self::OWN_ENDPOINT)
            ->assertJsonPath('data.settings.providers.openai.model', 'student-model')
            ->assertJsonPath('data.consent.provider', 'layanan AI di llm.example.com');
        $this->grantConsent();

        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/messages", ['question' => 'Apa itu normalisasi?'])->assertCreated();

        Http::assertSent(fn (Request $request): bool => $request->url() === self::OWN_ENDPOINT.'/chat/completions'
            && $request->header('Authorization') === ['Bearer sk-student-openai']
            && $request['model'] === 'student-model');
        Http::assertNotSent(fn (Request $request): bool => in_array('Bearer server-openai-key', $request->header('Authorization'), true));
    }

    public function test_an_own_endpoint_that_now_resolves_privately_is_never_called(): void
    {
        NotebookAiSetting::factory()->forOwner($this->ownerHash)->openAi()->create();
        $notebook = $this->notebookWithReadySource();
        $this->grantConsent();
        $this->dns['llm.example.com'] = ['10.1.2.3'];

        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/messages", ['question' => 'Apa itu normalisasi?']);

        Http::assertNotSent(fn (Request $request): bool => str_starts_with($request->url(), self::OWN_ENDPOINT));
    }

    public function test_the_server_key_never_goes_to_an_own_endpoint(): void
    {
        config(['services.openai_compatible.key' => 'server-openai-key', 'services.openai_compatible.model' => 'server-model']);
        NotebookAiSetting::factory()->forOwner($this->ownerHash)->openAi(key: '')->create(['openai_api_key' => null]);

        app(NotebookAiOwnerSettings::class)->actAs($this->ownerHash);
        $client = app(NotebookAiClient::class);

        $this->assertSame('openai', $client->driver());
        $this->assertFalse($client->isConfigured());
    }

    public function test_one_students_settings_do_not_reach_another_student(): void
    {
        NotebookAiSetting::factory()->forOwner('student_someone_else')->anthropic()->create();

        $this->sendAsStudent('GET', '/api/v1/ai-settings')
            ->assertOk()
            ->assertJsonPath('data.settings.active_provider', 'gemini')
            ->assertJsonPath('data.settings.providers.anthropic.has_key', false);
    }

    public function test_a_queued_job_reads_with_the_settings_of_the_notebooks_owner(): void
    {
        NotebookAiSetting::factory()->forOwner($this->ownerHash)->anthropic()->create();
        $middleware = new UseNotebookOwnerAiSettings(fn (): string => $this->ownerHash);

        $this->assertSame('gemini', app(NotebookAiClient::class)->driver());

        $driver = $middleware->handle(new \stdClass, fn (): string => app(NotebookAiClient::class)->driver());

        $this->assertSame('anthropic', $driver);
        $this->assertSame('gemini', app(NotebookAiClient::class)->driver());
    }

    public function test_the_ingest_provider_set_on_the_server_still_reads_files(): void
    {
        config(['notebook.ai.ingest_provider' => 'gemini']);
        NotebookAiSetting::factory()->forOwner($this->ownerHash)->anthropic()->create();
        app(NotebookAiOwnerSettings::class)->actAs($this->ownerHash);

        $providers = app(NotebookAiProviders::class);

        $this->assertSame('anthropic', $providers->chat()->driver());
        $this->assertSame('gemini', $providers->ingest()->driver());
    }

    public function test_the_mask_shows_only_the_last_four_characters(): void
    {
        $this->assertNull(NotebookAiSetting::mask(null));
        $this->assertSame('••••••••', NotebookAiSetting::mask('short'));
        $this->assertSame('••••••••WXYZ', NotebookAiSetting::mask('sk-abcdefWXYZ'));
    }

    private function grantConsent(): void
    {
        NotebookAiConsent::query()->where('owner_hash', $this->ownerHash)->delete();
        $this->sendAsStudent('POST', '/api/v1/ai-consent', ['accept' => true])->assertOk();
    }

    private function notebookWithReadySource(): Notebook
    {
        $notebook = Notebook::factory()->forOwner($this->ownerHash)->create();
        $source = NotebookSource::factory()->create(['notebook_id' => $notebook->id, 'title' => 'Modul']);
        NotebookChunk::query()->create(['notebook_source_id' => $source->id, 'notebook_id' => $notebook->id, 'position' => 0, 'page' => 1, 'content' => 'Normalisasi mengurangi redundansi.']);

        return $notebook;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function sendAsStudent(string $method, string $uri, array $payload = []): TestResponse
    {
        return $this->withSession(['_token' => 'known-csrf-token', 'campus_session_token' => self::TOKEN])
            ->json($method, $uri, $payload, ['X-CSRF-TOKEN' => 'known-csrf-token']);
    }
}
