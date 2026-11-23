<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Notebook;
use App\Models\NotebookMessage;
use App\Models\NotebookReviewCard;
use App\Services\PortalDataOwner;
use App\Services\PortalSessionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * "Ulasan harian": cards saved from flashcards and missed quiz questions,
 * the owner's daily queue across notebooks, grading, editing and
 * suspending. No AI is called anywhere.
 */
class NotebookReviewTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'token-notebook-review';

    private const USERNAME = '20990000041';

    private string $ownerHash;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-23 09:00:00');
        Http::fake();
        $this->instance(PortalSessionManager::class, Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('validateSession')->andReturnTrue();
        }));
        app(PortalDataOwner::class)->remember(self::TOKEN, self::USERNAME, 3600);
        $this->ownerHash = app(PortalDataOwner::class)->keyForUsername(self::USERNAME);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Cache::flush();
        Mockery::close();

        parent::tearDown();
    }

    public function test_flashcards_are_saved_to_the_review_with_their_citations_and_meeting(): void
    {
        $notebook = $this->notebook();
        $flashcards = $this->flashcards($notebook);

        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/messages/{$flashcards->id}/review-cards")
            ->assertCreated()
            ->assertJsonPath('message', '2 kartu masuk Ulasan harian.')
            ->assertJsonPath('data.imported', 2)
            ->assertJsonPath('data.skipped', 1)
            ->assertJsonPath('data.counts.due', 2);

        $cards = NotebookReviewCard::query()->orderBy('item_index')->get();
        $this->assertSame([0, 1], $cards->pluck('item_index')->all());
        $this->assertSame('Apa itu 1NF?', $cards[0]->front);
        $this->assertSame('Nilai setiap kolom atomik.', $cards[0]->back);
        $this->assertSame([['n' => 2, 'source_id' => 7, 'source_title' => 'Modul 3', 'page' => 4, 'excerpt' => 'Bentuk normal pertama']], $cards[0]->citations);
        $this->assertSame(3, $cards[0]->meeting_number);
        $this->assertSame([], $cards[1]->citations);
        $this->assertNull($cards[1]->meeting_number);
        $this->assertTrue($cards[0]->due_at->equalTo(now()));
        $this->assertSame(2.5, $cards[0]->ease);
        Http::assertNothingSent();
    }

    public function test_saving_the_same_output_again_adds_no_duplicates(): void
    {
        $notebook = $this->notebook();
        $flashcards = $this->flashcards($notebook);
        $uri = "/api/notebooks/{$notebook->id}/messages/{$flashcards->id}/review-cards";

        $this->sendAsStudent('POST', $uri)->assertCreated();
        $this->sendAsStudent('POST', $uri)
            ->assertOk()
            ->assertJsonPath('message', 'Semua kartu ini sudah ada di Ulasan harian.')
            ->assertJsonPath('data.imported', 0);

        // An edited card is not brought back to its old text either.
        NotebookReviewCard::query()->where('item_index', 0)->update(['front' => 'Diubah']);
        $this->sendAsStudent('POST', $uri, ['only_missed' => false])->assertOk();

        $this->assertSame(2, NotebookReviewCard::query()->count());
        $this->assertSame('Diubah', NotebookReviewCard::query()->where('item_index', 0)->value('front'));
    }

    public function test_only_the_cards_still_to_learn_can_be_saved_from_a_flashcards_practice(): void
    {
        $notebook = $this->notebook();
        $flashcards = $this->flashcards($notebook, practice: ['score' => 1, 'total' => 3, 'missed' => [1], 'flagged' => [], 'at' => now()->toISOString()]);

        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/messages/{$flashcards->id}/review-cards", ['only_missed' => true])
            ->assertCreated()
            ->assertJsonPath('data.imported', 1);

        $this->assertSame([1], NotebookReviewCard::query()->pluck('item_index')->all());
    }

    public function test_the_missed_questions_of_a_quiz_become_cards_with_the_answer_and_explanation(): void
    {
        $notebook = $this->notebook();
        $quiz = $this->quiz($notebook, missed: [1, 2, 9]);

        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/messages/{$quiz->id}/review-cards")
            ->assertCreated()
            ->assertJsonPath('data.imported', 2);

        $cards = NotebookReviewCard::query()->orderBy('item_index')->get();
        $this->assertSame([1, 2], $cards->pluck('item_index')->all());
        $this->assertSame('Kunci yang menentukan baris secara unik disebut?', $cards[0]->front);
        $this->assertSame("Jawaban: Primary key\n\nPrimary key unik dan tidak null.", $cards[0]->back);
        $this->assertSame('Kunci', $cards[0]->topic);
        $this->assertSame(1, $cards[0]->citations[0]['n']);
        $this->assertSame(5, $cards[0]->meeting_number);
        // The quiz's correctly answered question stays out, even when asked for every item.
        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/messages/{$quiz->id}/review-cards", ['only_missed' => false])
            ->assertOk()
            ->assertJsonPath('data.imported', 0);
        $this->assertSame(2, NotebookReviewCard::query()->count());
        Http::assertNothingSent();
    }

    public function test_a_quiz_without_missed_questions_or_another_output_cannot_be_saved(): void
    {
        $notebook = $this->notebook();
        $quiz = $this->quiz($notebook, missed: null);
        $summary = NotebookMessage::factory()->create(['notebook_id' => $notebook->id, 'kind' => 'summary', 'content' => 'Ringkasan.']);

        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/messages/{$quiz->id}/review-cards")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Belum ada soal yang salah di latihan terakhir. Selesaikan latihannya dulu.');
        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/messages/{$summary->id}/review-cards")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Hanya flashcard dan soal kuis yang bisa disimpan ke ulasan.');
        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/messages/999999/review-cards")->assertNotFound();

        $this->assertSame(0, NotebookReviewCard::query()->count());
    }

    public function test_at_most_fifty_cards_are_saved_from_one_output_and_the_notebook_limit_holds(): void
    {
        $notebook = $this->notebook();
        $deck = NotebookMessage::factory()->create([
            'notebook_id' => $notebook->id,
            'kind' => 'flashcards',
            'content' => json_encode(array_map(static fn (int $index): array => ['front' => "Depan {$index}", 'back' => "Belakang {$index}", 'citations' => []], range(0, 59))),
        ]);

        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/messages/{$deck->id}/review-cards")
            ->assertCreated()
            ->assertJsonPath('data.imported', 50);

        config(['notebook.limits.max_review_cards' => 50]);
        $other = $this->flashcards($notebook);
        NotebookReviewCard::query()->limit(10)->update(['suspended' => true]);
        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/messages/{$other->id}/review-cards")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Notebook ini sudah punya 50 kartu ulasan, batas untuk satu notebook. Kartu yang ditahan ikut dihitung; kartu dari notebook lain masih bisa disimpan.');
        $this->assertSame(50, NotebookReviewCard::query()->count());

        $elsewhere = $this->notebook();
        $this->sendAsStudent('POST', "/api/notebooks/{$elsewhere->id}/messages/{$this->flashcards($elsewhere)->id}/review-cards")->assertCreated();
    }

    public function test_the_queue_holds_the_owners_due_cards_in_order_with_at_most_twenty_new_a_day(): void
    {
        $first = $this->notebook();
        $second = $this->notebook();
        $late = NotebookReviewCard::factory()->learned()->create(['notebook_id' => $second->id, 'due_at' => now()->subDays(2)]);
        $early = NotebookReviewCard::factory()->learned()->create(['notebook_id' => $first->id, 'due_at' => now()->subHour()]);
        NotebookReviewCard::factory()->learned()->create(['notebook_id' => $first->id, 'due_at' => now()->addDay()]);
        NotebookReviewCard::factory()->learned()->suspended()->create(['notebook_id' => $first->id, 'due_at' => now()->subDay()]);
        NotebookReviewCard::factory()->count(25)->create(['notebook_id' => $first->id]);
        // 3 new cards were already started today: 17 more are let in.
        NotebookReviewCard::factory()->count(3)->create(['notebook_id' => $second->id, 'first_reviewed_at' => now()->startOfDay()->addHour(), 'reps' => 1, 'due_at' => now()->addDay()]);
        NotebookReviewCard::factory()->count(4)->create(['notebook_id' => Notebook::factory()->forOwner('student_someone_else')->create()->id]);

        $response = $this->getAsStudent('/api/notebooks/review')
            ->assertOk()
            ->assertJsonPath('data.counts.reviews', 2)
            ->assertJsonPath('data.counts.new', 17)
            ->assertJsonPath('data.counts.new_today', 3)
            ->assertJsonPath('data.counts.new_limit', 20)
            ->assertJsonPath('data.counts.due', 19)
            ->assertJsonPath('data.counts.total', 32)
            ->assertJsonPath('data.counts.suspended', 1)
            ->assertJsonPath('data.next_due_at', now()->addDay()->toISOString())
            ->assertJsonCount(19, 'data.cards')
            ->assertJsonPath('data.cards.0.id', $late->id)
            ->assertJsonPath('data.cards.0.notebook_title', $second->title)
            ->assertJsonPath('data.cards.0.is_new', false)
            ->assertJsonPath('data.cards.1.id', $early->id)
            ->assertJsonPath('data.cards.2.is_new', true)
            ->assertJsonPath('data.cards.2.next', ['lagi' => 10, 'sulit' => 1440, 'bisa' => 1440, 'mudah' => 4320]);

        $ids = collect($response->json('data.cards'))->pluck('notebook_id')->unique()->all();
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $ids);

        $this->getAsStudent('/api/notebooks/review?limit=5')->assertOk()->assertJsonCount(5, 'data.cards');
        $this->getAsStudent('/api/notebooks/review?limit=500')->assertUnprocessable();
        $this->getAsStudent('/api/notebooks')->assertOk()->assertJsonPath('data.review_due', 19);
        Http::assertNothingSent();
    }

    public function test_grading_moves_the_card_and_counts_it_among_todays_new_cards(): void
    {
        $card = NotebookReviewCard::factory()->create(['notebook_id' => $this->notebook()->id]);

        $this->sendAsStudent('POST', "/api/notebooks/review/{$card->id}", ['grade' => 'bisa'])
            ->assertOk()
            ->assertJsonPath('data.card.interval_days', 1)
            ->assertJsonPath('data.card.reps', 1)
            ->assertJsonPath('data.card.is_new', false)
            ->assertJsonPath('data.card.due_at', now()->addDay()->toISOString())
            ->assertJsonPath('data.counts.due', 0)
            ->assertJsonPath('data.counts.new_today', 1);

        Carbon::setTestNow(now()->addDay());
        app(PortalDataOwner::class)->remember(self::TOKEN, self::USERNAME, 3600);
        $this->sendAsStudent('POST', "/api/notebooks/review/{$card->id}", ['grade' => 'lagi'])
            ->assertOk()
            ->assertJsonPath('data.card.due_at', now()->addMinutes(10)->toISOString())
            ->assertJsonPath('data.card.lapses', 1)
            ->assertJsonPath('data.card.ease', 2.3);

        $card->refresh();
        $this->assertTrue($card->last_reviewed_at->equalTo(now()));
        $this->assertTrue($card->first_reviewed_at->equalTo(now()->subDay()));
        $this->sendAsStudent('POST', "/api/notebooks/review/{$card->id}", ['grade' => 'hafal'])->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_another_students_card_is_not_found(): void
    {
        $foreign = NotebookReviewCard::factory()->create(['notebook_id' => Notebook::factory()->forOwner('student_someone_else')->create()->id, 'front' => 'Rahasia']);
        $quiz = $this->quiz(Notebook::factory()->forOwner('student_someone_else')->create(), missed: [1]);

        $this->sendAsStudent('POST', "/api/notebooks/review/{$foreign->id}", ['grade' => 'bisa'])->assertNotFound();
        $this->sendAsStudent('PATCH', "/api/notebooks/review/{$foreign->id}", ['front' => 'Diubah'])->assertNotFound();
        $this->sendAsStudent('POST', "/api/notebooks/{$quiz->notebook_id}/messages/{$quiz->id}/review-cards")->assertNotFound();
        $own = $this->notebook();
        $this->sendAsStudent('POST', "/api/notebooks/{$own->id}/messages/{$quiz->id}/review-cards")->assertNotFound();

        $foreign->refresh();
        $this->assertSame('Rahasia', $foreign->front);
        $this->assertSame(0, $foreign->reps);
        $this->assertSame(1, NotebookReviewCard::query()->count());
    }

    public function test_a_card_is_edited_suspended_and_brought_back(): void
    {
        $card = NotebookReviewCard::factory()->create(['notebook_id' => $this->notebook()->id]);
        $uri = "/api/notebooks/review/{$card->id}";

        $this->sendAsStudent('PATCH', $uri, ['front' => '  Apa syarat 2NF?  ', 'back' => 'Sudah 1NF, tanpa ketergantungan parsial.'])
            ->assertOk()
            ->assertJsonPath('message', 'Kartu disimpan.')
            ->assertJsonPath('data.card.front', 'Apa syarat 2NF?');

        $this->sendAsStudent('PATCH', $uri, ['suspended' => true])
            ->assertOk()
            ->assertJsonPath('data.card.suspended', true)
            ->assertJsonPath('data.counts.due', 0);
        $this->getAsStudent('/api/notebooks/review')->assertJsonCount(0, 'data.cards');
        $this->sendAsStudent('POST', $uri, ['grade' => 'bisa'])->assertUnprocessable();

        $this->sendAsStudent('PATCH', $uri, ['suspended' => false])
            ->assertOk()
            ->assertJsonPath('message', 'Kartu kembali ke ulasan.')
            ->assertJsonPath('data.counts.due', 1);

        $this->sendAsStudent('PATCH', $uri, [])->assertUnprocessable();
        $this->sendAsStudent('PATCH', $uri, ['back' => '   '])->assertUnprocessable();
        $this->sendAsStudent('PATCH', $uri, ['front' => str_repeat('a', 2001)])->assertUnprocessable();
        $this->assertSame('Apa syarat 2NF?', $card->refresh()->front);
    }

    public function test_every_held_card_of_the_owner_comes_back_at_once(): void
    {
        $notebook = $this->notebook();
        $held = NotebookReviewCard::factory()->count(2)->suspended()->create(['notebook_id' => $notebook->id, 'due_at' => now()->subDay()]);
        $active = NotebookReviewCard::factory()->create(['notebook_id' => $this->notebook()->id]);
        $foreign = NotebookReviewCard::factory()->suspended()->create(['notebook_id' => Notebook::factory()->forOwner('student_someone_else')->create()->id]);

        $this->getAsStudent('/api/notebooks/review')
            ->assertJsonPath('data.counts.suspended', 2)
            ->assertJsonCount(1, 'data.cards');

        $this->sendAsStudent('POST', '/api/notebooks/review/resume')
            ->assertOk()
            ->assertJsonPath('message', '2 kartu kembali ke ulasan.')
            ->assertJsonPath('data.resumed', 2)
            ->assertJsonPath('data.counts.suspended', 0)
            ->assertJsonPath('data.counts.due', 3);

        $this->assertFalse($held[0]->refresh()->suspended);
        $this->assertFalse($active->refresh()->suspended);
        $this->assertTrue($foreign->refresh()->suspended, "Another student's held card stays held.");

        $this->sendAsStudent('POST', '/api/notebooks/review/resume')
            ->assertOk()
            ->assertJsonPath('message', 'Tidak ada kartu yang ditahan.')
            ->assertJsonPath('data.resumed', 0);
        Http::assertNothingSent();
    }

    public function test_cards_go_with_their_notebook_and_stay_when_their_output_is_deleted(): void
    {
        $notebook = $this->notebook();
        $flashcards = $this->flashcards($notebook);
        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/messages/{$flashcards->id}/review-cards")->assertCreated();

        $this->sendAsStudent('DELETE', "/api/notebooks/{$notebook->id}/messages/{$flashcards->id}")->assertOk();
        $this->assertSame(2, NotebookReviewCard::query()->whereNull('notebook_message_id')->count());

        $this->sendAsStudent('DELETE', "/api/notebooks/{$notebook->id}")->assertOk();
        $this->assertSame(0, NotebookReviewCard::query()->count());
    }

    public function test_emptying_a_course_notebook_removes_its_cards(): void
    {
        $course = Notebook::factory()->forOwner($this->ownerHash)->create(['kind' => Notebook::KIND_COURSE, 'course_key' => 'MK-1']);
        NotebookReviewCard::factory()->count(2)->create(['notebook_id' => $course->id]);

        $this->sendAsStudent('DELETE', "/api/notebooks/{$course->id}")->assertOk()->assertJsonPath('data.emptied', true);

        $this->assertSame(0, NotebookReviewCard::query()->count());
    }

    public function test_review_cards_are_deleted_with_the_account(): void
    {
        $this->instance(PortalSessionManager::class, Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('validateSession')->andReturnTrue();
            $mock->shouldReceive('sessionTokensForOwner')->andReturn([]);
            $mock->shouldReceive('laravelSessionId')->andReturnNull();
            $mock->shouldReceive('destroySession')->andReturnTrue();
            $mock->shouldReceive('getSession')->andReturn(['username' => self::USERNAME]);
        }));
        NotebookReviewCard::factory()->count(2)->create(['notebook_id' => $this->notebook()->id]);
        $kept = NotebookReviewCard::factory()->create(['notebook_id' => Notebook::factory()->forOwner('student_someone_else')->create()->id]);

        $this->sendAsStudent('DELETE', '/api/account', ['confirm' => true])->assertOk();

        $this->assertSame([$kept->id], NotebookReviewCard::query()->pluck('id')->all());
    }

    private function notebook(): Notebook
    {
        return Notebook::factory()->forOwner($this->ownerHash)->create();
    }

    /**
     * Three flashcards, the third without a back (left out).
     *
     * @param  array<string, mixed>|null  $practice
     */
    private function flashcards(Notebook $notebook, ?array $practice = null): NotebookMessage
    {
        return NotebookMessage::factory()->create([
            'notebook_id' => $notebook->id,
            'kind' => 'flashcards',
            'content' => json_encode([
                ['front' => 'Apa itu 1NF?', 'back' => 'Nilai setiap kolom atomik.', 'citations' => [2, 9]],
                ['front' => 'Apa itu 2NF?', 'back' => 'Tanpa ketergantungan parsial.', 'citations' => []],
                ['front' => 'Kosong', 'back' => '', 'citations' => []],
            ]),
            'citations' => [
                ['n' => 1, 'source_id' => 8, 'source_title' => 'Catatan', 'page' => null, 'excerpt' => 'Lain'],
                ['n' => 2, 'source_id' => 7, 'source_title' => 'Modul 3', 'page' => 4, 'excerpt' => 'Bentuk normal pertama'],
            ],
            'context' => [
                'source_ids' => [7, 8],
                'sources' => [['id' => 7, 'title' => 'Modul 3', 'meeting_number' => 3], ['id' => 8, 'title' => 'Catatan', 'meeting_number' => null]],
                'total_sources' => 2,
                'excerpts' => 2,
                'chars' => 100,
                'partial' => false,
                'focus' => null,
                ...($practice !== null ? ['practice' => $practice] : []),
            ],
            'pinned' => true,
        ]);
    }

    /**
     * @param  list<int>|null  $missed
     */
    private function quiz(Notebook $notebook, ?array $missed): NotebookMessage
    {
        return NotebookMessage::factory()->create([
            'notebook_id' => $notebook->id,
            'kind' => 'quiz',
            'content' => json_encode([
                ['question' => 'Apa itu 1NF?', 'options' => ['Atomik', 'Ganda'], 'answer_index' => 0, 'explanation' => 'Nilai atomik.', 'topic' => 'Normalisasi', 'citations' => []],
                ['question' => 'Kunci yang menentukan baris secara unik disebut?', 'options' => ['Foreign key', 'Primary key'], 'answer_index' => 1, 'explanation' => 'Primary key unik dan tidak null.', 'topic' => 'Kunci', 'citations' => [1]],
                ['question' => 'Relasi banyak ke banyak dipecah dengan?', 'options' => ['Tabel penghubung', 'Indeks'], 'answer_index' => 0, 'explanation' => 'Tabel penghubung menyimpan kedua kunci.', 'citations' => []],
            ]),
            'citations' => [['n' => 1, 'source_id' => 4, 'source_title' => 'Pertemuan 5', 'page' => null, 'excerpt' => 'Primary key']],
            'context' => [
                'source_ids' => [4],
                'sources' => [['id' => 4, 'title' => 'Pertemuan 5', 'meeting_number' => 5]],
                'total_sources' => 1,
                'excerpts' => 1,
                'chars' => 50,
                'partial' => false,
                'focus' => null,
                ...($missed !== null ? ['practice' => ['score' => 1, 'total' => 3, 'missed' => $missed, 'flagged' => [], 'choices' => [0, 0, 1], 'at' => now()->toISOString()]] : []),
            ],
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
