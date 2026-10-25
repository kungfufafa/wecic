import * as cacheRules from '../lib/cache.js';
import * as offlineRules from '../lib/offline.js';

/**
 * Auth and the campus session: the session check behind every page, the
 * identity shown in the header, the device copies that belong to one student
 * (auth snapshot, cache owner), Keluar, and the offline-session banner.
 *
 * Methods of PortalApp (resources/js/app.js mixes them in): `this` is the
 * app, and every field they use is set in its constructor.
 */
export class SessionFeature {
    /**
     * `background`: the app checks on its own (a timer, a realtime event), so
     * the check does not count as the student's activity.
     */
    async ensureAuthenticated({ force = false, background = false } = {}) {
        if (!force && this.authRequest) {
            return this.authRequest;
        }

        this.authRequest = this.performAuthenticationCheck({ background });
        const result = await this.authRequest;

        if (result || force) {
            return result;
        }

        this.authRequest = null;

        return result;
    }

    async performAuthenticationCheck({ background = false } = {}) {
        try {
            const payload = await this.request('/api/auth/session', { background });

            if (!payload) {
                return null;
            }

            this.storeAuthSnapshot(payload);
            this.hydrateUser(payload);

            if (payload?.data?.offline && payload.data.offline_reconnect) {
                this.setPortalOfflineReconnect(payload.data.offline_reconnect);
            }

            return payload;
        } catch (error) {
            if (error.status === 401 || error.status === 419) {
                this.showNotice(error.payload?.message || error.message, 'error');

                return null;
            }

            // The server checked this session when it served the page. A check
            // that could not finish (offline, slow, a 5xx or a proxy error) must
            // not leave the page unloaded: data requests still report their own
            // errors and send an ended session to the login page.
            const authSnapshot = this.getAuthSnapshot();

            if (authSnapshot) {
                this.hydrateUser(authSnapshot);
            }

            this.setSyncStatus(
                error.isOffline
                    ? 'Offline. Identitas dan data terakhir tetap tersedia.'
                    : 'Sesi belum bisa diperiksa ulang. Data tetap dimuat bila server siap.',
                'warning'
            );

            return authSnapshot || { status: 'unverified', data: null };
        }
    }

    hydrateUser(payload) {
        const userInfo = payload?.data?.user_info || payload?.user_info || {};
        const userIdentity = userInfo.student_id || userInfo.nim || userInfo.username || '';
        const rawName = userInfo.name || userInfo.nama || userInfo.student_name || userIdentity;
        const userName = rawName || 'Mahasiswa';

        document.querySelectorAll('[data-user-identity]').forEach((element) => {
            element.textContent = userIdentity || 'Mahasiswa';

            if (!/(?:sm|md|lg|xl|2xl):(?:block|flex|inline|inline-flex|grid)/.test(element.className)) {
                element.classList.toggle('hidden', userIdentity === '');
            }
        });

        document.querySelectorAll('[data-user-name]').forEach((element) => {
            element.textContent = userName;
            element.classList.remove('hidden');
        });

        const initial = (userName.trim()[0] || 'M').toUpperCase();
        document.querySelectorAll('[data-user-avatar]').forEach((element) => {
            if (!element.querySelector('img')) {
                element.textContent = initial;
            }
        });
    }

    handleLoginSuccess(payload) {
        // A login never inherits what an earlier session left on this device:
        // the portal's user_info is often empty, so identities cannot be compared.
        this.clearCachedResponses();
        this.clearProtectedPageCache();
        this.storeAuthSnapshot(payload);
        this.markDeviceCacheCleared();
        this.hydrateUser(payload);
    }

    /**
     * The server shows the login page only when no session is valid, so the
     * device copies of the session that ended (it may have idled out without
     * Keluar) are dropped. A copy of this page opened offline keeps them.
     */
    forgetEndedSession() {
        if (!navigator.onLine) {
            return;
        }

        try {
            this.clearOfflineData();
            this.markDeviceCacheCleared();
        } catch (error) {
            // Storage blocked: nothing is kept on this device anyway.
        }
    }

    /**
     * Nothing from an earlier student is left on the device: the next
     * signed-in page claims it without clearing, so it keeps the auth snapshot
     * of the login and the copy of itself the service worker just stored.
     */
    markDeviceCacheCleared() {
        try {
            localStorage.setItem(this.cacheOwnerKey, this.clearedCacheOwner);
        } catch (error) {
            // Storage blocked: the next page does not see the mark and clears again.
        }
    }

    /**
     * Device copies belong to one student. The layout names the owner of the
     * signed-in session, and copies left by anyone else (a shared lab PC where
     * the previous student never pressed Keluar) are dropped before a page can
     * render them. A device with no owner yet (copies kept before owners were
     * recorded) counts as anyone else; one just cleared for a login does not.
     * Runs when the bundle loads, ahead of the pages' scripts.
     */
    isolateDeviceCache() {
        const owner = document.body?.dataset.cacheOwner || '';

        if (owner === '') {
            return;
        }

        try {
            const claim = cacheRules.deviceCacheClaim(owner, localStorage.getItem(this.cacheOwnerKey), this.clearedCacheOwner);

            if (claim.clear) {
                this.clearOfflineData();
            }

            if (claim.store) {
                localStorage.setItem(this.cacheOwnerKey, owner);
            }
        } catch (error) {
            // Storage blocked: nothing from another student can be read either.
        }
    }

    /**
     * A page restored from the back/forward cache comes back as it was
     * rendered, without asking the server. After Keluar, or once another
     * student signed in, it is hidden and reloaded so the server decides.
     */
    bindHistoryRestore() {
        window.addEventListener('pageshow', (event) => {
            if (!event.persisted || document.body.dataset.auth !== 'required') {
                return;
            }

            let sessionEnded = true;

            try {
                const owner = document.body.dataset.cacheOwner || '';

                sessionEnded = !this.getAuthSnapshot()
                    || (owner !== '' && localStorage.getItem(this.cacheOwnerKey) !== owner);
            } catch (error) {
                sessionEnded = true;
            }

            if (sessionEnded) {
                document.body.hidden = true;
                window.location.reload();

                return;
            }

            if (navigator.onLine) {
                void this.ensureAuthenticated({ force: true });
            }
        });
    }

    bindLogout() {
        document.querySelectorAll('[data-logout]').forEach((button) => {
            button.addEventListener('click', async (event) => {
                event.preventDefault();

                // This tab finishes the logout itself; its own "logged_out"
                // notice must not navigate away mid-request.
                this.isLoggingOut = true;
                this.setSyncStatus('Keluar dari akun... portal kampus sedang menutup sesi.', 'info');

                let loggedOut = false;

                await this.withSubmitLoading(button, async () => {
                    try {
                        // The server closes the portal session with up to two
                        // 10 s calls; the wait covers them.
                        const payload = await this.request('/api/auth/logout', {
                            method: 'POST',
                            json: {},
                            redirectOnAuth: false,
                            timeout: 45000,
                        });

                        this.storeNotice(payload?.message || 'Kamu sudah keluar dari akun.', 'success');
                        loggedOut = true;
                    } catch (error) {
                        // 401/419: the app session is already gone (expired, or
                        // ended in another tab), so nothing is left to close.
                        if (error.status === 401 || error.status === 419) {
                            this.storeNotice('Kamu sudah keluar dari akun.', 'success');
                            loggedOut = true;

                            return;
                        }

                        // Rate limit, server error, timeout or offline: the
                        // session is still open, so the student must not be
                        // told otherwise on a shared device.
                        this.showNotice(`Belum berhasil keluar. ${this.describeError(error, 'Coba lagi sebentar lagi.')}`, 'error');
                    }
                });

                if (!loggedOut) {
                    this.isLoggingOut = false;
                    this.setSyncStatus('Belum berhasil keluar. Sesi masih aktif di perangkat ini.', 'warning');

                    return;
                }

                this.disconnectRealtime();
                this.clearOfflineData();
                window.location.href = '/login';
            });
        });
    }

    handleSessionNotice(payload) {
        if (this.isLoggingOut && payload?.status === 'logged_out') {
            return;
        }

        const tone = this.toneFromUrgency(payload?.urgency);
        const message = payload?.message || 'Status sesi portal berubah.';

        this.showNotice(message, tone);
        this.setSyncStatus(message, tone);

        if (payload?.status === 'offline_session_resumed') {
            this.leavePortalOfflineMode();

            return;
        }

        if (payload?.status === 'offline_resume_unavailable') {
            this.setPortalOfflineReconnect('portal_back', payload?.message);

            return;
        }

        if (payload?.status === 'logged_out' || payload?.status === 'manual_relogin_required') {
            this.clearAuthSnapshot();
            this.disconnectRealtime();
            window.location.href = '/login';
        }
    }

    /**
     * How the offline session reconnects (`data-portal-offline-reconnect`,
     * kept current from session checks and notices), told to the student.
     */
    portalOfflineReconnectMessage() {
        return offlineRules.offlineReconnectMessage(document.body?.dataset.portalOfflineReconnect);
    }

    /**
     * Records a new reconnect mode and rewrites the offline banner's sentence,
     * with the server's own wording when a notice carried one.
     */
    setPortalOfflineReconnect(mode, message = null) {
        if (!document.body || !offlineRules.isOfflineReconnectMode(mode)) {
            return;
        }

        document.body.dataset.portalOfflineReconnect = mode;
        document.querySelectorAll('[data-portal-offline-reconnect-text]').forEach((element) => {
            element.textContent = message || offlineRules.offlineReconnectMessage(mode);
        });
    }

    /**
     * The offline session logged in again in the background: portal actions
     * open up and the pages re-read their data as the warm-up announces it.
     */
    leavePortalOfflineMode() {
        if (document.body) {
            document.body.dataset.portalOffline = '0';
        }

        document.querySelectorAll('[data-portal-offline-banner]').forEach((banner) => banner.remove());
        this.refreshOnlineRequiredState();
        // The outbox is being flushed server-side; its items report as they settle.
        void this.loadOutbox({ force: true });
        window.dispatchEvent(new CustomEvent('portal:online-resumed'));
    }

    storeAuthSnapshot(payload) {
        const previousIdentity = this.extractUserIdentity(this.getAuthSnapshot()?.data?.user_info || {});
        const nextIdentity = this.extractUserIdentity(payload?.data?.user_info || {});

        if (previousIdentity && nextIdentity && previousIdentity !== nextIdentity) {
            this.clearCachedResponses();
            this.clearProtectedPageCache();
        }

        localStorage.setItem(this.authSnapshotKey, JSON.stringify({
            cached_at: new Date().toISOString(),
            payload,
        }));
    }

    getAuthSnapshot() {
        const rawSnapshot = localStorage.getItem(this.authSnapshotKey);

        if (!rawSnapshot) {
            return null;
        }

        try {
            return JSON.parse(rawSnapshot).payload || null;
        } catch (error) {
            localStorage.removeItem(this.authSnapshotKey);

            return null;
        }
    }

    clearAuthSnapshot() {
        localStorage.removeItem(this.authSnapshotKey);
        this.clearCachedResponses();
        this.clearProtectedPageCache();
    }

    /**
     * The session ended: its device copies go, and the login page says why.
     */
    sendToLogin(message, tone = 'error') {
        this.clearAuthSnapshot();
        this.storeNotice(message, tone);
        window.location.href = '/login';
    }

    clearOfflineData() {
        this.clearAuthSnapshot();
        this.clearCachedResponses();
        this.clearProtectedPageCache();
    }

    /**
     * The session was opened from the archive while the portal was down:
     * nothing can be sent to the portal until the student logs in again.
     */
    isPortalOfflineSession() {
        return document.body?.dataset.portalOffline === '1';
    }

    refreshOnlineRequiredState() {
        const portalOffline = this.isPortalOfflineSession();

        document.querySelectorAll('[data-online-required]').forEach((element) => {
            // Attendance and uploads ([data-outbox-capable]) still work in an
            // offline session: they wait in the MyCIC outbox, not sent yet.
            const queueable = element.hasAttribute('data-outbox-capable');
            const shouldDisable = !navigator.onLine || (portalOffline && !queueable);

            if ('disabled' in element) {
                element.disabled = shouldDisable;
            }

            if (shouldDisable) {
                element.setAttribute('aria-disabled', 'true');
                element.setAttribute('title', portalOffline ? 'Tidak tersedia di mode offline' : 'Butuh koneksi internet');
            } else {
                element.removeAttribute('aria-disabled');

                if (portalOffline && queueable) {
                    element.setAttribute('title', 'Mode offline: disimpan di antrean MyCIC, belum terkirim ke portal');
                } else {
                    element.removeAttribute('title');
                }
            }
        });
    }

    extractUserIdentity(userInfo) {
        return userInfo.student_id || userInfo.nim || userInfo.username || '';
    }
}
