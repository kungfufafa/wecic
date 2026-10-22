<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\PortalAuthenticationException;
use App\Exceptions\PortalConnectionException;
use App\Exceptions\PortalException;
use App\Jobs\SyncPortalArchiveJob;
use App\Models\PortalOfflineAccount;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Keeps the archive current while the student is away.
 *
 * Every online login stores the credentials encrypted (PortalOfflineAccess);
 * the scheduler then picks the accounts whose last sync is older than
 * `portal.background_sync.interval` and runs one SyncPortalArchiveJob each.
 * A sync reuses the student's active online session when there is one, and
 * otherwise logs in, re-syncs `portal.background_sync.datasets` and logs out
 * again. Dataset builds archive and notify exactly as they do for a logged-in
 * student, so new grades or assignments found here become notifications too.
 *
 * Every `portal.background_sync.courses.interval` minutes a sync also crawls
 * the student's classes (PortalCourseArchiver): the class detail, every
 * meeting and the files they link to are archived without the student
 * opening them. That is dozens of pages, hence its own, longer interval.
 */
class PortalBackgroundSync
{
    private const EXPIRED_SESSION_PREFIX = 'expired_session: ';

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
        $active = $account !== null && $this->enabled() && $account->hasUsableCredentials($this->credentialDays());

        return [
            'available' => $this->enabled(),
            'enabled' => $enabled,
            'active' => $active,
            // Switched on, but the credentials only arrive with the next online login.
            'pending_login' => $enabled && ! $active && ($account === null || ! is_array($account->credentials)) && $account?->credentials_rejected_at === null,
            'rejected' => $account?->credentials_rejected_at !== null,
            'interval_minutes' => $this->intervalMinutes(),
            'credential_days' => $this->credentialDays(),
            'last_synced_at' => $account?->last_background_sync_at?->toISOString(),
            'last_status' => $account?->last_background_sync_status,
            'last_error' => $account?->last_background_sync_error,
            'courses_enabled' => $this->crawlsCourses(),
            'courses_interval_minutes' => $this->coursesIntervalMinutes(),
            'last_course_sync_at' => $account?->last_course_sync_at?->toISOString(),
        ];
    }

    /**
     * Switching off drops the stored credentials at once; switching on takes
     * effect with the next online login, which stores them again.
     */
    public function setEnabled(string $ownerHash, bool $enabled): void
    {
        $account = $this->offlineAccess->findForOwner($ownerHash);

        if ($account === null) {
            return;
        }

        $account->forceFill($enabled
            ? ['background_sync_disabled_at' => null]
            : ['background_sync_disabled_at' => now(), 'credentials' => null, 'credentials_rejected_at' => null]
        )->save();
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
        return max(1, (int) config('portal.background_sync.credential_days', 30));
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
     *
     * @return PortalOfflineAccount::SYNC_*
     */
    public function syncAccount(PortalOfflineAccount $account): string
    {
        if (! $this->enabled() || ! $account->hasUsableCredentials($this->credentialDays())) {
            return PortalOfflineAccount::SYNC_FAILED;
        }

        $credentials = $account->credentials ?? [];
        $username = (string) ($credentials['username'] ?? '');
        $password = (string) ($credentials['password'] ?? '');

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
        } catch (PortalAuthenticationException) {
            $account->forceFill(['credentials_rejected_at' => now()])->save();

            return $this->record($account, PortalOfflineAccount::SYNC_REJECTED, 'Portal menolak password tersimpan; masuk lagi di MyCIC untuk memperbaruinya.');
        } catch (PortalConnectionException $exception) {
            return $this->record($account, PortalOfflineAccount::SYNC_UNREACHABLE, $exception->getMessage());
        } catch (\Throwable $throwable) {
            return $this->record($account, PortalOfflineAccount::SYNC_FAILED, $throwable->getMessage());
        }

        try {
            $this->offlineAccess->refreshUserInfo($username, $session['user_info']);
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
     * instead of waiting a whole course interval.
     *
     * @return array{0: PortalOfflineAccount::SYNC_*, 1: string|null}
     */
    private function syncCourses(string $sessionToken, PortalOfflineAccount $account): array
    {
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
            try {
                $result = $this->courseArchiver->archiveClass($sessionToken, $classParams);
                $archived++;
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

        if ($status !== PortalOfflineAccount::SYNC_FAILED) {
            $account->forceFill(['last_course_sync_at' => now()])->save();
        }

        Log::info('Background archive sync crawled the classes', [
            'domain' => 'campus_session',
            'event' => 'background_sync_courses_'.$status,
            'component' => self::class,
            'owner_hash' => $account->owner_hash,
            'classes' => count($classes),
            'archived' => $archived,
        ]);

        return [$status, $errors === [] ? null : 'materi kelas: '.implode('; ', $errors)];
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

    private function isExpiredSessionError(?string $error): bool
    {
        return is_string($error) && str_starts_with($error, self::EXPIRED_SESSION_PREFIX);
    }

    /**
     * @param  PortalOfflineAccount::SYNC_*  $status
     * @return PortalOfflineAccount::SYNC_*
     */
    private function record(PortalOfflineAccount $account, string $status, ?string $error): string
    {
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
        ]);

        return $status;
    }
}
