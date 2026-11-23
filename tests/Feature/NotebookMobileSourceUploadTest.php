<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ExpirePortalSessionJob;
use App\Jobs\RefreshPortalDataJob;
use App\Jobs\RefreshPortalSessionJob;
use App\Jobs\ResumeOfflineSessionJob;
use App\Jobs\ScanPortalNotificationsJob;
use App\Models\Notebook;
use App\Models\NotebookAiConsent;
use App\Models\NotebookChunk;
use App\Models\NotebookSource;
use App\Services\Ai\NotebookAiProviders;
use App\Services\PortalDataOwner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;
use Tests\Traits\LogsInWithMobileToken;

/**
 * A source added from the mobile app's "+ Sumber" sheet: multipart form
 * data (`type=upload` and `file`, no `source_ids`) on `/api/v1` with the
 * app's bearer token, as mobile/src/lib/notebook-sources.ts builds it.
 */
class NotebookMobileSourceUploadTest extends TestCase
{
    use LogsInWithMobileToken, RefreshDatabase;

    private const USERNAME = '20990000501';

    private const UNSUPPORTED = 'Model AI yang dipakai server tidak bisa membaca PDF hasil pindaian atau gambar; tempel teksnya atau unggah DOCX/PPTX.';

    private string $token;

    private Notebook $notebook;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config([
            'notebook.ai.provider' => 'gemini',
            'services.gemini.key' => 'test-gemini-key',
            'services.gemini.model' => 'gemini-test-flash',
            'services.gemini.fallback_model' => null,
            'services.gemini.retry_delay_ms' => 0,
            'notebook.thinking_budget' => null,
        ]);
        $this->portalAcceptsLogin(self::USERNAME);
        Bus::fake([RefreshPortalSessionJob::class, ExpirePortalSessionJob::class, ResumeOfflineSessionJob::class, ScanPortalNotificationsJob::class, RefreshPortalDataJob::class]);
        $this->token = $this->loginForToken(self::USERNAME);
        $ownerHash = app(PortalDataOwner::class)->keyForUsername(self::USERNAME);
        NotebookAiConsent::factory()->forOwner($ownerHash)->create(['provider' => app(NotebookAiProviders::class)->consentKey()]);
        $this->notebook = Notebook::factory()->forOwner($ownerHash)->create();
    }

    protected function tearDown(): void
    {
        Cache::flush();
        Mockery::close();

        parent::tearDown();
    }

    public function test_a_photo_sent_as_the_app_sends_it_becomes_a_source(): void
    {
        Http::fake(['https://generativelanguage.googleapis.com/v1beta/models/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => "[[HALAMAN 1]]\nPapan tulis: normalisasi 3NF."]]], 'finishReason' => 'STOP']],
        ])]);

        $this->uploadFromApp(UploadedFile::fake()->image('foto-1732300000000.jpg', 640, 480))
            ->assertCreated()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.sources.0.kind', 'upload')
            ->assertJsonPath('data.sources.0.status', 'ready');

        $source = NotebookSource::query()->sole();
        $this->assertSame($this->notebook->id, $source->notebook_id);
        $this->assertSame('Papan tulis: normalisasi 3NF.', NotebookChunk::query()->sole()->content);
        Http::assertSent(fn (Request $request): bool => ($request['contents'][0]['parts'][0]['inlineData']['mimeType'] ?? null) === 'image/jpeg');
    }

    public function test_a_document_sent_as_the_app_sends_it_is_read_without_the_model(): void
    {
        Http::fake();

        $this->uploadFromApp(UploadedFile::fake()->createWithContent('catatan.txt', 'Catatan kuliah basis data.'))
            ->assertCreated()
            ->assertJsonPath('data.sources.0.status', 'ready');

        $this->assertSame('Catatan kuliah basis data.', NotebookChunk::query()->sole()->content);
        Http::assertNotSent(fn (Request $request): bool => isset($request['contents'][0]['parts'][0]['inlineData']));
    }

    public function test_a_photo_the_configured_model_cannot_read_is_refused_with_json_the_app_shows_as_is(): void
    {
        config([
            'notebook.ai.provider' => 'openai',
            'services.openai_compatible.base_url' => 'http://llm.test/v1',
            'services.openai_compatible.model' => 'llama-test',
            'services.openai_compatible.image_input' => false,
            'services.openai_compatible.pdf_input' => false,
        ]);
        NotebookAiConsent::query()->update(['provider' => app(NotebookAiProviders::class)->consentKey()]);
        Http::fake();

        $this->uploadFromApp(UploadedFile::fake()->image('foto-1732300000000.jpg'))
            ->assertStatus(422)
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonPath('message', self::UNSUPPORTED)
            ->assertJsonPath('errors.file.0', self::UNSUPPORTED);

        $this->assertSame(0, NotebookSource::query()->count());
        Http::assertNothingSent();
    }

    public function test_an_upload_without_a_file_is_refused_with_its_message(): void
    {
        $this->withToken($this->token)
            ->post("/api/v1/notebooks/{$this->notebook->id}/sources", ['type' => 'upload'], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Pilih berkas yang ingin diunggah.');
    }

    /**
     * Multipart, not JSON: the fields the app's buildUploadForm() appends.
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function uploadFromApp(UploadedFile $file): TestResponse
    {
        return $this->withToken($this->token)
            ->post("/api/v1/notebooks/{$this->notebook->id}/sources", ['type' => 'upload', 'file' => $file], ['Accept' => 'application/json']);
    }
}
