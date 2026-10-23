<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Exceptions\PortalException;
use App\Exceptions\PortalOfflineException;
use App\Http\Requests\PortalAnnouncementRequest;
use App\Services\Extractors\AnnouncementExtractor;
use App\Services\PortalAnnouncementIds;
use App\Services\PortalDatasetRepository;
use App\Services\PortalFinanceStatusResolver;
use App\Services\PortalOfflineAccess;
use App\Services\PortalSnapshotStore;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

#[Group('Pengumuman', 'Feed pengumuman kampus: kategori, tenggat yang bisa dihitung mundur, tautan, dan detail per pengumuman.', 26)]
class PortalAnnouncementController extends CampusApiController
{
    private const TIMEZONE = 'Asia/Jakarta';

    private const NOTICE = 'array{text: string, dates: list<string>, is_future: bool, kind: string, stage_paid: bool|null}';

    private const ANNOUNCEMENT = "array{id: string, legacy_id?: string, title: string, published_at: string|null, published_date: string|null, published_label: string|null, publisher: string|null, publisher_unit: string|null, publisher_unit_short: string|null, category: 'krs'|'pembayaran'|'semester_pendek'|'surat'|'akademik'|'umum', category_label: string, excerpt: string, body_text: string, body_html: string, links: list<array{label: string, url: string, is_file: bool, is_campus_file: bool, kind: 'form'|'file'|'link'}>, emails: list<string>, deadlines: list<array{date: string, start: string|null, type: 'deadline'|'period'|'date', context: string, is_future: bool}>, steps: list<array{number: int, text: string}>, page?: int, duplicate_of?: string|null}";

    private const UPCOMING_DEADLINE = 'array{announcement_id: string, title: string, category: string, category_label: string, date: string, start: string|null, type: string, context: string, days_left: int, is_ongoing: bool}';

    public function __construct(
        private readonly PortalSnapshotStore $snapshots,
        private readonly PortalFinanceStatusResolver $financeStatus,
        private readonly PortalAnnouncementIds $announcementIds,
        private readonly PortalOfflineAccess $offlineAccess
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

            $feed = $this->snapshots->get($sessionToken, 'announcements')['data'] ?? null;
            $feed = is_array($feed) ? $feed : null;
            $pageData = $this->readDataset($request, $sessionToken, 'announcement_page', $this->pageParams($sessionToken, $page, $feed));
            $data = $this->refreshTimeFields($pageData['data'], $today);
            $paging = is_array($data['paging'] ?? null) ? $data['paging'] : null;

            if ($paging !== null) {
                // Portal URLs are useless without the campus session; page numbers are enough.
                unset($paging['previous_url'], $paging['next_url']);
            }

            return $this->statusSuccess('Announcements retrieved successfully', [
                'data' => [
                    'items' => $this->safeItems($this->distinctFromEarlier($data['items'] ?? [], $this->itemsBefore($sessionToken, $page, $feed))),
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
     * Dicari di feed gabungan, lalu di beberapa halaman portal sesudahnya (`detail_lookup_pages`,
     * memakai halaman yang sudah tersimpan bila ada): pengumuman dari notifikasi lama biasanya sudah
     * turun beberapa halaman. Halaman yang lebih jauh dan sudah dimuat lewat "Muat lagi" ikut dicari
     * dari salinannya, tanpa ke portal. 404 berarti tidak ada di halaman-halaman itu; 503 berarti
     * portal belum bisa dihubungi untuk mencarinya.
     *
     * `id` tetap sama walau isi pengumuman disunting di portal (dibentuk dari judul, tanggal terbit,
     * dan unit). Id lama yang dibentuk dari isi (`legacy_id`, ada di tautan notifikasi lama) juga
     * diterima, juga untuk pengumuman yang sudah keluar dari feed atau isinya sudah disunting: id lama
     * dicocokkan dengan arsip MyCIC (setiap versi feed yang pernah tampil) lalu dicari dengan id yang
     * berlaku sekarang. `data.id` selalu id yang berlaku sekarang, dan notifikasi mahasiswa yang masih
     * menautkan id lama ikut diperbarui.
     *
     * Bila pengumuman tidak ada di halaman-halaman yang dicari tetapi tersimpan di arsip MyCIC, salinan
     * arsip terakhir yang dipercaya dikirim dengan `meta.data_origin` = `archive` dan `meta.synced_at`
     * = kapan portal terakhir menampilkannya.
     *
     * Dalam mode offline portal tidak dihubungi sama sekali: hanya halaman yang sudah tersimpan yang
     * dicari (`meta.status` = `offline`), lalu arsip MyCIC. 503 dengan `meta.offline` = true berarti
     * pengumuman ada di halaman yang belum pernah tersimpan dan belum ada di arsip.
     */
    #[PathParameter('id', 'Identifier pengumuman (`items[].id`, atau `items[].legacy_id` dari tautan lama; 40 karakter heksadesimal).', type: 'string', example: '3f1c2b0a9e8d7c6b5a4f3e2d1c0b9a8f7e6d5c4b')]
    #[Response(description: 'Detail pengumuman berhasil diambil (dari feed, halaman portal sesudahnya, atau salinan arsip MyCIC).', type: "array{status: 'success', message: string, data: ".self::ANNOUNCEMENT.', meta: array{timestamp: string, campus_session_valid: true, status: string, synced_at: string|null, data_origin: string, page?: int}}')]
    #[Response(401, description: 'Endpoint ini membutuhkan sesi kampus yang aktif.', type: CampusApiController::SCRAMBLE_SESSION_ERROR_RESPONSE)]
    #[Response(404, description: 'Pengumuman tidak ditemukan.', type: CampusApiController::SCRAMBLE_NOT_FOUND_RESPONSE)]
    #[Response(503, description: 'Pengumuman tidak ada di feed maupun arsip, dan portal belum bisa dihubungi untuk mencarinya (dalam mode offline: `meta.offline` = true).', type: CampusApiController::SCRAMBLE_STATUS_ERROR_RESPONSE)]
    #[Response(500, description: 'Pengumuman gagal diambil.', type: CampusApiController::SCRAMBLE_STATUS_ERROR_RESPONSE)]
    public function show(Request $request, string $id): JsonResponse
    {
        return $this->withCampusSession('Announcement detail failed', 'Gagal memuat pengumuman', function (string $sessionToken) use ($request, $id): JsonResponse {
            $today = $this->today();
            $feed = $this->readDataset($request, $sessionToken, 'announcements');
            $feedItems = $feed['data']['items'] ?? [];
            $ids = [$id];
            $item = PortalAnnouncementIds::find($feedItems, $id);
            $meta = $feed['meta'];
            $lookupFailed = false;
            $unstoredOffline = null;

            if ($item === null) {
                $currentId = $this->announcementIds->currentId($sessionToken, $id, $feedItems);

                if ($currentId !== null) {
                    $ids[] = $currentId;
                    $item = PortalAnnouncementIds::find($feedItems, $currentId);
                }
            }

            $offline = $item === null && $this->offlineAccess->isOfflineSession($sessionToken);

            if ($item === null) {
                try {
                    [$item, $meta] = $this->findOnLaterPages($sessionToken, $ids, is_array($feed['data'] ?? null) ? $feed['data'] : [], $offline) ?? [null, $meta];
                } catch (PortalOfflineException $offlineException) {
                    $unstoredOffline = $offlineException;
                } catch (\Throwable $throwable) {
                    if (PortalException::indicatesExpiredSession($throwable)) {
                        throw $throwable;
                    }

                    Log::warning('Announcement lookup past the feed failed', ['error' => $throwable->getMessage()]);
                    $lookupFailed = true;
                }
            }

            if ($item === null) {
                $copy = $this->announcementIds->archivedCopy($sessionToken, array_reverse($ids));

                if ($copy !== null) {
                    $item = $copy['item'];
                    $meta = [
                        'data_origin' => 'archive',
                        'synced_at' => $copy['seen_at'],
                        'status' => match (true) {
                            $offline || $unstoredOffline !== null => 'offline',
                            $lookupFailed => 'failed',
                            default => 'synced',
                        },
                    ];
                }
            }

            if ($item === null && $unstoredOffline !== null) {
                // Offline, on a page never stored and not archived either: the offline answer, not a 404.
                throw $unstoredOffline;
            }

            if ($item === null && $lookupFailed) {
                return $this->statusError('Pengumuman ini tidak ada di halaman terbaru, dan portal belum bisa dihubungi untuk mencarinya. Coba lagi beberapa saat.', 503, campusSessionValid: true);
            }

            if ($item === null) {
                // Not seen on the pages searched nor archived: edited, removed, or older than the lookup reaches.
                return $this->statusError('Pengumuman tidak ditemukan. Bisa jadi sudah diperbarui atau dihapus di portal, atau sudah terlalu lama.', 404, campusSessionValid: true);
            }

            if (is_string($item['id'] ?? null) && $item['id'] !== $id && AnnouncementExtractor::stableIdOf($item) !== $id) {
                // Opened by an id it no longer goes by: the student's notifications follow.
                $this->announcementIds->follow($sessionToken, $id, $item['id']);
            }

            return $this->statusSuccess('Announcement retrieved successfully', [
                'data' => $this->safeItems($this->refreshTimeFields(['items' => [$item]], $today)['items'])[0],
            ], meta: $meta, campusSessionValid: true);
        });
    }

    /**
     * The portal pages right after the feed, at most `detail_lookup_pages`
     * of them, each read once per feed state (a snapshot when there is one),
     * then the pages past those that the student already loaded with "Muat
     * lagi": snapshots only, never a portal request. Pages are loaded in
     * order, so the first page without a snapshot ends the search. Never
     * forced fresh: a manual refresh of the detail must not re-read every
     * page.
     *
     * In an offline session the repository never contacts the portal: the
     * pages are their snapshots, and a page of the first few that was never
     * stored throws PortalOfflineException (online it would have been read).
     *
     * @param  list<string>  $ids  The id asked for, then the id it goes by now when known.
     * @param  array<string, mixed>  $feed
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}|null The item and its meta.
     *
     * @throws PortalOfflineException
     */
    private function findOnLaterPages(string $sessionToken, array $ids, array $feed, bool $offline): ?array
    {
        $lastPage = $feed['paging']['last_page'] ?? null;

        if (! is_int($lastPage)) {
            return null;
        }

        $firstPage = max(1, (int) ($feed['paging']['pages_loaded'] ?? 1) + 1);
        $lastLookup = min($lastPage, $firstPage + max(0, (int) config('portal.datasets.announcement_page.detail_lookup_pages', 4)) - 1);
        $repository = app(PortalDatasetRepository::class);
        $earlier = is_array($feed['items'] ?? null) ? array_values($feed['items']) : [];

        for ($page = $firstPage; $page <= $lastLookup; $page++) {
            $pageData = $repository->read($sessionToken, 'announcement_page', $this->pageParams($sessionToken, $page, $feed), false, (string) session()->getId());
            $items = $pageData['data']['items'] ?? [];
            $distinct = $this->distinctFromEarlier($items, $earlier);
            $item = $this->findAny($distinct, $ids);

            if ($item !== null) {
                return [$item, [...$pageData['meta'], 'page' => $page]];
            }

            if (! is_array($items) || $items === [] || ($pageData['data']['paging']['out_of_range'] ?? false) === true) {
                return null;
            }

            $earlier = [...$earlier, ...$distinct];
        }

        for ($page = max($firstPage, $lastLookup + 1); $page <= $lastPage; $page++) {
            $snapshot = $this->snapshots->get($sessionToken, 'announcement_page', $this->pageParams($sessionToken, $page, $feed));
            $items = $snapshot['data']['items'] ?? null;

            if (! is_array($items) || $items === []) {
                return null;
            }

            $distinct = $this->distinctFromEarlier($items, $earlier);
            $earlier = [...$earlier, ...$distinct];
            $item = $this->findAny($distinct, $ids);

            if ($item !== null) {
                return [$item, [
                    'data_origin' => 'snapshot',
                    'synced_at' => $snapshot['synced_at'] ?? null,
                    'status' => $offline ? 'offline' : ($snapshot['status'] ?? 'synced'),
                    'page' => $page,
                ]];
            }
        }

        return null;
    }

    /**
     * Snapshot params of one portal page. Pages are windows of the portal's
     * newest-first list and shift when staff post again, so a page is kept
     * per feed state (its total and newest id): a newer feed reads its pages
     * anew instead of continuing with a window from before the new posts.
     *
     * @param  array<string, mixed>|null  $feed  The feed being continued; its snapshot when null.
     * @return array{page: int, feed?: string}
     */
    private function pageParams(string $sessionToken, int $page, ?array $feed = null): array
    {
        $feed ??= $this->snapshots->get($sessionToken, 'announcements')['data'] ?? null;
        $total = is_array($feed) ? ($feed['paging']['total'] ?? null) : null;
        $newestId = is_array($feed) ? ($feed['items'][0]['id'] ?? null) : null;

        if (! is_int($total) && ! is_string($newestId)) {
            return ['page' => $page];
        }

        return ['page' => $page, 'feed' => substr(sha1($total.'|'.$newestId), 0, 16)];
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
     * The first of `$ids` found among the items (PortalAnnouncementIds::find()).
     *
     * @param  list<string>  $ids
     * @return array<string, mixed>|null
     */
    private function findAny(mixed $items, array $ids): ?array
    {
        foreach ($ids as $id) {
            $item = PortalAnnouncementIds::find($items, $id);

            if ($item !== null) {
                return $item;
            }
        }

        return null;
    }

    /**
     * What comes before a page past the feed, as "Muat lagi" lists it: the
     * feed, then every page between them already loaded (snapshots only,
     * never a portal request), each told apart from what precedes it.
     *
     * @param  array<string, mixed>|null  $feed
     * @return list<array<string, mixed>>
     */
    private function itemsBefore(string $sessionToken, int $page, ?array $feed): array
    {
        $earlier = is_array($feed['items'] ?? null) ? array_values($feed['items']) : [];
        $firstPage = max(1, (int) ($feed['paging']['pages_loaded'] ?? 1) + 1);

        for ($previous = $firstPage; $previous < $page; $previous++) {
            $items = $this->snapshots->get($sessionToken, 'announcement_page', $this->pageParams($sessionToken, $previous, $feed))['data']['items'] ?? null;

            if (is_array($items)) {
                $earlier = [...$earlier, ...$this->distinctFromEarlier($items, $earlier)];
            }
        }

        return $earlier;
    }

    /**
     * A page past the feed is read on its own, so an announcement there can
     * share its stable id with a different one listed before it (same title,
     * day and unit, other wording) in the feed or on an earlier page. The
     * one listed first keeps the id; the later one uses its legacy id, the
     * same way on every read, so each card opens its own announcement. The
     * same wording read again (shifted to the next page) takes the id it
     * already has.
     *
     * @param  list<array<string, mixed>>  $earlier  Items listed before, already told apart.
     * @return list<array<string, mixed>>
     */
    private function distinctFromEarlier(mixed $items, array $earlier): array
    {
        $claims = [];

        foreach ($earlier as $earlierItem) {
            if (! is_array($earlierItem) || ! is_string($earlierItem['id'] ?? null)) {
                continue;
            }

            $wording = is_string($earlierItem['legacy_id'] ?? null) ? $earlierItem['legacy_id'] : $earlierItem['id'];

            foreach (array_unique(array_filter([$earlierItem['id'], AnnouncementExtractor::stableIdOf($earlierItem)])) as $claimed) {
                $claims[$claimed][$wording] ??= $earlierItem['id'];
            }
        }

        $distinct = [];

        foreach (is_array($items) ? $items : [] as $item) {
            if (is_array($item) && is_string($item['id'] ?? null) && is_string($item['legacy_id'] ?? null)) {
                $item['id'] = $this->distinctId($item, $claims);

                foreach (array_unique(array_filter([$item['id'], AnnouncementExtractor::stableIdOf($item)])) as $claimed) {
                    $claims[$claimed][$item['legacy_id']] ??= $item['id'];
                }
            }

            $distinct[] = $item;
        }

        return $distinct;
    }

    /**
     * @param  array<string, mixed>  $item  With a string `id` and `legacy_id`.
     * @param  array<string, array<string, string>>  $claims  Id or stable id => wording => id given.
     */
    private function distinctId(array $item, array $claims): string
    {
        $sharing = [];

        foreach (array_unique(array_filter([$item['id'], AnnouncementExtractor::stableIdOf($item)])) as $claimed) {
            $sharing += $claims[$claimed] ?? [];
        }

        if (isset($sharing[$item['legacy_id']])) {
            return $sharing[$item['legacy_id']];
        }

        return $sharing === [] ? $item['id'] : $item['legacy_id'];
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
