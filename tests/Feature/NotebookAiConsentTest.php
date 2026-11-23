<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Notebook;
use App\Models\NotebookAiConsent;
use App\Models\NotebookChunk;
use App\Models\NotebookMessage;
use App\Models\NotebookSource;
use App\Models\PortalArchivedFile;
use App\Services\NotebookAiConsents;
use App\Services\NotebookLibrary;
use App\Services\PortalDataOwner;
use App\Services\PortalSessionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class NotebookAiConsentTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'token-ai-consent';

    private const USERNAME = '20990000031';

    private const GEMINI_URL = 'https://generativelanguage.googleapis.com/v1beta/models/*';

    private string $ownerHash;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config([
            'services.gemini.key' => 'test-gemini-key',
            'services.gemini.model' => 'gemini-test-flash',
            'services.gemini.fallback_model' => null,
            'services.gemini.retry_delay_ms' => 0,
            'notebook.thinking_budget' => null,
        ]);
        Http::fake([self::GEMINI_URL => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => "[[HALAMAN 1]]\nNormalisasi mengurangi redundansi [1]."]]], 'finishReason' => 'STOP']],
        ])]);
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

    public function test_a_student_has_not_consented_until_they_agree(): void
    {
        $this->getAsStudent('/api/v1/ai-consent')
            ->assertOk()
            ->assertJsonPath('data.consent.granted', false)
            ->assertJsonPath('data.consent.granted_at', null)
            ->assertJsonPath('data.consent.provider', 'Google Gemini')
            ->assertJsonPath('data.consent.free_tier', true);

        $this->getAsStudent('/api/notebooks')
            ->assertOk()
            ->assertJsonPath('data.ai_consent.granted', false);
        $this->assertSame(0, NotebookAiConsent::query()->count());
    }

    public function test_granting_needs_an_explicit_accept(): void
    {
        $this->sendAsStudent('POST', '/api/v1/ai-consent')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Centang persetujuan dulu sebelum mengaktifkan AI Notebook.');
        $this->sendAsStudent('POST', '/api/v1/ai-consent', ['accept' => false])->assertStatus(422);

        $this->assertFalse(app(NotebookAiConsents::class)->granted($this->ownerHash));
    }

    public function test_a_student_grants_and_withdraws_consent(): void
    {
        $this->travelTo(now()->startOfMinute());

        $this->sendAsStudent('POST', '/api/v1/ai-consent', ['accept' => true])
            ->assertOk()
            ->assertJsonPath('data.consent.granted', true)
            ->assertJsonPath('data.consent.granted_at', now()->toISOString())
            ->assertJsonPath('data.consent.withdrawn_at', null);

        $grantedAt = now()->toISOString();
        $this->travel(5)->minutes();

        $this->sendAsStudent('POST', '/api/ai-consent', ['accept' => true])
            ->assertOk()
            ->assertJsonPath('data.consent.granted_at', $grantedAt);
        $this->getAsStudent('/api/v1/ai-consent')->assertOk()->assertJsonPath('data.consent.granted', true);

        $this->sendAsStudent('DELETE', '/api/v1/ai-consent')
            ->assertOk()
            ->assertJsonPath('data.consent.granted', false)
            ->assertJsonPath('data.consent.granted_at', null)
            ->assertJsonPath('data.consent.withdrawn_at', now()->toISOString());

        $consent = NotebookAiConsent::query()->sole();
        $this->assertSame($this->ownerHash, $consent->owner_hash);
        $this->assertNull($consent->granted_at);
        $this->assertFalse(app(NotebookAiConsents::class)->granted($this->ownerHash));
    }

    /**
     * The grant inserts first and reads the stored row when the insert
     * collides, as the second of two simultaneous grants does.
     */
    public function test_a_grant_whose_insert_collides_reads_the_stored_consent(): void
    {
        $this->travelTo(now()->startOfMinute());
        NotebookAiConsent::query()->insert([
            'owner_hash' => $this->ownerHash,
            'granted_at' => now(),
            'withdrawn_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->travel(1)->minutes();

        $this->sendAsStudent('POST', '/api/v1/ai-consent', ['accept' => true])
            ->assertOk()
            ->assertJsonPath('data.consent.granted', true)
            ->assertJsonPath('data.consent.granted_at', now()->subMinute()->toISOString());

        $this->assertSame(1, NotebookAiConsent::query()->forOwner($this->ownerHash)->count());
    }

    public function test_withdrawing_without_a_consent_stores_nothing(): void
    {
        $this->sendAsStudent('DELETE', '/api/v1/ai-consent')
            ->assertOk()
            ->assertJsonPath('data.consent.granted', false);

        $this->assertSame(0, NotebookAiConsent::query()->count());
    }

    public function test_consent_endpoints_need_a_campus_session(): void
    {
        $this->getJson('/api/v1/ai-consent')->assertUnauthorized();
        $this->withSession(['_token' => 'known-csrf-token'])
            ->postJson('/api/ai-consent', ['accept' => true], ['X-CSRF-TOKEN' => 'known-csrf-token'])
            ->assertUnauthorized();

        $this->assertSame(0, NotebookAiConsent::query()->count());
    }

    public function test_the_ai_actions_are_refused_without_consent_and_nothing_reaches_gemini(): void
    {
        $notebook = $this->notebookWithReadySource();
        $failed = NotebookSource::factory()->create(['notebook_id' => $notebook->id, 'kind' => NotebookSource::KIND_UPLOAD, 'mime' => 'application/pdf', 'status' => NotebookSource::STATUS_FAILED]);
        $archived = PortalArchivedFile::factory()->forOwner($this->ownerHash)->create();

        $this->assertConsentRequired($this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/messages", ['question' => 'Apa itu normalisasi?']));
        $this->assertConsentRequired($this->sendAsStudent('POST', "/api/v1/notebooks/{$notebook->id}/studio", ['kind' => 'summary']));
        $this->assertConsentRequired($this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/sources", ['type' => 'upload', 'file' => UploadedFile::fake()->createWithContent('modul.pdf', '%PDF-1.4 isi')]));
        $this->assertConsentRequired($this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/sources", ['type' => 'archived_file', 'archived_file_ids' => [$archived->id]]));
        $this->assertConsentRequired($this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/sources/{$failed->id}/retry"));

        Http::assertNothingSent();
        $this->assertSame(0, NotebookMessage::query()->count());
        $this->assertSame(2, $notebook->sources()->count());
        $this->assertSame(NotebookSource::STATUS_FAILED, $failed->refresh()->status);
    }

    public function test_the_notebook_keeps_working_without_ai_consent(): void
    {
        $notebook = $this->notebookWithReadySource();
        $source = $notebook->sources()->sole();
        $message = NotebookMessage::factory()->create(['notebook_id' => $notebook->id, 'kind' => 'summary', 'content' => 'Ringkasan lama', 'pinned' => true]);

        $this->getAsStudent('/api/notebooks')->assertOk()->assertJsonCount(1, 'data.notebooks');
        $this->getAsStudent("/api/notebooks/{$notebook->id}")
            ->assertOk()
            ->assertJsonPath('data.ai_consent.granted', false)
            ->assertJsonCount(1, 'data.sources');
        $this->sendAsStudent('PATCH', "/api/notebooks/{$notebook->id}", ['title' => 'Basis Data'])->assertOk();
        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/sources", ['type' => 'text', 'title' => 'Catatan', 'content' => 'Kunci primer itu unik.'])
            ->assertCreated()
            ->assertJsonPath('data.sources.0.status', 'ready')
            ->assertJsonPath('data.sources.0.summary', null);
        $this->sendAsStudent('PATCH', "/api/notebooks/{$notebook->id}/messages/{$message->id}", ['pinned' => false])->assertOk();
        $this->sendAsStudent('DELETE', "/api/notebooks/{$notebook->id}/messages/{$message->id}")->assertOk();
        $this->sendAsStudent('DELETE', "/api/notebooks/{$notebook->id}/sources/{$source->id}")->assertOk();
        $this->sendAsStudent('DELETE', "/api/notebooks/{$notebook->id}")->assertOk();

        Http::assertNothingSent();
        $this->assertFalse(Notebook::query()->whereKey($notebook->id)->exists());
    }

    public function test_questions_are_answered_once_consent_is_given_and_refused_again_after_it_is_withdrawn(): void
    {
        $notebook = $this->notebookWithReadySource();

        $this->sendAsStudent('POST', '/api/v1/ai-consent', ['accept' => true])->assertOk();
        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/messages", ['question' => 'Apa itu normalisasi?'])->assertCreated();
        Http::assertSentCount(1);

        $this->sendAsStudent('DELETE', '/api/v1/ai-consent')->assertOk();
        $this->assertConsentRequired($this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/messages", ['question' => 'Lalu denormalisasi?']));
        Http::assertSentCount(1);
    }

    public function test_a_course_file_that_needs_the_model_waits_for_consent_and_is_read_after_it(): void
    {
        $notebook = Notebook::factory()->forOwner($this->ownerHash)->course()->create();
        $file = PortalArchivedFile::factory()->forOwner($this->ownerHash)->create(['course_key' => '8810', 'mime' => 'application/pdf', 'filename' => 'modul.pdf']);
        Storage::disk('local')->put($file->blobPath(), '%PDF-1.4 normalisasi');
        app(NotebookLibrary::class)->addArchivedFile($notebook, $file, byStudent: false);

        $this->getAsStudent("/api/notebooks/{$notebook->id}")->assertOk();

        $source = NotebookSource::query()->sole();
        $this->assertSame(NotebookSource::STATUS_PENDING, $source->status);
        $this->assertTrue($source->isWaiting());
        $this->assertStringContainsString('Setujui pengiriman data ke AI', (string) $source->error);
        Http::assertNothingSent();

        $this->sendAsStudent('POST', '/api/v1/ai-consent', ['accept' => true])->assertOk();
        $this->getAsStudent("/api/notebooks/{$notebook->id}")->assertOk();

        $this->assertSame(NotebookSource::STATUS_READY, $source->refresh()->status);
        $this->assertNotEmpty(NotebookChunk::query()->where('notebook_source_id', $source->id)->pluck('content')->all());
    }

    public function test_a_file_the_student_asked_for_fails_with_a_consent_message_after_consent_was_withdrawn(): void
    {
        NotebookAiConsent::factory()->forOwner($this->ownerHash)->withdrawn()->create();
        $notebook = Notebook::factory()->forOwner($this->ownerHash)->create();
        $file = PortalArchivedFile::factory()->forOwner($this->ownerHash)->create(['mime' => 'application/pdf', 'filename' => 'modul.pdf']);
        Storage::disk('local')->put($file->blobPath(), '%PDF-1.4 normalisasi');

        $source = app(NotebookLibrary::class)->addArchivedFile($notebook, $file);

        $this->assertSame(NotebookSource::STATUS_FAILED, $source->status);
        $this->assertStringContainsString('Baca ulang', (string) $source->error);
        Http::assertNothingSent();
    }

    public function test_the_consent_names_the_provider_the_server_uses(): void
    {
        $this->useAnthropic();

        $this->getAsStudent('/api/v1/ai-consent')
            ->assertOk()
            ->assertJsonPath('data.consent.granted', false)
            ->assertJsonPath('data.consent.provider', 'Anthropic Claude')
            ->assertJsonPath('data.consent.free_tier', false);

        $this->getAsStudent('/api/notebooks')
            ->assertOk()
            ->assertJsonPath('data.ai_provider', [
                'driver' => 'anthropic',
                'label' => 'Anthropic Claude',
                'free_tier' => false,
                'consent_key' => 'anthropic',
                'processors' => [['label' => 'Anthropic Claude', 'free_tier' => false]],
            ])
            ->assertJsonPath('data.ai_consent.provider', 'Anthropic Claude');

        $this->sendAsStudent('POST', '/api/v1/ai-consent', ['accept' => true])
            ->assertOk()
            ->assertJsonPath('message', 'AI Notebook aktif. Isi yang kamu kirim ke AI diproses oleh Anthropic Claude.');
        $this->assertSame('anthropic', NotebookAiConsent::query()->sole()->provider);
    }

    public function test_a_consent_names_both_providers_when_another_one_reads_files(): void
    {
        $this->useAnthropic(['notebook.ai.ingest_provider' => 'gemini']);
        NotebookAiConsent::factory()->forOwner($this->ownerHash)->forProvider('anthropic')->create();

        $this->getAsStudent('/api/v1/ai-consent')
            ->assertOk()
            ->assertJsonPath('data.consent.granted', false)
            ->assertJsonPath('data.consent.provider', 'Anthropic Claude dan Google Gemini')
            // Gemini reads the files on its free tier, so the student is told, for Gemini alone.
            ->assertJsonPath('data.consent.free_tier', true)
            ->assertJsonPath('data.consent.processors', [
                ['label' => 'Anthropic Claude', 'free_tier' => false],
                ['label' => 'Google Gemini', 'free_tier' => true],
            ])
            ->assertJsonPath('data.consent.consent_key', 'anthropic+gemini:free');
        $this->assertStringContainsString('ke Anthropic Claude dan Google Gemini.', app(NotebookAiConsents::class)->requiredMessage());

        $this->sendAsStudent('POST', '/api/v1/ai-consent', ['accept' => true])->assertOk()->assertJsonPath('data.consent.granted', true);
        $this->assertSame('anthropic+gemini:free', NotebookAiConsent::query()->sole()->provider);
    }

    public function test_a_gemini_consent_no_longer_holds_once_the_server_uses_another_provider(): void
    {
        $this->travelTo(now()->startOfMinute());
        NotebookAiConsent::factory()->forOwner($this->ownerHash)->forProvider('gemini')->create();
        $notebook = $this->notebookWithReadySource();
        $this->useAnthropic();
        $this->travel(1)->minutes();

        $this->getAsStudent("/api/notebooks/{$notebook->id}")
            ->assertOk()
            ->assertJsonPath('data.ai_consent.granted', false)
            ->assertJsonPath('data.ai_consent.granted_at', null)
            ->assertJsonPath('data.ai_consent.provider', 'Anthropic Claude');
        $this->assertConsentRequired($this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/messages", ['question' => 'Apa itu normalisasi?']));
        $this->assertConsentRequired($this->sendAsStudent('POST', "/api/v1/notebooks/{$notebook->id}/studio", ['kind' => 'summary']));
        Http::assertNothingSent();
        $this->assertSame(0, NotebookMessage::query()->count());

        // Agreeing again records the new provider and when it was given.
        $this->sendAsStudent('POST', '/api/v1/ai-consent', ['accept' => true])
            ->assertOk()
            ->assertJsonPath('data.consent.granted', true)
            ->assertJsonPath('data.consent.granted_at', now()->toISOString());
        $this->assertSame('anthropic', NotebookAiConsent::query()->sole()->provider);
        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/messages", ['question' => 'Apa itu normalisasi?'])->assertCreated();
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.anthropic.com/v1/messages');
    }

    public function test_a_consent_stored_before_providers_were_named_still_holds_for_gemini(): void
    {
        NotebookAiConsent::factory()->forOwner($this->ownerHash)->legacy()->create();
        $notebook = $this->notebookWithReadySource();

        $this->assertTrue(app(NotebookAiConsents::class)->granted($this->ownerHash));
        $this->getAsStudent('/api/v1/ai-consent')->assertOk()->assertJsonPath('data.consent.granted', true);
        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/messages", ['question' => 'Apa itu normalisasi?'])->assertCreated();
        Http::assertSentCount(1);

        // Granting again keeps the consent as it was.
        $this->sendAsStudent('POST', '/api/v1/ai-consent', ['accept' => true])->assertOk();
        $this->assertNull(NotebookAiConsent::query()->sole()->provider);

        // It was given for Gemini only.
        $this->useAnthropic();
        $this->assertFalse(app(NotebookAiConsents::class)->granted($this->ownerHash));
    }

    public function test_a_consent_no_longer_holds_once_the_same_driver_sends_content_to_another_endpoint(): void
    {
        config([
            'notebook.ai.provider' => 'openai',
            'services.openai_compatible.base_url' => 'http://localhost:11434/v1',
            'services.openai_compatible.label' => 'Model lokal WeCIC',
            'services.openai_compatible.model' => 'llama-test',
        ]);
        $this->sendAsStudent('POST', '/api/v1/ai-consent', ['accept' => true])->assertOk();
        $this->assertSame('openai@localhost', NotebookAiConsent::query()->sole()->provider);
        $this->assertTrue(app(NotebookAiConsents::class)->granted($this->ownerHash));

        config(['services.openai_compatible.base_url' => 'https://api.openai.com/v1', 'services.openai_compatible.label' => 'OpenAI']);
        $this->assertFalse(app(NotebookAiConsents::class)->granted($this->ownerHash));
        $this->getAsStudent('/api/v1/ai-consent')
            ->assertOk()
            ->assertJsonPath('data.consent.granted', false)
            ->assertJsonPath('data.consent.consent_key', 'openai@api.openai.com')
            ->assertJsonPath('data.consent.past_processors', ['layanan AI di localhost']);

        // Claude through a proxy is another recipient than Anthropic's own API.
        NotebookAiConsent::query()->update(['provider' => 'anthropic']);
        $this->useAnthropic(['services.anthropic.base_url' => 'https://claude-proxy.example.com']);
        $this->assertFalse(app(NotebookAiConsents::class)->granted($this->ownerHash));
        $this->assertSame('anthropic@claude-proxy.example.com', app(NotebookAiConsents::class)->currentKey());
    }

    public function test_a_consent_no_longer_holds_once_a_provider_moves_to_its_free_tier(): void
    {
        config(['services.gemini.free_tier' => false]);
        $this->sendAsStudent('POST', '/api/v1/ai-consent', ['accept' => true])->assertOk()->assertJsonPath('data.consent.free_tier', false);
        $this->assertSame('gemini', NotebookAiConsent::query()->sole()->provider);

        config(['services.gemini.free_tier' => true]);
        $this->getAsStudent('/api/v1/ai-consent')->assertOk()->assertJsonPath('data.consent.granted', false)->assertJsonPath('data.consent.free_tier', true);

        $this->sendAsStudent('POST', '/api/v1/ai-consent', ['accept' => true])->assertOk();
        $this->assertSame('gemini:free', NotebookAiConsent::query()->sole()->provider);

        // Leaving the free tier needs no new consent: the student was told more than now applies.
        config(['services.gemini.free_tier' => false]);
        $this->assertTrue(app(NotebookAiConsents::class)->granted($this->ownerHash));
    }

    public function test_a_consent_for_providers_the_student_was_not_shown_is_refused_with_the_current_ones(): void
    {
        $shown = app(NotebookAiConsents::class)->currentKey();
        $this->assertSame('gemini:free', $shown);
        $this->useAnthropic();

        $this->sendAsStudent('POST', '/api/v1/ai-consent', ['accept' => true, 'consent_key' => $shown])
            ->assertStatus(409)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('data.consent.granted', false)
            ->assertJsonPath('data.consent.provider', 'Anthropic Claude')
            ->assertJsonPath('data.consent.consent_key', 'anthropic');
        $this->assertSame(0, NotebookAiConsent::query()->count());

        $this->sendAsStudent('POST', '/api/v1/ai-consent', ['accept' => true, 'consent_key' => 'anthropic'])
            ->assertOk()
            ->assertJsonPath('data.consent.granted', true);
        $this->assertSame('anthropic', NotebookAiConsent::query()->sole()->provider);
    }

    public function test_the_deletion_page_names_only_the_providers_on_a_free_tier_as_free(): void
    {
        $this->useAnthropic(['notebook.ai.ingest_provider' => 'gemini']);

        $this->get('/hapus-data')
            ->assertOk()
            ->assertSee('(WeCIC memakai tingkat gratis Google Gemini)')
            ->assertSee('(WeCIC uses the free tier of Google Gemini)')
            ->assertDontSee('tingkat gratis Anthropic Claude')
            ->assertDontSee('free tier of Anthropic Claude');
    }

    public function test_providers_that_received_content_before_stay_named_after_a_switch(): void
    {
        NotebookAiConsent::factory()->forOwner($this->ownerHash)->legacy()->create();
        $this->useAnthropic();

        $this->getAsStudent('/api/v1/ai-consent')
            ->assertOk()
            ->assertJsonPath('data.consent.past_processors', ['Google Gemini']);
        $this->get('/hapus-data')
            ->assertOk()
            ->assertSee('Data yang sudah dikirim ke <strong>Anthropic Claude</strong>', false)
            ->assertSee('Data yang dulu dikirim ke <strong>Google Gemini</strong>', false);
        $this->get('/privasi')
            ->assertOk()
            ->assertSee('Google Gemini (sebelumnya)');
    }

    public function test_a_waiting_file_names_the_provider_that_reads_it(): void
    {
        $this->useAnthropic(['notebook.ai.ingest_provider' => 'gemini']);
        $notebook = Notebook::factory()->forOwner($this->ownerHash)->create();
        $file = PortalArchivedFile::factory()->forOwner($this->ownerHash)->create(['mime' => 'application/pdf', 'filename' => 'modul.pdf']);
        Storage::disk('local')->put($file->blobPath(), '%PDF-1.4 normalisasi');

        $source = app(NotebookLibrary::class)->addArchivedFile($notebook, $file);

        $this->assertSame(NotebookSource::STATUS_FAILED, $source->status);
        $this->assertStringContainsString('Berkas ini perlu dibaca AI (Google Gemini).', (string) $source->error);
        Http::assertNothingSent();
    }

    private function assertConsentRequired(TestResponse $response): void
    {
        $response->assertForbidden()
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('reason', 'ai_consent_required')
            ->assertJsonPath('message', app(NotebookAiConsents::class)->requiredMessage())
            ->assertJsonPath('data.ai_consent.granted', false);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function useAnthropic(array $settings = []): void
    {
        config([
            'notebook.ai.provider' => 'anthropic',
            'services.anthropic.key' => 'test-anthropic-key',
            'services.anthropic.model' => 'claude-opus-5-5',
            'services.anthropic.fallback_model' => null,
            'services.anthropic.base_url' => 'https://api.anthropic.com',
            'services.anthropic.version' => '2026-10-21',
            'services.anthropic.retry_delay_ms' => 0,
            ...$settings,
        ]);
        Http::fake([
            self::GEMINI_URL => Http::response(['candidates' => [['content' => ['parts' => [['text' => "[[HALAMAN 1]]\nIsi."]]], 'finishReason' => 'STOP']]]),
            'https://api.anthropic.com/v1/messages' => Http::response([
                'id' => 'msg_test',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-opus-5-5',
                'content' => [['type' => 'text', 'text' => 'Normalisasi mengurangi redundansi [1].']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 120, 'output_tokens' => 12],
            ]),
        ]);
    }

    private function notebookWithReadySource(): Notebook
    {
        $notebook = Notebook::factory()->forOwner($this->ownerHash)->create();
        $source = NotebookSource::factory()->create(['notebook_id' => $notebook->id, 'title' => 'Modul']);
        NotebookChunk::query()->create(['notebook_source_id' => $source->id, 'notebook_id' => $notebook->id, 'position' => 0, 'page' => 1, 'content' => 'Normalisasi mengurangi redundansi.']);

        return $notebook;
    }

    private function getAsStudent(string $uri): TestResponse
    {
        return $this->withSession(['campus_session_token' => self::TOKEN])->getJson($uri);
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
