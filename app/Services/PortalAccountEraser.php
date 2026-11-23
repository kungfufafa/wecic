<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ExamSubmissionRecord;
use App\Models\Notebook;
use App\Models\NotebookAiSetting;
use App\Models\PortalArchiveVersion;
use App\Models\PortalMobileSession;
use App\Models\PortalNotification;
use App\Models\PortalOfflineAccount;
use App\Models\PortalOutboxItem;
use App\Models\PortalPushToken;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes everything WeCIC keeps for the student a campus session belongs
 * to, then ends every session they have: the archive and its files, the
 * notebooks and their uploads (their review cards go with them), the Notebook AI consent and AI settings (own keys), the offline account with its password hash
 * and stored credentials (the row itself, not an opt-out stub), the
 * notifications, push tokens and outbox, the exam submission records, the
 * cached portal pages and snapshots, and the mobile app logins. The store
 * reviewer's data is shared by every reviewer login, so deleting it ends
 * only the calling session and leaves the other reviewers signed in.
 *
 * Cached pages and snapshots are keyed by a hash of their parameters, so
 * only the variants WeCIC can name are dropped: every dataset without
 * parameters and every parameter set the archive recorded. Any other
 * variant is marked stale and runs out with its cache lifetime.
 */
class PortalAccountEraser
{
    public function __construct(
        private readonly PortalDataOwner $owner,
        private readonly PortalSessionManager $sessionManager,
        private readonly PortalDataArchive $archive,
        private readonly PortalArchivedFileStore $files,
        private readonly NotebookLibrary $notebooks,
        private readonly PortalOfflineAccess $offlineAccess,
        private readonly PortalMobileSessions $mobileSessions,
        private readonly PortalSnapshotStore $snapshots,
        private readonly PortalDataCache $cache,
        private readonly PortalSnapshotDiffNotifier $diffNotifier,
        private readonly NotebookAiConsents $aiConsents,
    ) {}

    /**
     * @return array{versions: int, files: int, notebooks: int, ai_consents: int, ai_settings: int, notifications: int, outbox: int, push_tokens: int, exam_records: int, offline_accounts: int, sessions: int, mobile_sessions: int}
     */
    public function erase(string $sessionToken): array
    {
        $ownerHash = $this->owner->key($sessionToken);
        $usernames = $this->usernames($sessionToken);

        $isReviewer = $ownerHash === $this->owner->keyForUsername(PortalReviewerAccount::SESSION_USERNAME);

        $this->forgetCaches($sessionToken, $ownerHash);
        $mobileSessions = $isReviewer ? $this->mobileSessionsHolding($sessionToken) : $this->mobileSessionsOf($ownerHash);
        $sessionTokens = $isReviewer
            ? [$sessionToken]
            : array_values(array_unique([$sessionToken, ...$this->sessionManager->sessionTokensForOwner($ownerHash)]));

        $deleted = [
            'versions' => $this->archive->deleteOwner($ownerHash),
            'files' => $this->files->deleteOwner($ownerHash),
            'notebooks' => $this->deleteNotebooks($ownerHash),
            'ai_consents' => $this->aiConsents->forget($ownerHash),
            'ai_settings' => NotebookAiSetting::query()->forOwner($ownerHash)->delete(),
            'notifications' => PortalNotification::query()->forOwner($ownerHash)->delete(),
            'outbox' => $this->deleteOutbox($ownerHash),
            'push_tokens' => PortalPushToken::query()->forOwner($ownerHash)->delete(),
            'exam_records' => $usernames === [] ? 0 : ExamSubmissionRecord::query()->whereIn('username', $usernames)->delete(),
            'offline_accounts' => PortalOfflineAccount::query()->where('owner_hash', $ownerHash)->delete(),
            'sessions' => 0,
            'mobile_sessions' => 0,
        ];

        $laravelSessionIds = [];

        foreach ($sessionTokens as $token) {
            $laravelSessionId = $this->sessionManager->laravelSessionId($token);

            if ($laravelSessionId !== null) {
                $laravelSessionIds[] = $laravelSessionId;
            }

            $this->sessionManager->destroySession($token);
            $this->offlineAccess->endOfflineSession($token);
            $deleted['sessions']++;
        }

        $mobileSessions = $mobileSessions->merge(
            PortalMobileSession::query()->whereIn('session_id', $laravelSessionIds)->get()
        )->unique('id');

        foreach ($mobileSessions as $mobileSession) {
            $laravelSessionIds[] = $mobileSession->session_id;
            $this->mobileSessions->revoke($mobileSession, withPushTokens: true);
            $deleted['mobile_sessions']++;
        }

        $this->destroyLaravelSessions(array_values(array_unique($laravelSessionIds)));

        return $deleted;
    }

    /**
     * The usernames the student's exam submission records are stored under
     * (the one their session was opened with).
     *
     * @return list<string>
     */
    private function usernames(string $sessionToken): array
    {
        $username = $this->sessionManager->getSession($sessionToken)['username'] ?? null;

        if (! is_string($username) || trim($username) === '') {
            return [];
        }

        return array_values(array_unique([$username, trim($username), strtolower(trim($username))]));
    }

    private function forgetCaches(string $sessionToken, string $ownerHash): void
    {
        $datasets = array_keys((array) config('portal.datasets', []));
        $resources = array_keys((array) config('portal.cache.resources', []));
        $paramSets = [[]];

        PortalArchiveVersion::query()->forOwner($ownerHash)->get(['dataset', 'params'])
            ->each(function (PortalArchiveVersion $version) use (&$datasets, &$paramSets): void {
                $datasets[] = $version->dataset;
                $paramSets[PortalDataCache::paramsHash($version->params ?? [])] = $version->params ?? [];
            });

        $datasets = array_values(array_unique($datasets));

        foreach ($paramSets as $params) {
            foreach ($datasets as $dataset) {
                $this->snapshots->forget($sessionToken, $dataset, $params);
                $this->diffNotifier->forgetBaseline($sessionToken, $dataset, $params);
            }

            foreach ($resources as $resource) {
                $this->cache->forget($sessionToken, $resource, $params);
            }
        }

        $this->snapshots->markStale($sessionToken, ...$datasets);
        $this->cache->invalidate($sessionToken, ...$resources);
    }

    private function deleteNotebooks(string $ownerHash): int
    {
        $notebooks = Notebook::query()->forOwner($ownerHash)->with('sources:id,notebook_id,disk_path')->get();

        foreach ($notebooks as $notebook) {
            foreach ($notebook->sources as $source) {
                if ($source->disk_path !== null) {
                    Storage::disk((string) config('notebook.uploads.disk', 'local'))->delete($source->disk_path);
                }
            }

            $this->notebooks->deleteNotebook($notebook);
        }

        return $notebooks->count();
    }

    private function deleteOutbox(string $ownerHash): int
    {
        $items = PortalOutboxItem::query()->forOwner($ownerHash)->get();

        foreach ($items as $item) {
            if ($item->file_path !== null) {
                Storage::delete($item->file_path);
            }
        }

        return PortalOutboxItem::query()->whereKey($items->modelKeys())->delete();
    }

    /**
     * The mobile app logins whose session belongs to the student: the ones
     * that registered the student's push tokens, and any other whose stored
     * session holds a campus session of theirs. Read before the campus
     * sessions end, while their tokens still name the student.
     *
     * @return \Illuminate\Support\Collection<int, PortalMobileSession>
     */
    private function mobileSessionsOf(string $ownerHash): \Illuminate\Support\Collection
    {
        $found = PortalMobileSession::query()
            ->whereIn('id', PortalPushToken::query()->forOwner($ownerHash)->whereNotNull('portal_mobile_session_id')->select('portal_mobile_session_id'))
            ->get();

        PortalMobileSession::query()->whereNotIn('id', $found->modelKeys())->chunkById(200, function ($mobileSessions) use ($ownerHash, $found): void {
            foreach ($mobileSessions as $mobileSession) {
                $campusSessionToken = $this->storedSession($mobileSession->session_id)?->get('campus_session_token');

                if (is_string($campusSessionToken) && $campusSessionToken !== '' && $this->owner->key($campusSessionToken) === $ownerHash) {
                    $found->push($mobileSession);
                }
            }
        });

        return $found;
    }

    /**
     * The mobile app logins whose stored session holds this one campus
     * session: the only ones a store reviewer's deletion ends, since every
     * reviewer login shares one owner and the others keep reviewing.
     *
     * @return \Illuminate\Support\Collection<int, PortalMobileSession>
     */
    private function mobileSessionsHolding(string $sessionToken): \Illuminate\Support\Collection
    {
        $found = new \Illuminate\Database\Eloquent\Collection;

        PortalMobileSession::query()->chunkById(200, function ($mobileSessions) use ($sessionToken, $found): void {
            foreach ($mobileSessions as $mobileSession) {
                if ($this->storedSession($mobileSession->session_id)?->get('campus_session_token') === $sessionToken) {
                    $found->push($mobileSession);
                }
            }
        });

        return $found;
    }

    /**
     * @param  list<string>  $laravelSessionIds
     */
    private function destroyLaravelSessions(array $laravelSessionIds): void
    {
        $handler = app('session.store')->getHandler();

        foreach ($laravelSessionIds as $laravelSessionId) {
            try {
                $handler->destroy($laravelSessionId);
            } catch (\Throwable $throwable) {
                Log::warning('A session of an erased account could not be destroyed', ['error' => $throwable->getMessage()]);
            }
        }
    }

    /**
     * A stored Laravel session read on its own, without touching the one
     * the current request runs in.
     */
    private function storedSession(string $laravelSessionId): ?Session
    {
        try {
            $session = clone app('session.store');
            $session->flush();
            $session->setId($laravelSessionId);
            $session->start();

            return $session->getId() === $laravelSessionId ? $session : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
