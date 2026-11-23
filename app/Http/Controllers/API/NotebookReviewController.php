<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Requests\ImportNotebookReviewCardsRequest;
use App\Http\Requests\NotebookReviewQueueRequest;
use App\Http\Requests\ReviewNotebookCardRequest;
use App\Http\Requests\UpdateNotebookReviewCardRequest;
use App\Models\Notebook;
use App\Models\NotebookReviewCard;
use App\Services\NotebookPractice;
use App\Services\NotebookReviewDeck;
use App\Services\PortalDataOwner;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

/**
 * "Ulasan harian": spaced repetition over cards saved from the student's
 * flashcards and missed quiz questions, across all their notebooks. No AI
 * call, no AI consent and no daily AI budget.
 */
#[Group('Notebook', weight: 18)]
class NotebookReviewController extends CampusApiController
{
    private const CARD_ITEM = 'array{id: int, notebook_id: int, notebook_title: string|null, notebook_message_id: int|null, front: string, back: string, citations: list<array{n: int, source_id: int, source_title: string, page: int|null, position?: int, chunk_id?: int, excerpt: string}>, topic: string|null, meeting_number: int|null, due_at: string, interval_days: int, ease: float, reps: int, lapses: int, last_reviewed_at: string|null, suspended: bool, is_new: bool, next: array{lagi: int, sulit: int, bisa: int, mudah: int}}';

    private const COUNTS_ITEM = 'array{due: int, reviews: int, new: int, new_today: int, new_limit: int, total: int, suspended: int}';

    public function __construct(
        private readonly NotebookReviewDeck $deck,
        private readonly NotebookPractice $practice,
        private readonly PortalDataOwner $owner
    ) {}

    /**
     * Antrean Ulasan harian dari semua notebook mahasiswa: kartu yang jatuh tempo.
     *
     * Kartu yang pernah diulas lebih dulu (jatuh tempo paling lama di depan), lalu kartu baru, paling banyak `new_limit`
     * (bawaan 20) kartu baru per hari untuk semua notebook. `counts.due` adalah jumlah kartu antrean hari ini (untuk lencana),
     * `next_due_at` kapan kartu berikutnya yang belum jatuh tempo muncul. `next` di tiap kartu menyebut dalam berapa menit
     * kartu itu muncul lagi untuk tiap pilihan. Tidak memanggil AI.
     */
    #[Response(description: 'Antrean ulasan.', type: "array{status: 'success', message: string, data: array{cards: list<".self::CARD_ITEM.'>, counts: '.self::COUNTS_ITEM.', next_due_at: string|null}, meta: array{timestamp: string, campus_session_valid: true}}')]
    #[Response(401, description: 'Endpoint ini membutuhkan sesi kampus yang aktif.', type: CampusApiController::SCRAMBLE_SESSION_ERROR_RESPONSE)]
    #[Response(422, description: 'Parameter tidak valid.', type: CampusApiController::SCRAMBLE_VALIDATION_ERROR_RESPONSE)]
    public function index(NotebookReviewQueueRequest $request): JsonResponse
    {
        return $this->withCampusSession('Notebook review queue failed', 'Gagal memuat ulasan', function (string $sessionToken) use ($request): JsonResponse {
            $now = now();
            $queue = $this->deck->queue($this->owner->key($sessionToken), $request->limit(), $now);

            return $this->statusSuccess('Review queue retrieved', [
                'data' => [
                    'cards' => $queue['cards']->map(static fn (NotebookReviewCard $card): array => $card->toApiArray($now))->values()->all(),
                    'counts' => $queue['counts'],
                    'next_due_at' => $queue['next_due_at'],
                ],
            ], campusSessionValid: true);
        });
    }

    /**
     * Nilai satu kartu: `lagi` (muncul lagi 10 menit lagi), `sulit`, `bisa`, atau `mudah`.
     *
     * Jadwal berikutnya dihitung server (varian SM-2): `due_at`, `interval_days`, `ease`, `reps`, dan `lapses` kartu berubah.
     * Kartu yang ditahan (`suspended`) ditolak dengan 422. `counts` adalah sisa antrean sesudahnya.
     */
    #[PathParameter('card', 'Id kartu ulasan.', type: 'integer', example: 12)]
    #[Response(description: 'Kartu dinilai.', type: "array{status: 'success', message: string, data: array{card: ".self::CARD_ITEM.', counts: '.self::COUNTS_ITEM.'}, meta: array{timestamp: string, campus_session_valid: true}}')]
    #[Response(404, description: 'Kartu tidak ditemukan.', type: CampusApiController::SCRAMBLE_NOT_FOUND_RESPONSE)]
    #[Response(422, description: 'Pilihan tidak valid atau kartu sedang ditahan.', type: CampusApiController::SCRAMBLE_VALIDATION_ERROR_RESPONSE)]
    public function review(ReviewNotebookCardRequest $request, int $card): JsonResponse
    {
        return $this->withCard($card, 'Notebook review grade failed', 'Gagal menyimpan ulasan', function (NotebookReviewCard $owned, string $ownerHash) use ($request): JsonResponse {
            if ($owned->suspended) {
                return $this->statusError('Kartu ini sedang ditahan. Aktifkan lagi dulu untuk mengulasnya.', 422, campusSessionValid: true);
            }

            $now = now();
            $this->deck->grade($owned, $request->grade(), $now);

            return $this->statusSuccess('Ulasan disimpan.', [
                'data' => ['card' => $owned->toApiArray($now), 'counts' => $this->deck->counts($ownerHash, $now)],
            ], campusSessionValid: true);
        });
    }

    /**
     * Ubah isi kartu (`front`, `back`), atau tahan (`suspended: true`) dan aktifkan lagi kartu.
     *
     * Kartu yang ditahan tidak muncul di antrean; jadwalnya tetap tersimpan.
     */
    #[PathParameter('card', 'Id kartu ulasan.', type: 'integer', example: 12)]
    #[Response(description: 'Kartu diperbarui.', type: "array{status: 'success', message: string, data: array{card: ".self::CARD_ITEM.', counts: '.self::COUNTS_ITEM.'}, meta: array{timestamp: string, campus_session_valid: true}}')]
    #[Response(404, description: 'Kartu tidak ditemukan.', type: CampusApiController::SCRAMBLE_NOT_FOUND_RESPONSE)]
    #[Response(422, description: 'Isi kartu kosong atau terlalu panjang.', type: CampusApiController::SCRAMBLE_VALIDATION_ERROR_RESPONSE)]
    public function update(UpdateNotebookReviewCardRequest $request, int $card): JsonResponse
    {
        return $this->withCard($card, 'Notebook review card update failed', 'Gagal memperbarui kartu', function (NotebookReviewCard $owned, string $ownerHash) use ($request): JsonResponse {
            $changes = $request->changes();

            if (($changes['front'] ?? null) === '' || ($changes['back'] ?? null) === '') {
                return $this->statusError('Sisi kartu tidak boleh kosong.', 422, campusSessionValid: true);
            }

            $owned->update($changes);
            $now = now();

            return $this->statusSuccess(match (true) {
                isset($changes['front']) || isset($changes['back']) => 'Kartu disimpan.',
                $owned->suspended => 'Kartu ditahan dan tidak muncul di ulasan.',
                default => 'Kartu kembali ke ulasan.',
            }, [
                'data' => ['card' => $owned->toApiArray($now), 'counts' => $this->deck->counts($ownerHash, $now)],
            ], campusSessionValid: true);
        });
    }

    /**
     * Aktifkan lagi semua kartu yang ditahan, di semua notebook mahasiswa.
     *
     * Jadwal kartu tetap seperti saat ditahan, jadi kartu yang sudah jatuh tempo langsung masuk antrean. `resumed` adalah
     * jumlah kartu yang diaktifkan lagi (0 bila tidak ada yang ditahan). Tidak memanggil AI.
     */
    #[Response(description: 'Kartu aktif lagi.', type: "array{status: 'success', message: string, data: array{resumed: int, counts: ".self::COUNTS_ITEM.'}, meta: array{timestamp: string, campus_session_valid: true}}')]
    #[Response(401, description: 'Endpoint ini membutuhkan sesi kampus yang aktif.', type: CampusApiController::SCRAMBLE_SESSION_ERROR_RESPONSE)]
    public function resume(): JsonResponse
    {
        return $this->withCampusSession('Notebook review resume failed', 'Gagal mengaktifkan kartu', function (string $sessionToken): JsonResponse {
            $ownerHash = $this->owner->key($sessionToken);
            $resumed = NotebookReviewCard::query()->forOwner($ownerHash)->where('suspended', true)->update(['suspended' => false]);

            return $this->statusSuccess($resumed > 0 ? "{$resumed} kartu kembali ke ulasan." : 'Tidak ada kartu yang ditahan.', [
                'data' => ['resumed' => $resumed, 'counts' => $this->deck->counts($ownerHash, now())],
            ], campusSessionValid: true);
        });
    }

    /**
     * Simpan ke ulasan: kartu dari flashcard, atau soal yang salah di latihan terakhir sebuah kuis.
     *
     * Flashcard menyimpan semua kartunya (paling banyak 50), atau hanya yang belum hafal dengan `only_missed: true`.
     * Kuis menyimpan soal yang salah di `context.practice.missed`: sisi depan berisi soal, sisi belakang jawaban benar dan
     * penjelasannya. Rujukan butir itu ikut tersimpan. Butir yang sudah pernah disimpan dari hasil yang sama dilewati
     * (`skipped`), jadi menyimpan ulang tidak menggandakan kartu. Kartu baru langsung masuk antrean. Tidak memanggil AI.
     */
    #[PathParameter('notebook', 'Id notebook.', type: 'integer', example: 3)]
    #[PathParameter('message', 'Id hasil studio berjenis kuis atau flashcard.', type: 'integer', example: 40)]
    #[Response(201, description: 'Kartu disimpan.', type: "array{status: 'success', message: string, data: array{imported: int, skipped: int, counts: ".self::COUNTS_ITEM.'}, meta: array{timestamp: string, campus_session_valid: true}}')]
    #[Response(200, description: 'Semua butir sudah ada di ulasan.', type: "array{status: 'success', message: string, data: array{imported: int, skipped: int, counts: ".self::COUNTS_ITEM.'}, meta: array{timestamp: string, campus_session_valid: true}}')]
    #[Response(404, description: 'Notebook atau pesan tidak ditemukan.', type: CampusApiController::SCRAMBLE_NOT_FOUND_RESPONSE)]
    #[Response(422, description: 'Bukan kuis atau flashcard, belum ada soal yang salah, atau batas kartu notebook tercapai.', type: CampusApiController::SCRAMBLE_STATUS_ERROR_RESPONSE)]
    public function import(ImportNotebookReviewCardsRequest $request, int $notebook, int $message): JsonResponse
    {
        return $this->withCampusSession('Notebook review import failed', 'Gagal menyimpan ke ulasan', function (string $sessionToken) use ($request, $notebook, $message): JsonResponse {
            $ownerHash = $this->owner->key($sessionToken);
            $owned = Notebook::query()->forOwner($ownerHash)->whereKey($notebook)->first();
            $output = $owned?->messages()->whereKey($message)->first();

            if ($owned === null || $output === null) {
                return $this->statusError($owned === null ? 'Notebook tidak ditemukan.' : 'Pesan tidak ditemukan.', 404, campusSessionValid: true);
            }

            if (! $this->practice->isPracticeable($output)) {
                return $this->statusError('Hanya flashcard dan soal kuis yang bisa disimpan ke ulasan.', 422, campusSessionValid: true);
            }

            $indexes = $this->deck->importableIndexes($output, $request->onlyMissed());

            if ($indexes === null || $indexes === []) {
                return $this->statusError($indexes === null
                    ? 'Hasil ini tidak berisi kartu.'
                    : 'Belum ada soal yang salah di latihan terakhir. Selesaikan latihannya dulu.', 422, campusSessionValid: true);
            }

            $now = now();
            $result = $this->deck->import($owned, $output, $indexes, $now);

            if ($result['imported'] === 0 && $result['full']) {
                return $this->statusError("Notebook ini sudah punya {$this->deck->cardLimit()} kartu ulasan, batas untuk satu notebook. Kartu yang ditahan ikut dihitung; kartu dari notebook lain masih bisa disimpan.", 422, campusSessionValid: true);
            }

            $message = match (true) {
                $result['imported'] === 0 => 'Semua kartu ini sudah ada di Ulasan harian.',
                $result['full'] => "{$result['imported']} kartu masuk Ulasan harian; sisanya melewati batas kartu notebook ini.",
                default => "{$result['imported']} kartu masuk Ulasan harian.",
            };

            return $this->statusSuccess($message, [
                'data' => [
                    'imported' => $result['imported'],
                    'skipped' => $result['skipped'],
                    'counts' => $this->deck->counts($ownerHash, $now),
                ],
            ], campusSessionValid: true, statusCode: $result['imported'] > 0 ? 201 : 200);
        }, ['notebook_id' => $notebook, 'message_id' => $message]);
    }

    /**
     * @param  callable(NotebookReviewCard, string): JsonResponse  $callback
     */
    private function withCard(int $cardId, string $failureLogMessage, string $clientErrorPrefix, callable $callback): JsonResponse
    {
        return $this->withCampusSession($failureLogMessage, $clientErrorPrefix, function (string $sessionToken) use ($cardId, $callback): JsonResponse {
            $ownerHash = $this->owner->key($sessionToken);
            $card = NotebookReviewCard::query()->forOwner($ownerHash)->with('notebook:id,title')->whereKey($cardId)->first();

            return $card === null
                ? $this->statusError('Kartu tidak ditemukan.', 404, campusSessionValid: true)
                : $callback($card, $ownerHash);
        }, ['card_id' => $cardId]);
    }
}
