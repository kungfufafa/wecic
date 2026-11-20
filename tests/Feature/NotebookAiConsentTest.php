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
            ->assertJsonPath('data.consent.provider', 'Google Gemini');

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

    private function assertConsentRequired(TestResponse $response): void
    {
        $response->assertForbidden()
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('reason', 'ai_consent_required')
            ->assertJsonPath('message', NotebookAiConsents::REQUIRED_MESSAGE)
            ->assertJsonPath('data.ai_consent.granted', false);
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
