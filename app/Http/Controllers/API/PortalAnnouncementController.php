<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Requests\PortalAnnouncementRequest;
use App\Services\PortalFinanceStatusResolver;
use App\Services\PortalSnapshotStore;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Pengumuman', 'Feed pengumuman kampus: kategori, tenggat yang bisa dihitung mundur, tautan, dan detail per pengumuman.', 26)]
class PortalAnnouncementController extends CampusApiController
{
    private const TIMEZONE = 'Asia/Jakarta';

    private const NOTICE = 'array{text: string, dates: list<string>, is_future: bool, kind: string, stage_paid: bool|null}';

    private const ANNOUNCEMENT = "array{id: string, title: string, published_at: string|null, published_date: string|null, published_label: string|null, publisher: string|null, publisher_unit: string|null, publisher_unit_short: string|null, category: 'krs'|'pembayaran'|'semester_pendek'|'surat'|'akademik'|'umum', category_label: string, excerpt: string, body_text: string, body_html: string, links: list<array{label: string, url: string, is_file: bool, is_campus_file: bool, kind: 'form'|'file'|'link'}>, emails: list<string>, deadlines: list<array{date: string, start: string|null, type: 'deadline'|'period'|'date', context: string, is_future: bool}>, steps: list<array{number: int, text: string}>, page?: int, duplicate_of?: string|null}";

    private const UPCOMING_DEADLINE = 'array{announcement_id: string, title: string, category: string, category_label: string, date: string, start: string|null, type: string, context: string, days_left: int, is_ongoing: bool}';

    public function __construct(
        private readonly PortalSnapshotStore $snapshots,
        private readonly PortalFinanceStatusResolver $financeStatus
    ) {}

    /**
     * Feed pengumuman kampus.
     *
     * Tanpa `page`: feed gabungan beberapa halaman pertama (urut terbaru, duplikat ditandai `duplicate_of`)
     * beserta `upcoming_deadlines` yang dihitung ulang terhadap hari ini. Dengan `page`: tepat satu
     * halaman portal (3 pengumuman) untuk "Muat lagi", mulai dari `paging.next_page` feed.
     */
    #[Response(description: 'Pengumuman berhasil diambil (feed gabungan tanpa `page`, satu halaman dengan `page`).', type: "array{status: 'success', message: string, data: array{items: list<".self::ANNOUNCEMENT.'>, paging: array{pages_loaded: int, last_page: int|null, total: int|null, has_more: bool, next_page: int|null, complete: bool, stopped_at_known: bool}|array{page: int, per_page: int, from: int|null, to: int|null, total: int|null, last_page: int|null, has_previous: bool, has_next: bool, previous_page: int|null, next_page: int|null, out_of_range: bool}, notice: '.self::NOTICE.'|null, upcoming_deadlines: list<'.self::UPCOMING_DEADLINE.">, mode: 'feed'|'page'}, meta: array{timestamp: string, campus_session_valid: true, status: string, synced_at: string|null, data_origin: string}}")]
    #[Response(401, description: 'Endpoint ini membutuhkan sesi kampus yang aktif.', type: CampusApiController::SCRAMBLE_SESSION_ERROR_RESPONSE)]
    #[Response(422, description: 'Nomor halaman tidak valid.', type: CampusApiController::SCRAMBLE_VALIDATION_ERROR_RESPONSE)]
    #[Response(500, description: 'Pengumuman gagal diambil.', type: CampusApiController::SCRAMBLE_STATUS_ERROR_RESPONSE)]
    public function index(PortalAnnouncementRequest $request): JsonResponse
    {
        return $this->withCampusSession('Announcement extraction failed', 'Gagal memuat pengumuman', function (string $sessionToken) use ($request): JsonResponse {
            $today = $this->today();
            $page = $request->page();

            if ($page === null) {
                $feed = $this->readDataset($request, $sessionToken, 'announcements');
                $data = $this->refreshTimeFields($feed['data'], $today);

                return $this->statusSuccess('Announcements retrieved successfully', [
                    'data' => [
                        'items' => $this->safeItems($data['items'] ?? []),
                        'paging' => $data['paging'] ?? null,
                        'notice' => $this->withStagePaid($sessionToken, $data['notice'] ?? null, $today),
                        'upcoming_deadlines' => $this->upcomingDeadlines($data['upcoming_deadlines'] ?? [], $today),
                        'mode' => 'feed',
                    ],
                ], meta: $feed['meta'], campusSessionValid: true);
            }

            $pageData = $this->readDataset($request, $sessionToken, 'announcement_page', ['page' => $page]);
            $data = $this->refreshTimeFields($pageData['data'], $today);
            $paging = is_array($data['paging'] ?? null) ? $data['paging'] : null;

            if ($paging !== null) {
                // Portal URLs are useless without the campus session; page numbers are enough.
                unset($paging['previous_url'], $paging['next_url']);
            }

            return $this->statusSuccess('Announcements retrieved successfully', [
                'data' => [
                    'items' => $this->safeItems($data['items'] ?? []),
                    'paging' => $paging,
                    'notice' => $this->withStagePaid($sessionToken, $data['notice'] ?? null, $today),
                    'upcoming_deadlines' => [],
                    'mode' => 'page',
                ],
            ], meta: [
                ...$pageData['meta'],
                'page' => $page,
            ], campusSessionValid: true);
        });
    }

    /**
     * Detail satu pengumuman.
     *
     * Dicari di feed gabungan, lalu di halaman yang sudah pernah dimuat lewat `?page`. Tidak ada
     * request tambahan ke portal untuk pencarian ini.
     */
    #[PathParameter('id', 'Identifier pengumuman (`items[].id`, 40 karakter heksadesimal).', type: 'string', example: '3f1c2b0a9e8d7c6b5a4f3e2d1c0b9a8f7e6d5c4b')]
    #[Response(description: 'Detail pengumuman berhasil diambil.', type: "array{status: 'success', message: string, data: ".self::ANNOUNCEMENT.', meta: array{timestamp: string, campus_session_valid: true, status: string, synced_at: string|null, data_origin: string}}')]
    #[Response(401, description: 'Endpoint ini membutuhkan sesi kampus yang aktif.', type: CampusApiController::SCRAMBLE_SESSION_ERROR_RESPONSE)]
    #[Response(404, description: 'Pengumuman tidak ditemukan.', type: CampusApiController::SCRAMBLE_NOT_FOUND_RESPONSE)]
    #[Response(500, description: 'Pengumuman gagal diambil.', type: CampusApiController::SCRAMBLE_STATUS_ERROR_RESPONSE)]
    public function show(Request $request, string $id): JsonResponse
    {
        return $this->withCampusSession('Announcement detail failed', 'Gagal memuat pengumuman', function (string $sessionToken) use ($request, $id): JsonResponse {
            $today = $this->today();
            $feed = $this->readDataset($request, $sessionToken, 'announcements');
            $item = $this->findItem($feed['data']['items'] ?? [], $id);
            $meta = $feed['meta'];

            if ($item === null) {
                $lastPage = $feed['data']['paging']['last_page'] ?? null;
                $firstUnloaded = (int) ($feed['data']['paging']['pages_loaded'] ?? 1) + 1;

                for ($page = $firstUnloaded; is_int($lastPage) && $page <= min($lastPage, 500); $page++) {
                    $snapshot = $this->snapshots->get($sessionToken, 'announcement_page', ['page' => $page]);
                    $item = $this->findItem($snapshot['data']['items'] ?? [], $id);

                    if ($item !== null) {
                        $meta = [
                            'data_origin' => 'snapshot',
                            'status' => $snapshot['status'] ?? 'synced',
                            'synced_at' => $snapshot['synced_at'] ?? null,
                            'page' => $page,
                        ];

                        break;
                    }
                }
            }

            if ($item === null) {
                return $this->statusError('Pengumuman tidak ditemukan. Mungkin sudah dihapus dari portal.', 404, campusSessionValid: true);
            }

            return $this->statusSuccess('Announcement retrieved successfully', [
                'data' => $this->safeItems($this->refreshTimeFields(['items' => [$item]], $today)['items'])[0],
            ], meta: $meta, campusSessionValid: true);
        });
    }

    /**
     * K5: a payment notice naming a UKT stage carries `stage_paid` (true when
     * that stage is already recorded as paid, so the page hides the warning;
     * null when it names no stage or the payments snapshot is not there yet).
     * Read from the snapshot only, so the feed never waits on the portal.
     *
     * @return array<string, mixed>|null
     */
    private function withStagePaid(string $sessionToken, mixed $notice, CarbonImmutable $today): ?array
    {
        if (! is_array($notice)) {
            return null;
        }

        $stagePaid = null;

        if ($this->financeStatus->noticeStage((string) ($notice['text'] ?? '')) !== null) {
            $payments = $this->peekDatasets($sessionToken, ['payments'])['payments']['data'] ?? null;
            $stagePaid = $this->financeStatus->noticeStagePaid($notice, is_array($payments) ? $payments : null, $today);
        }

        return [...$notice, 'stage_paid' => $stagePaid];
    }

    /**
     * The extractor already unwraps portal action links in a body; this
     * second pass also drops any such link from `links[]` (K13).
     *
     * @return list<array<string, mixed>>
     */
    private function safeItems(mixed $items): array
    {
        $safe = [];

        foreach (is_array($items) ? $items : [] as $item) {
            if (! is_array($item)) {
                continue;
            }

            $item = $this->withoutWriteEffectLinks($item);

            if (is_array($item['links'] ?? null)) {
                $item['links'] = array_values(array_filter(
                    $item['links'],
                    static fn (mixed $link): bool => is_array($link) && is_string($link['url'] ?? null)
                ));
            }

            $safe[] = $item;
        }

        return $safe;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findItem(mixed $items, string $id): ?array
    {
        foreach (is_array($items) ? $items : [] as $item) {
            if (is_array($item) && ($item['id'] ?? null) === $id) {
                return $item;
            }
        }

        return null;
    }

    /**
     * Snapshots keep `is_future` as it was at sync time; a page snapshot can
     * be a day old, so every time-dependent flag is computed again.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function refreshTimeFields(array $data, CarbonImmutable $today): array
    {
        if (is_array($data['items'] ?? null)) {
            $data['items'] = array_map(function (mixed $item) use ($today): mixed {
                if (! is_array($item) || ! is_array($item['deadlines'] ?? null)) {
                    return $item;
                }

                $item['deadlines'] = array_map(fn (mixed $deadline): mixed => is_array($deadline)
                    ? [...$deadline, 'is_future' => $this->isOnOrAfter($deadline['date'] ?? null, $today)]
                    : $deadline, $item['deadlines']);

                return $item;
            }, $data['items']);
        }

        if (is_array($data['notice'] ?? null)) {
            $data['notice']['is_future'] = array_filter(
                is_array($data['notice']['dates'] ?? null) ? $data['notice']['dates'] : [],
                fn (mixed $date): bool => $this->isOnOrAfter($date, $today)
            ) !== [];
        }

        return $data;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function upcomingDeadlines(mixed $deadlines, CarbonImmutable $today): array
    {
        $upcoming = [];

        foreach (is_array($deadlines) ? $deadlines : [] as $deadline) {
            $date = is_array($deadline) ? $this->date($deadline['date'] ?? null) : null;

            if ($date === null || $date->lessThan($today)) {
                continue;
            }

            $start = $this->date($deadline['start'] ?? null);
            $upcoming[] = [
                ...$deadline,
                'days_left' => (int) round($today->diffInDays($date)),
                'is_ongoing' => $start !== null && $start->lessThanOrEqualTo($today),
            ];
        }

        return $upcoming;
    }

    private function isOnOrAfter(mixed $value, CarbonImmutable $today): bool
    {
        $date = $this->date($value);

        return $date !== null && $date->greaterThanOrEqualTo($today);
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1 || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }

        return CarbonImmutable::create((int) $parts[1], (int) $parts[2], (int) $parts[3], 0, 0, 0, self::TIMEZONE);
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::now(self::TIMEZONE)->startOfDay();
    }
}
