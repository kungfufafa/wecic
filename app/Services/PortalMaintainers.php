<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Request;

/**
 * Who may open the operator dashboards, Horizon (/horizon) and Log Viewer
 * (/log-viewer): everyone in the local environment, elsewhere only a student
 * signed in to MyCIC with a NIM listed in `portal.maintainers`. The NIM is
 * the username the campus session was opened with, and the session must be
 * one the rest of MyCIC still accepts: active, not idle-expired, and online,
 * since an offline login only proves the last password the portal accepted.
 */
class PortalMaintainers
{
    public function __construct(
        private readonly PortalSessionManager $sessions,
        private readonly PortalOfflineAccess $offlineAccess
    ) {}

    public function allows(Request $request): bool
    {
        if (app()->environment('local')) {
            return true;
        }

        $maintainers = (array) config('portal.maintainers', []);

        if ($maintainers === [] || ! $request->hasSession()) {
            return false;
        }

        $sessionToken = $request->session()->get('campus_session_token');

        if (! is_string($sessionToken) || $sessionToken === '' || ! $this->sessions->isSessionUsable($sessionToken) || $this->offlineAccess->isOfflineSession($sessionToken)) {
            return false;
        }

        $username = $this->sessions->getSession($sessionToken)['username'] ?? null;

        return is_string($username) && in_array($username, $maintainers, true);
    }
}
