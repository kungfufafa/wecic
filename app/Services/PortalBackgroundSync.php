<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\PortalAuthenticationException;
use App\Exceptions\PortalConnectionException;
use App\Exceptions\PortalException;
use App\Jobs\FlushPortalOutboxJob;
use App\Jobs\SendPortalOutboxJob;
use App\Jobs\SyncPortalArchiveJob;
use App\Models\PortalOfflineAccount;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Keeps the archive current while the student is away.
 *
 * Every online login stores the credentials encrypted (PortalOfflineAccess);
 * the scheduler then picks the accounts whose last sync is older than
 * `portal.background_sync.interval` and runs one SyncPortalArchiveJob each.
 * The credentials are dropped `credential_days` after the last online login
 * (PortalOfflineAccount::prunable()) or as soon as the portal refuses them,
 * which ends the syncs until the student logs in online again.
 * A sync reuses the student's active online session when there is one, and
 * otherwise logs in, re-syncs `portal.background_sync.datasets` and logs out
 * again. Dataset builds archive and notify exactly as they do for a logged-in
 * student, so new grades or assignments found here become notifications too.
 *
 * Every `portal.background_sync.courses.interval` minutes a sync also crawls
 * the student's classes (PortalCourseArchiver): the class detail, every
 * meeting and the files they link to are archived without the student
 * opening them. That is dozens of pages, hence its own, longer interval.
 * Classes done are remembered for the round, so a crawl cut short (worker
 * timeout, expired session) resumes where it stopped instead of starting
 * over.
 *
 * A sync is stamped `running` when it starts, so a sync that never returns
 * (killed worker, timeout) neither keeps its account at the head of the
 * queue nor gets queued again before its interval; the stamp reads as
 * failed once the job's timeout has passed.
 *
 * Logging in resolves the student's owner key (PortalDataOwner), which may
 * move the account row to the current key or drop it in favour of the row
 * that key already had, with that row's background sync opt-out. The
 * account is therefore re-read after the login and before every later use
 * of its owner key: a sync whose account is gone, or now switched off,
 * stops there.
 */
class PortalBackgroundSync
{
    public const SYNC_RUNNING = 'running';

    private const EXPIRED_SESSION_PREFIX = 'expired_session: ';

    private const INTERRUPTED_MESSAGE = 'Sinkronisasi terhenti sebelum selesai (melewati batas waktu atau worker dihentikan).';

    private const UNREADABLE_CREDENTIALS_MESSAGE = 'Password tersimpan tidak bisa dibaca lagi. Masuk lagi di MyCIC saat portal online untuk menyimpannya ulang.';

    private const SWITCHED_OFF_MESSAGE = 'Sinkronisasi latar dimatikan sebelum sinkronisasi dimulai; tidak ada yang disinkronkan.';

    public function __construct(
        private readonly PortalSessionManager $sessionManager,
        private readonly PortalDatasetRepository $datasets,
        private readonly PortalOfflineAccess $offlineAccess,
        private readonly PortalCourseArchiver $courseArchiver
    ) {}

    /**
     * Whether the deployment stores credentials and syncs in the background
     * at all; each student can still switch it off for themselves.
     */
    public static function configured(): bool
    {
        return (bool) config('portal.archive.enabled', true)
            && (bool) config('portal.background_sync.enabled', true);
    }

    public function enabled(): bool
    {
        return self::configured();
    }

    /**
     * Whether syncs also crawl the classes (class detail, meetings, files).
     */
    public function crawlsCourses(): bool
    {
        return $this->enabled() && (bool) config('portal.background_sync.courses.enabled', true);
    }

    /**
     * What the archive page shows: whether syncs run for this student, when
     * the last one happened and how it went.
     *
     * @return array{available: bool, enabled: bool, active: bool, pending_login: bool, rejected: bool, interval_minutes: int, credential_days: int, last_synced_at: string|null, last_status: string|null, last_error: string|null, courses_enabled: bool, courses_interval_minutes: int, last_course_sync_at: string|null}
     */
    public function status(string $ownerHash): array
    {
        $account = $this->offlineAccess->findForOwner($ownerHash);
        $enabled = $this->enabled() && ($account === null || $account->isBackgroundSyncEnabled());
        $active = $account !== null && $this->enabled() && $this->hasUsableCredentials($account);
        [$lastStatus, $lastError] = $this->lastOutcome($account);

        return [
            'available' => $this->enabled(),
            'enabled' => $enabled,
            'active' => $active,
            // Switched on, but the credentials only arrive with the next online login.
            'pending_login' => $enabled && ! $active && ($account === null || $this->readableCredentials($account) === null) && $account?->credentials_rejected_at === null,
            'rejected' => $account?->credentials_rejected_at !== null,
            'interval_minutes' => $this->intervalMinutes(),
            'credential_days' => $this->credentialDays(),
            'last_synced_at' => $account?->last_background_sync_at?->toISOString(),
            'last_status' => $lastStatus,
            'last_error' => $lastError,
            'courses_enabled' => $this->crawlsCourses(),
            'courses_interval_minutes' => $this->coursesIntervalMinutes(),
            'last_course_sync_at' => $account?->last_course_sync_at?->toISOString(),
        ];
    }

    /**
     * Switching off drops the stored credentials at once, and is remembered
     * even when MyCIC keeps nothing else for the student yet; switching on
     * takes effect with the next online login, which stores them again.
     * Either way a class crawl cut short starts over: the archive may have
     * been deleted meanwhile, files of the classes it had done included.
     */
    public function setEnabled(string $ownerHash, bool $enabled): void
    {
        $account = $this->offlineAccess->findForOwner($ownerHash);

        if ($account === null) {
            if (! $enabled) {
                $this->offlineAccess->rememberBackgroundSyncOptOut($ownerHash);
                $this->sessionManager->forgetStoredCredentialsForOwner($ownerHash);
            }

            return;
        }

        $account->forceFill($enabled
            ? ['background_sync_disabled_at' => null]
            : ['background_sync_disabled_at' => now(), 'credentials' => null, 'credentials_rejected_at' => null]
        )->save();

        if (! $enabled) {
            // A busy session keeps its copy until its next keep-alive drops it.
            $this->sessionManager->forgetStoredCredentialsForOwner($ownerHash);
        }

        Cache::forget($this->courseCrawlKey($account));
    }

    public function intervalMinutes(): int
    {
        return max(5, (int) config('portal.background_sync.interval', 180));
    }

    public function coursesIntervalMinutes(): int
    {
        return max(5, (int) config('portal.background_sync.courses.interval', 1440));
    }

    public function credentialDays(): int
    {
        return PortalOfflineAccount::credentialDays();
    }

    /**
     * @return list<string>
     */
    public function datasets(): array
    {
        return array_values(array_filter(
            array_unique(array_filter((array) config('portal.background_sync.datasets', []), 'is_string')),
            static fn (string $dataset): bool => is_array(config("portal.datasets.{$dataset}"))
        ));
    }

    /**
     * Whether the next sync of this account also crawls the classes.
     */
    public function coursesDue(PortalOfflineAccount $account): bool
    {
        if (! $this->crawlsCourses()) {
            return false;
        }

        return $account->last_course_sync_at === null
            || $account->last_course_sync_at->addMinutes($this->coursesIntervalMinutes())->isPast();
    }

    /**
     * Accounts whose archive is due for a sync, the longest-waiting first.
     *
     * @return Collection<int, PortalOfflineAccount>
     */
    public function dueAccounts(?int $limit = null): Collection
    {
        if (! $this->enabled()) {
            return new Collection;
        }

        $limit ??= max(1, (int) config('portal.background_sync.batch_size', 10));

        return PortalOfflineAccount::query()
            ->withUsableCredentials($this->credentialDays())
            ->where(function ($query): void {
                $query->whereNull('last_background_sync_at')
                    ->orWhere('last_background_sync_at', '<=', now()->subMinutes($this->intervalMinutes()));
            })
            ->orderByRaw('last_background_sync_at is not null')
            ->orderBy('last_background_sync_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Queue one sync per due account.
     *
     * @return list<int> The account ids queued.
     */
    public function dispatchDue(?int $limit = null): array
    {
        $ids = [];

        foreach ($this->dueAccounts($limit) as $account) {
            SyncPortalArchiveJob::dispatch($account->id);
            $ids[] = $account->id;
        }

        return $ids;
    }

    /**
     * Sync one student's archive now, and record the outcome on the account.
     * Whatever goes wrong is recorded too: an account whose syncs keep
     * failing must not stay at the head of the queue.
     *
     * @return PortalOfflineAccount::SYNC_*
     */
    public function syncAccount(PortalOfflineAccount $account): string
    {
        if (! $this->enabled()) {
            return PortalOfflineAccount::SYNC_FAILED;
        }

        try {
            $credentials = $account->credentials ?? [];
        } catch (DecryptException) {
            $this->forgetUnreadableCredentials($account);

            return $this->record($account, PortalOfflineAccount::SYNC_FAILED, self::UNREADABLE_CREDENTIALS_MESSAGE);
        }

        if (! $account->hasUsableCredentials($this->credentialDays())) {
            return PortalOfflineAccount::SYNC_FAILED;
        }

        $account->forceFill([
            'last_background_sync_at' => now(),
            'last_background_sync_status' => self::SYNC_RUNNING,
            'last_background_sync_error' => null,
        ])->save();

        try {
            return $this->syncWith($account, (string) ($credentials['username'] ?? ''), (string) ($credentials['password'] ?? ''));
        } catch (\Throwable $throwable) {
            Log::warning('Background archive sync failed', [
                'owner_hash' => $this->reload($account) ? $account->owner_hash : null,
                'account_id' => $account->getKey(),
                'error' => $throwable->getMessage(),
            ]);

            return $this->record($account, PortalOfflineAccount::SYNC_FAILED, $throwable->getMessage());
        }
    }

    /**
     * Sends the student's outbox while they are not online in MyCIC
     * (portal:send-outbox, SendPortalOutboxJob): with their online session
     * when one appeared meanwhile, else with a background login with the
     * stored password, which also confirms what an offline session opened
     * with that same password queued (PortalOutbox::confirmWithPassword).
     * The background session is logged out once the flush is done. A
     * password the portal refuses is dropped, as for a sync. Never throws.
     *
     * @return 'flushed'|'nothing'|'unavailable'|'unreachable'|'rejected'|'failed'
     */
    public function sendOutbox(PortalOfflineAccount $account): string
    {
        $outbox = app(PortalOutbox::class);

        if (! $this->enabled() || ! $outbox->enabled() || ! $outbox->hasQueued($account->owner_hash)) {
            return 'nothing';
        }

        try {
            $credentials = $account->credentials ?? [];
        } catch (DecryptException) {
            $this->forgetUnreadableCredentials($account);
            $credentials = [];
        }

        $username = (string) ($credentials['username'] ?? '');
        $password = (string) ($credentials['password'] ?? '');
        $usable = $username !== '' && $password !== '' && $account->hasUsableCredentials($this->credentialDays());
        $activeToken = $this->sessionManager->activeOnlineSessionTokenForOwner($account->owner_hash);

        if ($activeToken !== null) {
            // The stored password is the one the portal accepted at the
            // student's last online login: it confirms what an offline
            // session opened with it queued meanwhile (another device).
            if ($usable) {
                $outbox->confirmWithPassword($activeToken, $password);
            }

            $outbox->flushWhenQueued($activeToken);

            return 'flushed';
        }

        if (! $usable) {
            return 'unavailable';
        }

        try {
            $session = $this->sessionManager->createBackgroundSession($username, $password);
        } catch (PortalAuthenticationException $exception) {
            if (! PortalAuthService::refusedPassword($exception)) {
                return 'unreachable';
            }

            $this->markCredentialsRejected($account);

            return 'rejected';
        } catch (PortalConnectionException) {
            return 'unreachable';
        } catch (\Throwable $throwable) {
            Log::warning('Outbox send could not log in', ['account_id' => $account->getKey(), 'error' => $throwable->getMessage()]);

            return 'failed';
        }

        try {
            $outbox->confirmWithPassword($session['session_token'], $password);
            FlushPortalOutboxJob::dispatchSync($session['session_token'], null, 1, false);

            // Left over (a time budget, a target another upload holds): the
            // next portal:send-outbox run sends it, without waiting out the throttle.
            if ($outbox->hasSendable($account->owner_hash)) {
                Cache::forget(SendPortalOutboxJob::attemptKey((int) $account->getKey()));
            }
        } catch (\Throwable $throwable) {
            Log::warning('Outbox send failed', ['account_id' => $account->getKey(), 'error' => $throwable->getMessage()]);

            return 'failed';
        } finally {
            $this->sessionManager->destroySession($session['session_token']);
        }

        return 'flushed';
    }

    /**
     * A sync that ended without returning (worker timeout, failed job):
     * recorded as failed so the account waits for its next interval.
     */
    public function recordInterrupted(int $accountId, ?\Throwable $throwable = null): void
    {
        $account = PortalOfflineAccount::query()->find($accountId);

        if ($account === null) {
            return;
        }

        $this->record($account, PortalOfflineAccount::SYNC_FAILED, self::INTERRUPTED_MESSAGE, [
            'error' => $throwable?->getMessage(),
        ]);
    }

    /**
     * @return PortalOfflineAccount::SYNC_*
     */
    private function syncWith(PortalOfflineAccount $account, string $username, string $password): string
    {
        if ($username === '' || $password === '') {
            return $this->record($account, PortalOfflineAccount::SYNC_FAILED, 'Kredensial tersimpan tidak lengkap.');
        }

        $activeToken = $this->sessionManager->activeOnlineSessionTokenForOwner($account->owner_hash);

        if ($activeToken !== null) {
            [$status, $error] = $this->syncAll($activeToken, $account);

            // A session the portal no longer accepts: log in on our own below.
            if ($status !== PortalOfflineAccount::SYNC_FAILED || ! $this->isExpiredSessionError($error)) {
                return $this->record($account, $status, $error);
            }
        }

        try {
            $session = $this->sessionManager->createBackgroundSession($username, $password);
        } catch (PortalAuthenticationException $exception) {
            if (! PortalAuthService::refusedPassword($exception)) {
                return $this->record($account, PortalOfflineAccount::SYNC_UNREACHABLE, 'Portal belum menjawab login dengan benar; dicoba lagi pada jadwal berikutnya.');
            }

            if (! $this->markCredentialsRejected($account)) {
                return $this->record($account, PortalOfflineAccount::SYNC_FAILED, 'Password tersimpan berganti saat sinkronisasi berjalan; dicoba lagi pada jadwal berikutnya.');
            }

            return $this->record($account, PortalOfflineAccount::SYNC_REJECTED, 'Portal menolak password tersimpan; masuk lagi di MyCIC untuk memperbaruinya.');
        } catch (PortalConnectionException $exception) {
            return $this->record($account, PortalOfflineAccount::SYNC_UNREACHABLE, $exception->getMessage());
        } catch (\Throwable $throwable) {
            return $this->record($account, PortalOfflineAccount::SYNC_FAILED, $throwable->getMessage());
        }

        try {
            $this->offlineAccess->refreshUserInfo($username, $session['user_info']);

            if (! $this->reload($account)) {
                return $this->stopForMergedAccount($account);
            }

            if (! $account->isBackgroundSyncEnabled()) {
                return $this->record($account, PortalOfflineAccount::SYNC_FAILED, self::SWITCHED_OFF_MESSAGE);
            }

            [$status, $error] = $this->syncAll($session['session_token'], $account);
        } finally {
            $this->sessionManager->destroySession($session['session_token']);
        }

        return $this->record($account, $status, $error);
    }

    /**
     * The configured datasets, then the crawl of the classes when it is due.
     * An expired session ends either at once: the caller logs in again.
     *
     * @return array{0: PortalOfflineAccount::SYNC_*, 1: string|null}
     */
    private function syncAll(string $sessionToken, PortalOfflineAccount $account): array
    {
        $datasets = $this->syncDatasets($sessionToken);

        if ($this->isExpiredSessionError($datasets[1]) || ! $this->coursesDue($account)) {
            return $datasets;
        }

        $courses = $this->syncCourses($sessionToken, $account);

        if ($this->isExpiredSessionError($courses[1])) {
            return $courses;
        }

        return $this->combine($datasets, $courses);
    }

    /**
     * @return array{0: PortalOfflineAccount::SYNC_*, 1: string|null}
     */
    private function syncDatasets(string $sessionToken): array
    {
        $synced = 0;
        $errors = [];

        foreach ($this->datasets() as $dataset) {
            try {
                $this->datasets->sync($sessionToken, $dataset);
                $synced++;
            } catch (\Throwable $throwable) {
                $errors[$dataset] = $throwable->getMessage();

                if (PortalException::indicatesExpiredSession($throwable)) {
                    return [PortalOfflineAccount::SYNC_FAILED, self::EXPIRED_SESSION_PREFIX.$throwable->getMessage()];
                }

                Log::warning('Background archive sync of a dataset failed', [
                    'dataset' => $dataset,
                    'error' => $throwable->getMessage(),
                ]);
            }
        }

        $error = $errors === [] ? null : implode('; ', array_map(
            static fn (string $dataset, string $message): string => "{$dataset}: {$message}",
            array_keys($errors),
            $errors
        ));

        return match (true) {
            $errors === [] => [PortalOfflineAccount::SYNC_SYNCED, null],
            $synced > 0 => [PortalOfflineAccount::SYNC_PARTIAL, $error],
            default => [PortalOfflineAccount::SYNC_FAILED, $error],
        };
    }

    /**
     * Crawl every class in the student's class list (PortalCourseArchiver).
     * The crawl is stamped on the account unless no class could be archived
     * at all, so a portal outage is retried with the next dataset sync
     * instead of waiting a whole course interval. Until the round is
     * stamped, the classes it archived are skipped by the next attempt.
     * The account is re-read before it is stamped (see reload()): an
     * account gone meanwhile is left to record().
     *
     * @return array{0: PortalOfflineAccount::SYNC_*, 1: string|null}
     */
    private function syncCourses(string $sessionToken, PortalOfflineAccount $account): array
    {
        $progressKey = $this->courseCrawlKey($account);
        $done = Cache::get($progressKey);
        $done = is_array($done) ? $done : [];

        try {
            $classes = $this->courseArchiver->classParamsFrom($this->datasets->ensureFresh($sessionToken, 'classes'));
        } catch (\Throwable $throwable) {
            if (PortalException::indicatesExpiredSession($throwable)) {
                return [PortalOfflineAccount::SYNC_FAILED, self::EXPIRED_SESSION_PREFIX.$throwable->getMessage()];
            }

            Log::warning('Background archive sync could not read the class list', ['error' => $throwable->getMessage()]);

            return [PortalOfflineAccount::SYNC_FAILED, 'materi kelas: daftar kelas tidak terbaca: '.$throwable->getMessage()];
        }

        $archived = 0;
        $errors = [];

        foreach ($classes as $classParams) {
            if (in_array($classParams['id'], $done, true)) {
                $archived++;

                continue;
            }

            try {
                $result = $this->courseArchiver->archiveClass($sessionToken, $classParams);
                $archived++;
                $done[] = $classParams['id'];
                Cache::put($progressKey, $done, $this->coursesIntervalMinutes() * 60);
                $label = $result['course_name'] ?? "kelas {$classParams['id']}";

                foreach ($result['errors'] as $error) {
                    $errors[] = "{$label}: {$error}";
                }
            } catch (\Throwable $throwable) {
                if (PortalException::indicatesExpiredSession($throwable)) {
                    return [PortalOfflineAccount::SYNC_FAILED, self::EXPIRED_SESSION_PREFIX.$throwable->getMessage()];
                }

                Log::warning('Background archive sync of a class failed', [
                    'class_id' => $classParams['id'],
                    'error' => $throwable->getMessage(),
                ]);
                $errors[] = "kelas {$classParams['id']}: {$throwable->getMessage()}";
            }
        }

        $status = match (true) {
            $errors === [] => PortalOfflineAccount::SYNC_SYNCED,
            $archived > 0 => PortalOfflineAccount::SYNC_PARTIAL,
            default => PortalOfflineAccount::SYNC_FAILED,
        };

        $errorSummary = $errors === [] ? null : 'materi kelas: '.implode('; ', $errors);

        if (! $this->reload($account)) {
            Cache::forget($progressKey);

            return [$status, $errorSummary];
        }

        if ($status !== PortalOfflineAccount::SYNC_FAILED) {
            $account->forceFill(['last_course_sync_at' => now()])->save();
            Cache::forget($progressKey);
        }

        Log::info('Background archive sync crawled the classes', [
            'domain' => 'campus_session',
            'event' => 'background_sync_courses_'.$status,
            'component' => self::class,
            'owner_hash' => $account->owner_hash,
            'classes' => count($classes),
            'archived' => $archived,
        ]);

        return [$status, $errorSummary];
    }

    /**
     * @param  array{0: PortalOfflineAccount::SYNC_*, 1: string|null}  $datasets
     * @param  array{0: PortalOfflineAccount::SYNC_*, 1: string|null}  $courses
     * @return array{0: PortalOfflineAccount::SYNC_*, 1: string|null}
     */
    private function combine(array $datasets, array $courses): array
    {
        $statuses = [$datasets[0], $courses[0]];
        $status = match (true) {
            $statuses === [PortalOfflineAccount::SYNC_SYNCED, PortalOfflineAccount::SYNC_SYNCED] => PortalOfflineAccount::SYNC_SYNCED,
            $statuses === [PortalOfflineAccount::SYNC_FAILED, PortalOfflineAccount::SYNC_FAILED] => PortalOfflineAccount::SYNC_FAILED,
            default => PortalOfflineAccount::SYNC_PARTIAL,
        };
        $errors = array_values(array_filter([$datasets[1], $courses[1]], 'is_string'));

        return [$status, $errors === [] ? null : implode('; ', $errors)];
    }

    /**
     * Where the classes archived by a crawl round not yet stamped are kept.
     */
    private function courseCrawlKey(PortalOfflineAccount $account): string
    {
        return 'portal_background_course_crawl_'.$account->id;
    }

    private function isExpiredSessionError(?string $error): bool
    {
        return is_string($error) && str_starts_with($error, self::EXPIRED_SESSION_PREFIX);
    }

    /**
     * Re-read the account in place. Resolving the student's owner key
     * (PortalDataOwner::keyForUsername(), on every login and session store)
     * may have moved its row to the current key or dropped it in favour of
     * the row that key already had.
     *
     * @return bool False when the row is gone.
     */
    private function reload(PortalOfflineAccount $account): bool
    {
        try {
            $account->refresh();
        } catch (ModelNotFoundException) {
            return false;
        }

        return true;
    }

    /**
     * The account was merged into the row of the student's current owner
     * key while this sync ran: nothing is recorded on it, and that row is
     * synced on its own schedule (or not at all, when it is switched off).
     *
     * @return PortalOfflineAccount::SYNC_FAILED
     */
    private function stopForMergedAccount(PortalOfflineAccount $account): string
    {
        Cache::forget($this->courseCrawlKey($account));

        Log::info('Background archive sync stopped: the account moved to the current owner key', [
            'domain' => 'campus_session',
            'event' => 'background_sync_account_merged',
            'component' => self::class,
            'account_id' => $account->getKey(),
        ]);

        return PortalOfflineAccount::SYNC_FAILED;
    }

    /**
     * Stamp the outcome on the account as it is now: a login during the
     * sync may have moved it to the student's current owner key, or dropped
     * it in favour of the row that key already had, which keeps its own.
     *
     * @param  PortalOfflineAccount::SYNC_*  $status
     * @param  array<string, mixed>  $context
     * @return PortalOfflineAccount::SYNC_*
     */
    private function record(PortalOfflineAccount $account, string $status, ?string $error, array $context = []): string
    {
        if (! $this->reload($account)) {
            $this->stopForMergedAccount($account);

            return $status;
        }

        $account->forceFill([
            'last_background_sync_at' => now(),
            'last_background_sync_status' => $status,
            'last_background_sync_error' => $error !== null ? mb_substr($error, 0, 500) : null,
        ])->save();

        Log::info('Background archive sync finished', [
            'domain' => 'campus_session',
            'event' => 'background_sync_'.$status,
            'component' => self::class,
            'owner_hash' => $account->owner_hash,
            'error' => $error,
            ...$context,
        ]);

        return $status;
    }

    /**
     * The status and error to show: a `running` stamp older than the job's
     * timeout belongs to a sync that died without recording its outcome.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function lastOutcome(?PortalOfflineAccount $account): array
    {
        $status = $account?->last_background_sync_status;
        $startedAt = $account?->last_background_sync_at;

        if ($status === self::SYNC_RUNNING && ($startedAt === null || $startedAt->copy()->addSeconds(SyncPortalArchiveJob::TIMEOUT)->isPast())) {
            return [PortalOfflineAccount::SYNC_FAILED, self::INTERRUPTED_MESSAGE];
        }

        return [$status, $account?->last_background_sync_error];
    }

    private function hasUsableCredentials(PortalOfflineAccount $account): bool
    {
        try {
            return $account->hasUsableCredentials($this->credentialDays());
        } catch (DecryptException) {
            return false;
        }
    }

    /**
     * @return array<string, mixed>|null Null when none are stored or they cannot be decrypted.
     */
    private function readableCredentials(PortalOfflineAccount $account): ?array
    {
        try {
            $credentials = $account->credentials;
        } catch (DecryptException) {
            return null;
        }

        return is_array($credentials) ? $credentials : null;
    }

    /**
     * Credentials encrypted with a key the app no longer has (APP_KEY
     * rotated) can never be used: they are dropped, so the account leaves
     * the queue and the next online login stores them again. Credentials
     * stored meanwhile by a login are left alone.
     *
     * The account is re-read afterwards, so the warning names the owner key
     * the row has now: a login since the job loaded it may have moved it to
     * the student's current key (see reload()), or dropped it.
     */
    private function forgetUnreadableCredentials(PortalOfflineAccount $account): void
    {
        PortalOfflineAccount::query()
            ->whereKey($account->getKey())
            ->where('credentials', $account->getRawOriginal('credentials'))
            ->update(['credentials' => null]);

        $exists = $this->reload($account);

        Log::warning('Stored background sync credentials could not be decrypted', [
            'owner_hash' => $exists ? $account->owner_hash : null,
            'account_id' => $account->getKey(),
        ]);
    }

    /**
     * Flag the credentials this sync logged in with as rejected and drop
     * them, unless the student stored new ones (or switched background sync
     * off) while the login was in flight: those were never tried. A refused
     * password is not kept. The offline-login hash goes with it, since
     * remember() writes both from the same password, so it no longer opens
     * MyCIC offline either.
     */
    private function markCredentialsRejected(PortalOfflineAccount $account): bool
    {
        return PortalOfflineAccount::query()
            ->whereKey($account->getKey())
            ->where('credentials', $account->getRawOriginal('credentials'))
            ->update(['credentials_rejected_at' => now(), 'credentials' => null, 'password_hash' => '']) === 1;
    }
}
