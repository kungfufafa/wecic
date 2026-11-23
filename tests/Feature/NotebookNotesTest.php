<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Notebook;
use App\Models\NotebookAiConsent;
use App\Models\NotebookChunk;
use App\Models\NotebookMessage;
use App\Models\NotebookSource;
use App\Services\PortalDataOwner;
use App\Services\PortalSessionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * The student's own notes (`kind: note`), "Jadikan sumber", and the AI
 * context choice kept on the server (`context_source_ids`).
 */
class NotebookNotesTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'token-notebook-notes';

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

    public function test_a_note_is_created_edited_and_deleted_without_consent_or_any_ai_call(): void
    {
        Http::fake();
        $notebook = $this->notebook();

        $created = $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/notes", ['content' => "## Normalisasi\n- 1NF: nilai atomik"])
            ->assertCreated()
            ->assertJsonPath('data.message.kind', 'note')
            ->assertJsonPath('data.message.role', 'user')
            ->assertJsonPath('data.message.pinned', true)
            ->assertJsonPath('data.message.content', "## Normalisasi\n- 1NF: nilai atomik");
        $id = $created->json('data.message.id');

        $this->sendAsStudent('PATCH', "/api/notebooks/{$notebook->id}/messages/{$id}", ['content' => 'Isi baru'])
            ->assertOk()
            ->assertJsonPath('data.message.content', 'Isi baru')
            ->assertJsonPath('data.message.pinned', true);

        $this->getAsStudent("/api/notebooks/{$notebook->id}")
            ->assertOk()
            ->assertJsonPath('data.messages.0.kind', 'note')
            ->assertJsonPath('data.messages.0.content', 'Isi baru');

        $this->sendAsStudent('DELETE', "/api/notebooks/{$notebook->id}/messages/{$id}")->assertOk();

        $this->assertSame(0, NotebookMessage::query()->count());
        $this->assertSame(0, NotebookAiConsent::query()->count());
        Http::assertNothingSent();
    }

    public function test_an_empty_note_can_be_started_and_cleared(): void
    {
        $notebook = $this->notebook();

        $id = $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/notes", [])
            ->assertCreated()
            ->assertJsonPath('data.message.content', '')
            ->json('data.message.id');

        $this->sendAsStudent('PATCH', "/api/notebooks/{$notebook->id}/messages/{$id}", ['content' => 'Draf'])->assertOk();
        $this->sendAsStudent('PATCH', "/api/notebooks/{$notebook->id}/messages/{$id}", ['content' => ''])
            ->assertOk()
            ->assertJsonPath('data.message.content', '');
    }

    public function test_a_note_longer_than_ten_thousand_characters_is_refused_in_indonesian(): void
    {
        $notebook = $this->notebook();

        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/notes", ['content' => str_repeat('a', 10001)])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Catatan maksimal 10.000 karakter.');

        $note = $this->note($notebook, 'Pendek');

        $this->sendAsStudent('PATCH', "/api/notebooks/{$notebook->id}/messages/{$note->id}", ['content' => str_repeat('a', 10001)])
            ->assertUnprocessable();
        $this->assertSame('Pendek', $note->refresh()->content);
    }

    public function test_the_content_of_a_chat_or_studio_message_cannot_be_edited(): void
    {
        $notebook = $this->notebook();
        $answer = NotebookMessage::factory()->create(['notebook_id' => $notebook->id, 'pinned' => true]);
        $summary = NotebookMessage::factory()->create(['notebook_id' => $notebook->id, 'kind' => 'summary', 'pinned' => true, 'content' => 'Ringkasan [1].']);

        foreach ([$answer, $summary] as $message) {
            $this->sendAsStudent('PATCH', "/api/notebooks/{$notebook->id}/messages/{$message->id}", ['content' => 'Diubah'])
                ->assertUnprocessable()
                ->assertJsonPath('message', 'Hanya catatan sendiri yang bisa diubah isinya.');
        }

        $this->assertSame('Normalisasi mengurangi redundansi data [1].', $answer->refresh()->content);
        $this->assertSame('Ringkasan [1].', $summary->refresh()->content);

        $this->sendAsStudent('PATCH', "/api/notebooks/{$notebook->id}/messages/{$answer->id}", ['pinned' => false])
            ->assertOk()
            ->assertJsonPath('data.message.pinned', false);
    }

    public function test_an_answer_is_copied_to_a_note_with_its_citations(): void
    {
        Http::fake();
        $notebook = $this->notebook();
        $citations = [['n' => 1, 'source_id' => 4, 'source_title' => 'Modul', 'page' => 2, 'position' => 0, 'excerpt' => 'Normalisasi.']];
        $answer = NotebookMessage::factory()->create(['notebook_id' => $notebook->id, 'citations' => $citations]);

        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/notes", ['from_message_id' => $answer->id])
            ->assertCreated()
            ->assertJsonPath('message', 'Disalin ke catatan.')
            ->assertJsonPath('data.message.kind', 'note')
            ->assertJsonPath('data.message.content', 'Normalisasi mengurangi redundansi data [1].')
            ->assertJsonPath('data.message.citations.0.source_title', 'Modul')
            ->assertJsonPath('data.message.context.from_message_id', $answer->id);

        $this->assertFalse($answer->refresh()->pinned);
        Http::assertNothingSent();
    }

    public function test_only_text_answers_of_this_notebook_can_be_copied(): void
    {
        $notebook = $this->notebook();
        $question = NotebookMessage::factory()->create(['notebook_id' => $notebook->id, 'role' => 'user', 'content' => 'Apa itu 1NF?']);
        $quiz = NotebookMessage::factory()->create(['notebook_id' => $notebook->id, 'kind' => 'quiz', 'content' => '[]']);
        $foreign = NotebookMessage::factory()->create(['notebook_id' => Notebook::factory()->forOwner($this->ownerHash)->create()->id]);

        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/notes", ['from_message_id' => $question->id])->assertUnprocessable();
        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/notes", ['from_message_id' => $quiz->id])->assertUnprocessable();
        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/notes", ['from_message_id' => $foreign->id])->assertNotFound();

        $this->assertSame(0, NotebookMessage::query()->where('kind', NotebookMessage::KIND_NOTE)->count());
    }

    public function test_a_note_becomes_a_ready_text_source_with_chunks_without_consent(): void
    {
        config(['notebook.limits.chunk_chars' => 400]);
        Http::fake();
        $notebook = $this->notebook();
        $body = implode("\n\n", array_map(static fn (int $index): string => "Paragraf {$index}: ".str_repeat('bentuk normal ketiga ', 10), range(1, 4)));
        $note = $this->note($notebook, "# **Normalisasi** basis data\n\n".$body);

        $response = $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/notes/{$note->id}/source")
            ->assertCreated()
            ->assertJsonPath('data.source.kind', 'text')
            ->assertJsonPath('data.source.status', 'ready')
            ->assertJsonPath('data.source.title', 'Normalisasi basis data')
            ->assertJsonPath('data.source.summary', null);

        $source = NotebookSource::query()->sole();
        $this->assertSame($response->json('data.source.id'), $source->id);
        $this->assertGreaterThan(1, NotebookChunk::query()->where('notebook_source_id', $source->id)->count());
        $this->assertSame($source->id, $note->refresh()->context['note_source']['id']);
        $response->assertJsonPath('data.message.context.note_source.id', $source->id);
        // Without consent nothing is sent to the AI provider, not even for the summary.
        Http::assertNothingSent();

        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/notes/{$note->id}/source")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Catatan ini sudah menjadi sumber. Hapus sumber lamanya dulu bila ingin memakai isi terbarunya.');
    }

    public function test_a_note_made_a_source_is_summarised_only_with_consent_and_a_read_left(): void
    {
        NotebookAiConsent::factory()->forOwner($this->ownerHash)->create();
        config(['notebook.limits.daily_source_reads' => 1]);
        Http::fake([self::GEMINI_URL => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => '{"summary":"Ringkasan catatan.","topics":["Normalisasi"],"questions":["Apa itu 1NF?"]}']]], 'finishReason' => 'STOP']],
        ])]);
        $notebook = $this->notebook();
        $first = $this->note($notebook, 'Catatan pertama tentang 1NF.');
        $second = $this->note($notebook, 'Catatan kedua tentang 2NF.');

        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/notes/{$first->id}/source")
            ->assertCreated()
            ->assertJsonPath('data.source.summary', 'Ringkasan catatan.');
        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/notes/{$second->id}/source")
            ->assertCreated()
            ->assertJsonPath('data.source.status', 'ready')
            ->assertJsonPath('data.source.summary', null);

        Http::assertSentCount(1);
        $this->assertSame(1, RateLimiter::attempts('notebook-reads:'.$this->ownerHash.':'.now()->format('Y-m-d')));
    }

    public function test_a_note_is_not_made_a_source_when_the_notebook_is_full_empty_or_not_a_note(): void
    {
        config(['notebook.limits.max_sources' => 1]);
        $notebook = $this->notebook();
        NotebookSource::factory()->create(['notebook_id' => $notebook->id]);
        $note = $this->note($notebook, 'Isi catatan.');

        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/notes/{$note->id}/source")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Notebook ini sudah mencapai batas jumlah sumber.');

        config(['notebook.limits.max_sources' => 5]);
        $empty = $this->note($notebook, '  ');
        $answer = NotebookMessage::factory()->create(['notebook_id' => $notebook->id]);

        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/notes/{$empty->id}/source")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Catatan ini masih kosong.');
        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/notes/{$answer->id}/source")->assertNotFound();

        $this->assertSame(1, NotebookSource::query()->count());
    }

    public function test_notes_of_another_students_notebook_are_not_found(): void
    {
        $foreign = Notebook::factory()->forOwner('student_someone_else')->create();
        $note = $this->note($foreign, 'Rahasia.');

        $this->sendAsStudent('POST', "/api/notebooks/{$foreign->id}/notes", ['content' => 'Halo'])->assertNotFound();
        $this->sendAsStudent('PATCH', "/api/notebooks/{$foreign->id}/messages/{$note->id}", ['content' => 'Diubah'])->assertNotFound();
        $this->sendAsStudent('POST', "/api/notebooks/{$foreign->id}/notes/{$note->id}/source")->assertNotFound();
        $this->sendAsStudent('PATCH', "/api/notebooks/{$foreign->id}", ['context_source_ids' => []])->assertNotFound();

        $own = $this->notebook();
        $this->sendAsStudent('POST', "/api/notebooks/{$own->id}/notes/{$note->id}/source")->assertNotFound();

        $this->assertSame('Rahasia.', $note->refresh()->content);
        $this->assertSame(1, NotebookMessage::query()->count());
        $this->assertSame(0, NotebookSource::query()->count());
    }

    public function test_notes_never_reach_the_model_as_conversation_history(): void
    {
        NotebookAiConsent::factory()->forOwner($this->ownerHash)->create();
        $notebook = $this->notebook();
        $source = NotebookSource::factory()->create(['notebook_id' => $notebook->id, 'title' => 'Modul']);
        NotebookChunk::query()->create(['notebook_source_id' => $source->id, 'notebook_id' => $notebook->id, 'position' => 0, 'page' => 1, 'content' => 'Kunci primer unik.']);
        NotebookMessage::factory()->create(['notebook_id' => $notebook->id, 'role' => 'user', 'content' => 'Apa itu kunci primer?']);
        NotebookMessage::factory()->create(['notebook_id' => $notebook->id, 'role' => 'assistant', 'content' => 'Kunci primer unik [1].']);
        $this->note($notebook, 'CATATAN-PRIBADI tentang kunci primer.');
        Http::fake([self::GEMINI_URL => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => 'Ya [1].']]], 'finishReason' => 'STOP']],
        ])]);

        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/messages", ['question' => 'Boleh kosong?'])->assertCreated();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => count($request['contents']) === 3
            && ! str_contains($request->body(), 'CATATAN-PRIBADI'));
    }

    public function test_the_ticked_sources_are_kept_on_the_server_and_follow_added_and_deleted_sources(): void
    {
        $notebook = $this->notebook();
        $first = NotebookSource::factory()->create(['notebook_id' => $notebook->id]);
        $second = NotebookSource::factory()->create(['notebook_id' => $notebook->id]);
        $third = NotebookSource::factory()->create(['notebook_id' => $notebook->id]);

        $this->getAsStudent("/api/notebooks/{$notebook->id}")->assertOk()->assertJsonPath('data.context_source_ids', null);

        $this->sendAsStudent('PATCH', "/api/notebooks/{$notebook->id}", ['context_source_ids' => [$first->id, $second->id]])
            ->assertOk()
            ->assertJsonPath('message', 'Pilihan sumber disimpan.')
            ->assertJsonPath('data.context_source_ids', [$first->id, $second->id]);
        $this->assertSame($notebook->title, $notebook->refresh()->title);

        // A deleted source leaves the choice; a new one joins it ticked.
        $this->sendAsStudent('DELETE', "/api/notebooks/{$notebook->id}/sources/{$second->id}")->assertOk();
        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/sources", ['type' => 'text', 'title' => 'Baru', 'content' => 'Teks baru.'])->assertCreated();
        $added = NotebookSource::query()->where('title', 'Baru')->sole();

        $this->getAsStudent("/api/notebooks/{$notebook->id}")
            ->assertOk()
            ->assertJsonPath('data.context_source_ids', [$first->id, $added->id]);
        $this->assertNotContains($third->id, $notebook->refresh()->contextSourceIds());

        // Kosongkan keeps an empty choice; Semua goes back to every source.
        $this->sendAsStudent('PATCH', "/api/notebooks/{$notebook->id}", ['context_source_ids' => []])->assertOk()->assertJsonPath('data.context_source_ids', []);
        $this->sendAsStudent('PATCH', "/api/notebooks/{$notebook->id}", ['context_source_ids' => null])->assertOk()->assertJsonPath('data.context_source_ids', null);
        $this->assertNull($notebook->refresh()->context_source_ids);
    }

    public function test_sources_of_another_notebook_or_invalid_ids_cannot_be_ticked(): void
    {
        $notebook = $this->notebook();
        $own = NotebookSource::factory()->create(['notebook_id' => $notebook->id]);
        $foreign = NotebookSource::factory()->create(['notebook_id' => Notebook::factory()->forOwner('student_someone_else')->create()->id]);

        $this->sendAsStudent('PATCH', "/api/notebooks/{$notebook->id}", ['context_source_ids' => [$own->id, $foreign->id]])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Sumber yang dipilih tidak ada di notebook ini.');
        $this->sendAsStudent('PATCH', "/api/notebooks/{$notebook->id}", ['context_source_ids' => ['satu']])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Id sumber harus berupa angka.');
        $this->sendAsStudent('PATCH', "/api/notebooks/{$notebook->id}", ['context_source_ids' => [$own->id, $own->id]])->assertUnprocessable();
        $this->sendAsStudent('PATCH', "/api/notebooks/{$notebook->id}", [])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Beri judul notebook.');

        $this->assertNull($notebook->refresh()->context_source_ids);

        $this->sendAsStudent('PATCH', "/api/notebooks/{$notebook->id}", ['title' => 'Judul baru'])
            ->assertOk()
            ->assertJsonPath('data.notebook.title', 'Judul baru')
            ->assertJsonPath('data.context_source_ids', null);
    }

    public function test_notes_are_deleted_with_the_account(): void
    {
        $this->instance(PortalSessionManager::class, Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('validateSession')->andReturnTrue();
            $mock->shouldReceive('sessionTokensForOwner')->andReturn([]);
            $mock->shouldReceive('laravelSessionId')->andReturnNull();
            $mock->shouldReceive('destroySession')->andReturnTrue();
            $mock->shouldReceive('getSession')->andReturn(['username' => self::USERNAME]);
        }));
        $notebook = $this->notebook();
        $this->note($notebook, 'Catatan pribadi.');
        $kept = $this->note(Notebook::factory()->forOwner('student_someone_else')->create(), 'Catatan orang lain.');

        $this->sendAsStudent('DELETE', '/api/account', ['confirm' => true])
            ->assertOk()
            ->assertJsonPath('data.notebooks', 1);

        $this->assertSame(0, Notebook::query()->forOwner($this->ownerHash)->count());
        $this->assertSame([$kept->id], NotebookMessage::query()->where('kind', NotebookMessage::KIND_NOTE)->pluck('id')->all());
    }

    private function notebook(): Notebook
    {
        return Notebook::factory()->forOwner($this->ownerHash)->create();
    }

    private function note(Notebook $notebook, string $content): NotebookMessage
    {
        return NotebookMessage::factory()->create([
            'notebook_id' => $notebook->id,
            'role' => NotebookMessage::ROLE_USER,
            'kind' => NotebookMessage::KIND_NOTE,
            'content' => $content,
            'pinned' => true,
        ]);
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
