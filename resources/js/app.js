import { createPortalEcho } from './echo';
import { csrfHeaders } from './csrf';
import jsQR from 'jsqr';

class PortalApp {
    constructor() {
        this.campusBase = document.documentElement.dataset.campusBase || window.location.origin;
        this.noticeTimeout = null;
        this.onlineState = navigator.onLine;
        this.syncHandlers = new Map();
        this.isSyncing = false;
        this.cacheNamespace = 'mycic-cache-v3';
        this.authSnapshotKey = `${this.cacheNamespace}:auth`;
        this.themeStorageKey = 'mycic-theme';
        this.protectedPageCacheName = 'mycic-pages-v2';
        this.authRequest = null;
        this.pullRefreshState = {
            active: false,
            armed: false,
            refreshing: false,
            startY: 0,
            threshold: 84,
            maxDistance: 132,
        };
        this.qrScannerState = {
            stream: null,
            rafId: null,
            input: null,
            busy: false,
            canvas: null,
            context: null,
        };
        this.echo = null;
        this.echoChannel = null;
        this.realtimeConnected = false;
        this.activeOperations = new Map();
        this.realtimeRefreshTimers = new Map();
        this.cachePolicies = [
            { pattern: /^\/api\/dashboard$/, freshFor: 30 * 60 * 1000, retainFor: 7 * 24 * 60 * 60 * 1000, label: 'ringkasan dashboard' },
            { pattern: /^\/api\/attendance\/today$/, freshFor: 15 * 60 * 1000, retainFor: 48 * 60 * 60 * 1000, label: 'shortcut hari ini' },
            { pattern: /^\/api\/assignment\/pending$/, freshFor: 20 * 60 * 1000, retainFor: 48 * 60 * 60 * 1000, label: 'tugas belum dikumpulkan' },
            { pattern: /^\/api\/profile$/, freshFor: 6 * 60 * 60 * 1000, retainFor: 14 * 24 * 60 * 60 * 1000, label: 'profil mahasiswa' },
            { pattern: /^\/api\/class(?:\?.*)?$/, freshFor: 6 * 60 * 60 * 1000, retainFor: 14 * 24 * 60 * 60 * 1000, label: 'daftar kelas' },
            { pattern: /^\/api\/lms\/class$/, freshFor: 6 * 60 * 60 * 1000, retainFor: 14 * 24 * 60 * 60 * 1000, label: 'kelas LMS' },
            { pattern: /^\/api\/schedule\/class$/, freshFor: 6 * 60 * 60 * 1000, retainFor: 14 * 24 * 60 * 60 * 1000, label: 'jadwal kelas' },
            { pattern: /^\/api\/class\/[^/?]+(?:\?.*)?$/, freshFor: 6 * 60 * 60 * 1000, retainFor: 14 * 24 * 60 * 60 * 1000, label: 'detail kelas' },
            { pattern: /^\/api\/meeting\/[^/?]+(?:\?.*)?$/, freshFor: 6 * 60 * 60 * 1000, retainFor: 14 * 24 * 60 * 60 * 1000, label: 'detail meeting' },
            { pattern: /^\/api\/exam$/, freshFor: 2 * 60 * 60 * 1000, retainFor: 7 * 24 * 60 * 60 * 1000, label: 'daftar ujian' },
            { pattern: /^\/api\/exam\/[^/?]+$/, freshFor: 2 * 60 * 60 * 1000, retainFor: 7 * 24 * 60 * 60 * 1000, label: 'detail ujian' },
        ];
    }

    init() {
        this.restoreNotice();
        this.bindLogout();
        this.bindConnectionListeners();
        this.bindSyncRefreshButton();
        this.bindPullToRefresh();
        this.updateConnectionState(navigator.onLine, { announce: false });
        this.pruneExpiredCacheEntries();
        this.refreshOnlineRequiredState();
        this.bindQrScannerUi();
        this.bindAccountMenu();
        this.bindThemeToggle();
        this.bindQuickQrAction();
        this.bindRealtimeTestHook();

        if (document.body.dataset.auth !== 'guest') {
            const authSnapshot = this.getAuthSnapshot();

            if (authSnapshot) {
                this.hydrateUser(authSnapshot);
            }

            this.initRealtime();
        }
    }

    bindConnectionListeners() {
        window.addEventListener('online', () => {
            this.updateConnectionState(true);
        });

        window.addEventListener('offline', () => {
            this.updateConnectionState(false);
        });
    }

    bindSyncRefreshButton() {
        document.querySelectorAll('[data-sync-refresh]').forEach((button) => {
            button.addEventListener('click', async () => {
                if (!this.requireOnlineAction('Sinkronisasi server butuh koneksi internet.')) {
                    return;
                }

                await this.queueBackgroundRefresh();
            });
        });
    }

    bindQrScannerUi() {
        const modal = document.querySelector('[data-qr-scanner-modal]');

        if (!modal) {
            return;
        }

        this.qrScannerState.modal = {
            show: () => {
                modal.classList.remove('hidden');
                modal.setAttribute('aria-hidden', 'false');
            },
            hide: () => {
                modal.classList.add('hidden');
                modal.setAttribute('aria-hidden', 'true');
            },
        };

        modal.querySelector('[data-qr-scanner-close]')?.addEventListener('click', () => {
            void this.closeQrScanner();
        });

        document.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape' || modal.classList.contains('hidden')) {
                return;
            }

            void this.closeQrScanner();
        });
    }

    bindThemeToggle() {
        this.refreshThemeToggleState();

        document.querySelectorAll('[data-theme-toggle]').forEach((button) => {
            button.addEventListener('click', () => {
                const nextTheme = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';

                this.setTheme(nextTheme);
            });
        });

        if (window.matchMedia) {
            window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (event) => {
                const storedTheme = localStorage.getItem(this.themeStorageKey);

                if (storedTheme !== 'dark' && storedTheme !== 'light') {
                    this.setTheme(event.matches ? 'dark' : 'light', { persist: false });
                }
            });
        }
    }

    setTheme(theme, { persist = true } = {}) {
        document.documentElement.dataset.theme = theme;

        if (persist) {
            localStorage.setItem(this.themeStorageKey, theme);
        }

        this.updateThemeColorMeta();
        this.refreshThemeToggleState();
    }

    updateThemeColorMeta() {
        const meta = document.querySelector('meta[name="theme-color"]');

        if (meta) {
            meta.setAttribute('content', document.documentElement.dataset.theme === 'dark' ? '#091019' : '#0352A1');
        }
    }

    refreshThemeToggleState() {
        const isDark = document.documentElement.dataset.theme === 'dark';

        document.querySelectorAll('[data-theme-toggle]').forEach((button) => {
            button.setAttribute('aria-pressed', isDark ? 'true' : 'false');
            button.setAttribute('aria-label', isDark ? 'Aktifkan mode terang' : 'Aktifkan mode gelap');
        });
    }

    bindQuickQrAction() {
        document.querySelectorAll('[data-qr-quick]').forEach((button) => {
            button.addEventListener('click', async () => {
                const input = document.querySelector('[data-field="kode"]') || document.querySelector('#attendance-code');

                if (!input) {
                    this.showNotice('Tidak ada sesi absensi aktif di halaman ini. Buka detail kelas saat sesi absensi dibuka.', 'warning');

                    return;
                }

                await this.openQrScanner(input);
            });
        });
    }

    bindAccountMenu() {
        document.querySelectorAll('[data-account-menu]').forEach((menu) => {
            const trigger = menu.querySelector('[data-account-menu-trigger]');
            const panel = menu.querySelector('[data-account-menu-panel]');

            if (!trigger || !panel) {
                return;
            }

            trigger.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopPropagation();

                const isOpen = !panel.classList.contains('hidden');

                document.querySelectorAll('[data-account-menu-panel]').forEach((otherPanel) => {
                    otherPanel.classList.add('hidden');
                });

                document.querySelectorAll('[data-account-menu-trigger]').forEach((otherTrigger) => {
                    otherTrigger.setAttribute('aria-expanded', 'false');
                });

                if (isOpen) {
                    trigger.setAttribute('aria-expanded', 'false');

                    return;
                }

                panel.classList.remove('hidden');
                trigger.setAttribute('aria-expanded', 'true');
            });

            document.addEventListener('click', (event) => {
                if (menu.contains(event.target)) {
                    return;
                }

                panel.classList.add('hidden');
                trigger.setAttribute('aria-expanded', 'false');
            });
        });
    }

    bindPullToRefresh() {
        if (document.body.dataset.auth !== 'required') {
            return;
        }

        window.addEventListener('touchstart', (event) => {
            if (this.isSyncing || this.pullRefreshState.refreshing || window.scrollY > 0) {
                return;
            }

            if (this.isInteractiveTarget(event.target)) {
                return;
            }

            const touch = event.touches[0];

            if (!touch) {
                return;
            }

            this.pullRefreshState.active = true;
            this.pullRefreshState.armed = false;
            this.pullRefreshState.startY = touch.clientY;
            this.setPullIndicator({
                distance: 0,
                armed: false,
                refreshing: false,
            });
        }, { passive: true });

        window.addEventListener('touchmove', (event) => {
            if (!this.pullRefreshState.active || this.pullRefreshState.refreshing) {
                return;
            }

            const touch = event.touches[0];

            if (!touch) {
                return;
            }

            const delta = touch.clientY - this.pullRefreshState.startY;

            if (delta <= 0) {
                this.setPullIndicator({
                    distance: 0,
                    armed: false,
                    refreshing: false,
                });

                return;
            }

            if (window.scrollY > 0) {
                this.resetPullIndicator();

                return;
            }

            event.preventDefault();

            const distance = Math.min(this.pullRefreshState.maxDistance, delta * 0.55);
            const armed = delta >= this.pullRefreshState.threshold;
            this.pullRefreshState.armed = armed;

            this.setPullIndicator({
                distance,
                armed,
                refreshing: false,
            });
        }, { passive: false });

        const finishPullGesture = async () => {
            if (!this.pullRefreshState.active) {
                return;
            }

            const shouldRefresh = this.pullRefreshState.armed;
            this.pullRefreshState.active = false;
            this.pullRefreshState.armed = false;

            if (!shouldRefresh) {
                this.resetPullIndicator();

                return;
            }

            if (!navigator.onLine) {
                this.resetPullIndicator();
                this.showNotice('Sedang offline. Menampilkan data terakhir yang tersimpan.', 'warning');

                return;
            }

            this.pullRefreshState.refreshing = true;
            this.setPullIndicator({
                distance: this.pullRefreshState.threshold,
                armed: true,
                refreshing: true,
            });

            await this.runSyncHandlers({ force: true });

            this.pullRefreshState.refreshing = false;
            this.resetPullIndicator();
        };

        window.addEventListener('touchend', () => {
            void finishPullGesture();
        }, { passive: true });

        window.addEventListener('touchcancel', () => {
            this.pullRefreshState.active = false;
            this.pullRefreshState.armed = false;
            this.pullRefreshState.refreshing = false;
            this.resetPullIndicator();
        }, { passive: true });
    }

    updateConnectionState(isOnline, { announce = true } = {}) {
        const previousState = this.onlineState;
        this.onlineState = isOnline;

        document.querySelectorAll('[data-connection-chip]').forEach((element) => {
            element.className = isOnline ? 'mycic-chip-online' : 'mycic-chip-offline';
        });

        document.querySelectorAll('[data-connection-label]').forEach((element) => {
            element.textContent = isOnline ? 'Online' : 'Offline';
        });

        this.refreshOnlineRequiredState();
        this.setSyncRefreshButtonState();

        if (!isOnline) {
            this.disconnectRealtime();
            this.setSyncStatus('Data terakhir dipakai bila tersedia.', 'warning');

            if (announce && previousState !== false) {
                this.showNotice('Koneksi internet terputus. Menampilkan data terakhir yang tersimpan.', 'warning');
            }

            return;
        }

        if (previousState === false) {
            this.setSyncStatus('Koneksi kembali. Memuat ulang data aplikasi...', 'info');

            if (announce) {
                this.showNotice('Koneksi kembali. Data aplikasi sedang dimuat ulang.', 'info');
            }

            this.initRealtime();
            void this.runSyncHandlers();

            return;
        }

        if (document.body.dataset.auth !== 'guest') {
            this.initRealtime();
        }

        if (!document.querySelector('[data-sync-status]')?.textContent?.trim()) {
            this.setSyncStatus(this.defaultSyncStatusMessage(), 'info');
        }
    }

    registerSyncHandler(key, handler) {
        if (!key || typeof handler !== 'function') {
            return;
        }

        this.syncHandlers.set(key, handler);
    }

    async runSyncHandlers({ force = false } = {}) {
        if (this.isSyncing || !navigator.onLine || this.syncHandlers.size === 0) {
            return;
        }

        this.isSyncing = true;
        this.setSyncRefreshButtonState({ syncing: true, force });
        this.setSyncStatus(force ? 'Memeriksa server kampus...' : 'Memuat ulang data aplikasi...', 'info');

        let refreshedCount = 0;

        for (const handler of this.syncHandlers.values()) {
            try {
                await handler({ force });
                refreshedCount += 1;
            } catch (error) {
                console.error('Sync handler failed', error);
            }
        }

        this.isSyncing = false;
        this.setSyncRefreshButtonState();

        if (refreshedCount === 0) {
            this.setSyncStatus(force ? 'Belum ada data yang bisa disegarkan dari server.' : 'Tidak ada pembaruan halaman.', 'info');
        }
    }

    async request(url, options = {}) {
        const method = String(options.method || 'GET').toUpperCase();
        const requestUrl = this.buildRequestUrl(url, options);
        const cacheUrl = this.normalizeCacheUrl(requestUrl);
        const cachePolicy = this.resolveCachePolicy(cacheUrl, method, options);
        const cachedResponse = cachePolicy ? this.readCachedResponse(cacheUrl) : null;

        if (!navigator.onLine) {
            if (cachedResponse) {
                this.setSyncStatus(this.buildOfflineCacheMessage(cachePolicy.label, cachedResponse.isStale), 'warning');

                return this.decorateCachedPayload(cachedResponse, 'offline');
            }

            throw this.createOfflineError(
                method === 'GET'
                    ? 'Koneksi internet tidak tersedia dan data offline belum tersimpan.'
                    : 'Kamu sedang offline. Sambungkan internet untuk mengirim perubahan.'
            );
        }

        const config = {
            method,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                ...csrfHeaders(),
                ...(options.headers || {}),
            },
        };

        if (options.json !== undefined) {
            config.headers['Content-Type'] = 'application/json';
            config.body = JSON.stringify(options.json);
        }

        if (options.formData instanceof FormData) {
            config.body = options.formData;
        }

        try {
            const response = await fetch(requestUrl.toString(), config);
            const contentType = response.headers.get('content-type') || '';
            const payload = contentType.includes('application/json')
                ? await response.json()
                : null;

            if (response.status === 401 && options.redirectOnAuth !== false && document.body.dataset.auth === 'required') {
                this.clearAuthSnapshot();
                this.storeNotice(payload?.message || 'Sesi kampus sudah berakhir.', 'error');
                window.location.href = '/login';

                return null;
            }

            if (!response.ok) {
                const error = new Error(payload?.message || `Request failed with status ${response.status}.`);
                error.status = response.status;
                error.payload = payload;
                throw error;
            }

            if (cachePolicy && payload && typeof payload === 'object') {
                this.writeCachedResponse(cacheUrl, payload, cachePolicy);
            }

            return payload;
        } catch (error) {
            if (cachePolicy && cachedResponse && this.isLikelyNetworkError(error)) {
                this.updateConnectionState(false, { announce: false });
                this.setSyncStatus(this.buildOfflineCacheMessage(cachePolicy.label, cachedResponse.isStale), 'warning');

                return this.decorateCachedPayload(cachedResponse, 'fallback');
            }

            if (this.isLikelyNetworkError(error)) {
                this.updateConnectionState(false, { announce: false });
                throw this.createOfflineError(
                    method === 'GET'
                        ? 'Koneksi internet terputus saat memuat data.'
                        : 'Koneksi internet terputus saat mengirim perubahan.'
                );
            }

            throw error;
        }
    }

    async ensureAuthenticated({ force = false } = {}) {
        if (!force && this.authRequest) {
            return this.authRequest;
        }

        this.authRequest = this.performAuthenticationCheck();
        const result = await this.authRequest;

        if (result || force) {
            return result;
        }

        this.authRequest = null;

        return result;
    }

    async performAuthenticationCheck() {
        try {
            const payload = await this.request('/api/auth/session');

            if (!payload) {
                return null;
            }

            this.storeAuthSnapshot(payload);
            this.hydrateUser(payload);

            return payload;
        } catch (error) {
            const authSnapshot = this.getAuthSnapshot();

            if (error.isOffline && authSnapshot) {
                this.hydrateUser(authSnapshot);
                this.setSyncStatus('Offline. Identitas dan data terakhir tetap tersedia.', 'warning');

                return authSnapshot;
            }

            this.showNotice(error.payload?.message || error.message, error.isOffline ? 'warning' : 'error');

            return null;
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
        document.querySelectorAll('[data-user-avatar], .mycic-avatar').forEach((element) => {
            if (!element.querySelector('img')) {
                element.textContent = initial;
            }
        });
    }

    handleLoginSuccess(payload) {
        this.storeAuthSnapshot(payload);
        this.hydrateUser(payload);
    }

    bindLogout() {
        document.querySelectorAll('[data-logout]').forEach((button) => {
            button.addEventListener('click', async (event) => {
                event.preventDefault();

                button.setAttribute('disabled', 'disabled');

                try {
                    const payload = await this.request('/api/auth/logout', {
                        method: 'POST',
                        json: {},
                        redirectOnAuth: false,
                    });

                    this.storeNotice(payload?.message || 'Logout berhasil.', 'success');
                } catch (error) {
                    this.storeNotice(error.payload?.message || 'Logout diproses lokal.', 'warning');
                }

                this.disconnectRealtime();
                this.clearOfflineData();
                window.location.href = '/login';
            });
        });
    }

    bindRealtimeTestHook() {
        window.addEventListener('portal:test-session-notice', (event) => {
            this.handleSessionNotice(event.detail || {});
        });
    }

    initRealtime() {
        if (!navigator.onLine || document.body.dataset.auth === 'guest' || this.echo) {
            return;
        }

        this.echo = createPortalEcho();

        if (!this.echo) {
            this.setRealtimeStatus('Real-time tidak dikonfigurasi.', 'warning');

            return;
        }

        const sessionId = document.documentElement.dataset.sessionId;

        if (!sessionId) {
            this.setRealtimeStatus('Menunggu sesi real-time...', 'warning');

            return;
        }

        this.echoChannel = this.echo.private(`portal.session.${sessionId}`)
            .listen('.portal.sync.status', (payload) => this.handleRealtimePayload(payload))
            .listen('.portal.data.invalidated', (payload) => this.handleRealtimePayload(payload))
            .listen('.portal.upload.progress', (payload) => this.handleUploadProgress(payload))
            .listen('.portal.session.notice', (payload) => this.handleSessionNotice(payload))
            .listen('.portal.notification', (payload) => this.handlePortalNotification(payload));

        this.realtimeConnected = true;
        this.setRealtimeStatus('Real-time aktif.', 'success');
    }

    disconnectRealtime() {
        if (this.echo) {
            this.echo.disconnect();
        }

        this.echo = null;
        this.echoChannel = null;
        this.realtimeConnected = false;
        this.setRealtimeStatus('Real-time terputus.', 'warning');
    }

    async queueBackgroundRefresh() {
        const domains = this.refreshDomainsForCurrentPage();

        this.isSyncing = true;
        this.setSyncRefreshButtonState({ syncing: true, force: true });
        this.setSyncStatus('Menghubungkan ke portal kampus...', 'info');

        try {
            await this.request('/api/refresh', {
                method: 'POST',
                json: { domains },
            });
            this.setSyncStatus('Refresh data berjalan di background.', 'info');
        } catch (error) {
            this.setSyncStatus('Gagal memulai refresh background.', 'error');
            this.showNotice(error.payload?.message || error.message, 'error');
            await this.runSyncHandlers({ force: true });
        } finally {
            this.isSyncing = false;
            this.setSyncRefreshButtonState();
        }
    }

    refreshDomainsForCurrentPage() {
        const page = document.body.dataset.page || 'app';

        const pageDomains = {
            dashboard: ['dashboard', 'assignments'],
            'class-index': ['classes'],
            'class-show': ['classes', 'assignments'],
            'meeting-show': ['assignments'],
            'exam-index': ['exams'],
            'exam-show': ['exams'],
            profile: ['profile'],
        };

        return pageDomains[page] || ['dashboard'];
    }

    handleRealtimePayload(payload) {
        const cacheKeys = Array.isArray(payload?.cache_keys) ? payload.cache_keys : [];
        const syncKeys = Array.isArray(payload?.sync_keys) ? payload.sync_keys : [];

        cacheKeys.forEach((cacheKey) => this.clearCachedResponse(cacheKey));

        if (payload?.message) {
            this.setSyncStatus(payload.message, this.toneFromUrgency(payload.urgency));
        }

        syncKeys.forEach((syncKey) => {
            window.clearTimeout(this.realtimeRefreshTimers.get(syncKey));
            this.realtimeRefreshTimers.set(syncKey, window.setTimeout(() => {
                const handler = this.syncHandlers.get(syncKey);

                if (handler) {
                    void handler({ force: true });
                }
            }, 250));
        });
    }

    handleSessionNotice(payload) {
        const tone = this.toneFromUrgency(payload?.urgency);
        const message = payload?.message || 'Status sesi portal berubah.';

        this.showNotice(message, tone);
        this.setSyncStatus(message, tone);

        if (payload?.status === 'logged_out' || payload?.status === 'manual_relogin_required') {
            this.clearAuthSnapshot();
            this.disconnectRealtime();
            window.location.href = '/login';
        }
    }

    handlePortalNotification(payload) {
        const tone = this.toneFromUrgency(payload?.urgency);
        this.showNotice(payload?.message || payload?.title || 'Ada pembaruan portal.', tone);
        this.handleRealtimePayload(payload);
    }

    handleUploadProgress(payload) {
        if (!payload?.operation_id) {
            return;
        }

        this.activeOperations.set(payload.operation_id, {
            label: this.activeOperations.get(payload.operation_id)?.label || 'Upload',
            status: payload.status,
            message: payload.message,
            detail: payload.detail,
            nextStep: payload.next_step,
            percent: payload.percent || this.percentForStage(payload.status),
        });

        this.renderOperationStatus(payload.operation_id);

        if (payload.status === 'completed') {
            this.showNotice(payload.message || 'Upload selesai.', 'success');
            this.handleRealtimePayload(payload);
        }

        if (payload.status === 'failed') {
            const failureMessage = payload.next_step
                ? `${payload.message} ${payload.next_step}`
                : payload.message;
            this.showNotice(failureMessage, 'error');
        }
    }

    trackOperation(operationId, label) {
        this.activeOperations.set(operationId, {
            label,
            status: 'queued',
            message: `${label} masuk antrean.`,
            percent: 0,
        });
        this.renderOperationStatus(operationId);
    }

    percentForStage(stage) {
        return {
            connecting: 10,
            file_received: 20,
            portal_uploading: 55,
            validation_succeeded: 80,
            submission_saved: 100,
            completed: 100,
            failed: 100,
        }[stage] || 10;
    }

    renderOperationStatus(operationId) {
        const operation = this.activeOperations.get(operationId);
        const host = document.querySelector('[data-operation-status]');

        if (!operation || !host) {
            return;
        }

        host.innerHTML = `
            <div class="mycic-alert-info">
                <div class="flex items-center justify-between gap-3">
                    <span>${this.escapeHtml(operation.message)}</span>
                    <span>${operation.percent}%</span>
                </div>
                <div class="mt-2 h-2 overflow-hidden rounded-full bg-surface-muted">
                    <div class="h-full rounded-full bg-brand" style="width: ${operation.percent}%"></div>
                </div>
                ${operation.detail ? `<p class="mt-2 text-caption text-ink-subtle">${this.escapeHtml(operation.detail)}</p>` : ''}
                ${operation.nextStep ? `<p class="mt-2 text-caption font-medium text-ink">${this.escapeHtml(operation.nextStep)}</p>` : ''}
            </div>
        `;
    }

    toneFromUrgency(urgency = 'info') {
        return {
            critical: 'error',
            error: 'error',
            warning: 'warning',
            success: 'success',
            info: 'info',
        }[urgency] || 'info';
    }

    setRealtimeStatus(message, tone = 'info') {
        document.querySelectorAll('[data-realtime-label]').forEach((element) => {
            element.textContent = message;
        });

        document.querySelectorAll('[data-realtime-chip]').forEach((element) => {
            element.classList.remove('mycic-chip-online', 'mycic-chip-offline');
            element.classList.add(tone === 'success' ? 'mycic-chip-online' : 'mycic-chip-offline');
        });
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

    clearCachedResponses() {
        Object.keys(localStorage).forEach((key) => {
            if (key.startsWith(`${this.cacheNamespace}:response:`)) {
                localStorage.removeItem(key);
            }
        });
    }

    clearCachedResponse(url) {
        if (!url) {
            return;
        }

        const requestUrl = this.normalizeCacheUrl(new URL(url, window.location.origin));
        localStorage.removeItem(this.buildCacheKey(requestUrl));
    }

    clearOfflineData() {
        this.clearAuthSnapshot();
        this.clearCachedResponses();
        this.clearProtectedPageCache();
    }

    setSyncRefreshButtonState({ syncing = false, force = false } = {}) {
        document.querySelectorAll('[data-sync-refresh]').forEach((button) => {
            button.disabled = syncing || !navigator.onLine;
        });

        document.querySelectorAll('[data-sync-refresh-icon]').forEach((icon) => {
            icon.classList.toggle('hidden', syncing);
        });

        document.querySelectorAll('[data-sync-refresh-spinner]').forEach((spinner) => {
            spinner.classList.toggle('hidden', !syncing);
        });

        document.querySelectorAll('[data-sync-refresh-label]').forEach((label) => {
            label.textContent = syncing
                ? (force ? 'Memeriksa server...' : 'Memuat ulang...')
                : 'Sinkronkan server';
        });
    }

    refreshOnlineRequiredState() {
        document.querySelectorAll('[data-online-required]').forEach((element) => {
            const shouldDisable = !navigator.onLine;

            if ('disabled' in element) {
                element.disabled = shouldDisable;
            }

            if (shouldDisable) {
                element.setAttribute('aria-disabled', 'true');
                element.setAttribute('title', 'Butuh koneksi internet');
            } else {
                element.removeAttribute('aria-disabled');
                element.removeAttribute('title');
            }
        });
    }

    clearProtectedPageCache() {
        if ('caches' in window) {
            window.caches.delete(this.protectedPageCacheName).catch(() => {});
        }

        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.ready
                .then((registration) => {
                    registration.active?.postMessage({ type: 'CLEAR_AUTH_CACHES' });
                })
                .catch(() => {});
        }
    }

    extractAttendanceCode(value) {
        const normalizedValue = String(value || '').trim();

        if (normalizedValue === '') {
            return null;
        }

        const codeCandidates = Array.from(normalizedValue.matchAll(/[a-f\d]{8,64}/gi))
            .map((match) => ({
                value: match[0],
                index: match.index ?? Number.MAX_SAFE_INTEGER,
                hasHexLetters: /[a-f]/i.test(match[0]),
                isHinted: /(?:kode|unix|qr|code)[^a-f\d]{0,20}$/i.test(normalizedValue.slice(Math.max(0, (match.index ?? 0) - 20), match.index ?? 0)),
            }))
            .sort((left, right) => {
                if (left.isHinted !== right.isHinted) {
                    return left.isHinted ? -1 : 1;
                }

                if (left.hasHexLetters !== right.hasHexLetters) {
                    return left.hasHexLetters ? -1 : 1;
                }

                return left.index - right.index;
            });

        const code = codeCandidates[0]?.value;

        if (!code) {
            return null;
        }

        return code.slice(0, 8).toLowerCase();
    }

    fillAttendanceCode(input, rawValue) {
        const code = this.extractAttendanceCode(rawValue);

        if (!code) {
            this.showNotice('QR terdeteksi, tetapi tidak menemukan kode absensi yang valid.', 'warning');

            return false;
        }

        input.value = code;
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
        this.showNotice(`Kode absensi ${code} terisi otomatis.`, 'success');

        return true;
    }

    requestFormSubmit(form) {
        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();

            return;
        }

        form.dispatchEvent(new Event('submit', {
            bubbles: true,
            cancelable: true,
        }));
    }

    async withSubmitLoading(button, callback) {
        if (!button) {
            return await callback();
        }

        const originalHtml = button.innerHTML;
        const originalWidth = button.offsetWidth;
        
        button.disabled = true;
        if (originalWidth > 0) {
            button.style.minWidth = `${originalWidth}px`;
        }
        button.innerHTML = '<span class="mycic-spinner me-2" role="status" aria-hidden="true"></span>Memproses...';
        
        try {
            await callback();
        } finally {
            button.disabled = false;
            button.innerHTML = originalHtml;
            button.style.minWidth = '';
        }
    }

    getQrCanvasContext(width, height) {
        if (!this.qrScannerState.canvas || !this.qrScannerState.context) {
            this.qrScannerState.canvas = document.createElement('canvas');
            this.qrScannerState.context = this.qrScannerState.canvas.getContext('2d', {
                willReadFrequently: true,
            });
        }

        const canvas = this.qrScannerState.canvas;
        const context = this.qrScannerState.context;

        if (!context) {
            return null;
        }

        canvas.width = Math.max(1, Math.floor(width));
        canvas.height = Math.max(1, Math.floor(height));

        return { canvas, context };
    }

    decodeQrImageData(imageData) {
        try {
            return jsQR(imageData.data, imageData.width, imageData.height, {
                inversionAttempts: 'attemptBoth',
            })?.data || '';
        } catch (error) {
            return '';
        }
    }

    async decodeQrFromFile(file, input, options = {}) {
        try {
            const imageUrl = URL.createObjectURL(file);
            const image = await new Promise((resolve, reject) => {
                const candidate = new Image();
                candidate.onload = () => resolve(candidate);
                candidate.onerror = () => reject(new Error('Image load failed'));
                candidate.src = imageUrl;
            });

            const maxDimension = 1600;
            const scale = Math.min(1, maxDimension / Math.max(image.naturalWidth || image.width, image.naturalHeight || image.height));
            const width = (image.naturalWidth || image.width) * scale;
            const height = (image.naturalHeight || image.height) * scale;
            const canvasKit = this.getQrCanvasContext(width, height);

            URL.revokeObjectURL(imageUrl);

            if (!canvasKit) {
                this.showNotice('Kanvas browser tidak tersedia untuk memproses foto QR.', 'error');

                return false;
            }

            canvasKit.context.clearRect(0, 0, canvasKit.canvas.width, canvasKit.canvas.height);
            canvasKit.context.drawImage(image, 0, 0, canvasKit.canvas.width, canvasKit.canvas.height);

            const imageData = canvasKit.context.getImageData(0, 0, canvasKit.canvas.width, canvasKit.canvas.height);
            const rawValue = this.decodeQrImageData(imageData);

            if (!rawValue) {
                this.showNotice('QR tidak terbaca dari foto yang dipilih.', 'warning');

                return false;
            }

            const didFill = this.fillAttendanceCode(input, rawValue);

            if (didFill) {
                options.onDetected?.(input.value, {
                    source: 'file',
                    input,
                });
            }

            return didFill;
        } catch (error) {
            this.showNotice('Foto QR gagal diproses.', 'error');

            return false;
        }
    }

    async openQrScanner(input, options = {}) {
        if (!navigator.mediaDevices?.getUserMedia) {
            this.showNotice('Kamera tidak tersedia di perangkat ini.', 'warning');

            return;
        }

        const modal = document.querySelector('[data-qr-scanner-modal]');
        const video = modal?.querySelector('[data-qr-scanner-video]');

        if (!modal || !video) {
            return;
        }

        try {
            const stream = await navigator.mediaDevices.getUserMedia({
                video: {
                    facingMode: { ideal: 'environment' },
                },
                audio: false,
            });

            this.qrScannerState.stream = stream;
            this.qrScannerState.input = input;
            this.qrScannerState.busy = false;

            video.srcObject = stream;
            await video.play();

            this.qrScannerState.modal?.show();
            this.setQrScannerStatus('Arahkan kamera ke QR absensi untuk mengambil 8 karakter pertama kode.');

            const scanFrame = async () => {
                if (!this.qrScannerState.stream) {
                    return;
                }

                if (!this.qrScannerState.busy && video.readyState >= 2) {
                    this.qrScannerState.busy = true;

                    try {
                        const canvasKit = this.getQrCanvasContext(video.videoWidth, video.videoHeight);

                        if (!canvasKit) {
                            this.setQrScannerStatus('Kanvas browser tidak tersedia untuk scan QR.', 'warning');
                            await this.closeQrScanner();

                            return;
                        }

                        canvasKit.context.drawImage(video, 0, 0, canvasKit.canvas.width, canvasKit.canvas.height);
                        const imageData = canvasKit.context.getImageData(0, 0, canvasKit.canvas.width, canvasKit.canvas.height);
                        const rawValue = this.decodeQrImageData(imageData);

                        if (rawValue && this.fillAttendanceCode(input, rawValue)) {
                            await this.closeQrScanner();
                            options.onDetected?.(input.value, {
                                source: 'camera',
                                input,
                            });

                            return;
                        }
                    } catch (error) {
                        this.setQrScannerStatus('QR belum terbaca. Arahkan kamera lebih dekat ke kode.', 'warning');
                    } finally {
                        this.qrScannerState.busy = false;
                    }
                }

                this.qrScannerState.rafId = window.requestAnimationFrame(() => {
                    void scanFrame();
                });
            };

            await scanFrame();
        } catch (error) {
            this.showNotice('Kamera tidak bisa dibuka. Pastikan izin kamera diberikan.', 'error');
            await this.closeQrScanner();
        }
    }

    async closeQrScanner({ preserveHiddenState = false } = {}) {
        const modal = document.querySelector('[data-qr-scanner-modal]');
        const video = modal?.querySelector('[data-qr-scanner-video]');

        if (this.qrScannerState.rafId) {
            window.cancelAnimationFrame(this.qrScannerState.rafId);
            this.qrScannerState.rafId = null;
        }

        this.qrScannerState.stream?.getTracks().forEach((track) => track.stop());
        this.qrScannerState.stream = null;
        this.qrScannerState.input = null;
        this.qrScannerState.busy = false;

        if (video) {
            video.pause();
            video.srcObject = null;
        }

        if (modal && !preserveHiddenState) {
            this.qrScannerState.modal?.hide();
        } else if (modal) {
            modal.setAttribute('aria-hidden', 'true');
        }

        this.setQrScannerStatus('Arahkan kamera ke QR absensi untuk mengambil 8 karakter pertama kode.');
    }

    setQrScannerStatus(message, tone = 'info') {
        const status = document.querySelector('[data-qr-scanner-status]');

        if (!status) {
            return;
        }

        status.textContent = message;
        status.dataset.tone = tone;
        status.className = `${this.alertTone(tone)} mb-0`;
    }

    bindAttendanceQrTools(container, input, options = {}) {
        const scanButton = container.querySelector('[data-qr-scan]');
        const fileInput = container.querySelector('[data-qr-file]');

        scanButton?.addEventListener('click', async () => {
            await this.openQrScanner(input, options);
        });

        fileInput?.addEventListener('change', async () => {
            const file = fileInput.files?.[0];

            if (!file) {
                return;
            }

            await this.decodeQrFromFile(file, input, options);
            fileInput.value = '';
        });
    }

    isInteractiveTarget(target) {
        return target instanceof Element && Boolean(target.closest('button, a, input, textarea, select, label, summary, [role="button"]'));
    }

    setPullIndicator({ distance = 0, armed = false, refreshing = false }) {
        const indicator = document.querySelector('[data-pull-indicator]');
        const spinner = indicator?.querySelector('[data-pull-spinner]');

        if (!indicator) {
            return;
        }

        const visible = distance > 0 || refreshing;
        const tone = refreshing ? 'info' : armed ? 'primary' : 'secondary';

        indicator.dataset.state = refreshing ? 'refreshing' : armed ? 'armed' : 'idle';
        indicator.className = `${this.alertTone(tone)} text-center mb-3${visible ? '' : ' hidden'}`;
        spinner?.classList.toggle('hidden', !refreshing);

        const label = indicator.querySelector('[data-pull-copy]');

        if (!label) {
            return;
        }

        if (refreshing) {
            label.textContent = 'Menyegarkan data terbaru...';

            return;
        }

        label.textContent = armed ? 'Lepas untuk memperbarui' : 'Tarik untuk memperbarui';
    }

    resetPullIndicator() {
        const indicator = document.querySelector('[data-pull-indicator]');
        const spinner = indicator?.querySelector('[data-pull-spinner]');

        if (!indicator) {
            return;
        }

        indicator.dataset.state = 'idle';
        indicator.className = `${this.alertTone('info')} text-center mb-3 hidden`;
        spinner?.classList.add('hidden');

        const label = indicator.querySelector('[data-pull-copy]');

        if (label) {
            label.textContent = 'Tarik untuk memperbarui';
        }
    }

    getCachedResponse(url) {
        const requestUrl = this.normalizeCacheUrl(new URL(url, window.location.origin));
        const record = this.readCachedResponse(requestUrl);

        if (!record) {
            return null;
        }

        return {
            ...record,
            payload: this.decorateCachedPayload(record, 'prefill'),
        };
    }

    primeCachedView(url, render, label = null) {
        const cached = this.getCachedResponse(url);

        if (!cached) {
            return false;
        }

        render(cached.payload);

        const cacheLabel = label || cached.label || 'data';

        if (navigator.onLine) {
            this.setSyncStatus(`Menampilkan ${cacheLabel} tersimpan. Sinkronkan ke server bila perlu.`, 'info');
        } else {
            this.setSyncStatus(this.buildOfflineCacheMessage(cacheLabel, cached.isStale), 'warning');
        }

        return true;
    }

    resolveCachePolicy(requestUrl, method, options) {
        if (options.cache === false || method !== 'GET' || requestUrl.origin !== window.location.origin) {
            return null;
        }

        const key = `${requestUrl.pathname}${requestUrl.search}`;

        return this.cachePolicies.find((policy) => policy.pattern.test(key) || policy.pattern.test(requestUrl.pathname)) || null;
    }

    buildRequestUrl(url, options = {}) {
        const requestUrl = new URL(url, window.location.origin);

        if (options.fresh === true) {
            requestUrl.searchParams.set('fresh', '1');
        }

        return requestUrl;
    }

    normalizeCacheUrl(requestUrl) {
        const normalizedUrl = new URL(requestUrl.toString());

        normalizedUrl.searchParams.delete('fresh');

        return normalizedUrl;
    }

    buildCacheKey(requestUrl) {
        return `${this.cacheNamespace}:response:${requestUrl.pathname}${requestUrl.search}`;
    }

    writeCachedResponse(requestUrl, payload, policy) {
        const now = Date.now();
        const cacheKey = this.buildCacheKey(requestUrl);

        localStorage.setItem(cacheKey, JSON.stringify({
            payload,
            cached_at: new Date(now).toISOString(),
            fresh_until: new Date(now + policy.freshFor).toISOString(),
            expires_at: new Date(now + policy.retainFor).toISOString(),
            label: policy.label,
        }));
    }

    readCachedResponse(requestUrl) {
        const rawRecord = localStorage.getItem(this.buildCacheKey(requestUrl));

        if (!rawRecord) {
            return null;
        }

        try {
            const record = JSON.parse(rawRecord);
            const expiresAt = new Date(record.expires_at).getTime();

            if (Number.isNaN(expiresAt) || Date.now() > expiresAt) {
                localStorage.removeItem(this.buildCacheKey(requestUrl));

                return null;
            }

            const freshUntil = new Date(record.fresh_until).getTime();

            return {
                ...record,
                isStale: Number.isNaN(freshUntil) ? true : Date.now() > freshUntil,
            };
        } catch (error) {
            localStorage.removeItem(this.buildCacheKey(requestUrl));

            return null;
        }
    }

    pruneExpiredCacheEntries() {
        Object.keys(localStorage).forEach((key) => {
            if (!key.startsWith(`${this.cacheNamespace}:response:`)) {
                return;
            }

            try {
                const record = JSON.parse(localStorage.getItem(key) || '{}');
                const expiresAt = new Date(record.expires_at).getTime();

                if (Number.isNaN(expiresAt) || Date.now() > expiresAt) {
                    localStorage.removeItem(key);
                }
            } catch (error) {
                localStorage.removeItem(key);
            }
        });
    }

    decorateCachedPayload(record, mode) {
        if (!record?.payload || typeof record.payload !== 'object' || Array.isArray(record.payload)) {
            return record?.payload ?? null;
        }

        const payload = JSON.parse(JSON.stringify(record.payload));
        const currentMeta = payload.meta && typeof payload.meta === 'object' ? payload.meta : {};

        payload.meta = {
            ...currentMeta,
            offline_cache: true,
            cache_mode: mode,
            cached_at: record.cached_at,
            cache_is_stale: record.isStale,
            data_origin: 'device_cache',
        };

        return payload;
    }

    isLikelyNetworkError(error) {
        return error instanceof TypeError || error?.name === 'AbortError';
    }

    createOfflineError(message) {
        const error = new Error(message);
        error.isOffline = true;
        error.status = 0;

        return error;
    }

    buildOfflineCacheMessage(label, isStale) {
        return `Menampilkan ${label} terakhir${isStale ? ' yang mungkin sudah lama' : ''}.`;
    }

    markViewSynced(label, payload = null, { force = false } = {}) {
        const syncTime = this.formatTimeOnly(new Date());
        const dataOrigin = payload?.meta?.data_origin || null;
        const portalTime = this.portalDataTime(payload);
        const portalSuffix = portalTime ? ` · portal per ${this.formatTimeOnly(portalTime)}` : '';

        if (force) {
            if (dataOrigin === 'device_cache') {
                this.setSyncStatus('Server belum terjangkau — memakai data tersimpan.', 'warning');

                return;
            }

            this.setSyncStatus(`Sinkron ${syncTime}${portalSuffix}`, 'success');

            return;
        }

        if (dataOrigin === 'snapshot' || dataOrigin === 'server_cache') {
            const statusSuffix = payload?.meta?.status === 'syncing' ? ' · menyinkronkan...' : '';
            this.setSyncStatus(`Data tersimpan${portalSuffix || ` · dimuat ${syncTime}`}${statusSuffix}`, 'info');

            return;
        }

        this.setSyncStatus(portalTime ? `Data portal per ${this.formatTimeOnly(portalTime)}` : `Data dimuat ${syncTime}`, 'success');
    }

    portalDataTime(payload) {
        if (payload?.meta?.synced_at) {
            const synced = new Date(payload.meta.synced_at);
            if (!Number.isNaN(synced.getTime())) {
                return synced;
            }
        }

        const entries = payload?.data;

        if (!entries || typeof entries !== 'object') {
            return null;
        }

        for (const value of Object.values(entries)) {
            if (value && typeof value === 'object' && value.extracted_at) {
                const extracted = new Date(value.extracted_at);

                return Number.isNaN(extracted.getTime()) ? null : extracted;
            }
        }

        return null;
    }

    requireOnlineAction(message = 'Fitur ini butuh koneksi internet.') {
        if (navigator.onLine) {
            return true;
        }

        this.showNotice(message, 'warning');

        return false;
    }

    setSyncStatus(message, tone = 'info') {
        document.querySelectorAll('[data-sync-status]').forEach((element) => {
            element.textContent = message;
            element.dataset.tone = tone;
        });

        const toneClass = {
            success: 'mycic-sync-strip-success',
            warning: 'mycic-sync-strip-warning',
            error: 'mycic-sync-strip-danger',
            danger: 'mycic-sync-strip-danger',
        }[tone] || '';

        document.querySelectorAll('[data-sync-alert]').forEach((element) => {
            element.className = `mycic-sync-strip${toneClass ? ` ${toneClass}` : ''}`;
        });
    }

    defaultSyncStatusMessage() {
        return 'Data siap dipakai.';
    }

    extractUserIdentity(userInfo) {
        return userInfo.student_id || userInfo.nim || userInfo.username || '';
    }

    capitalize(value) {
        if (!value) {
            return '';
        }

        return String(value).charAt(0).toUpperCase() + String(value).slice(1);
    }

    alertTone(type = 'secondary') {
        const tones = {
            secondary: 'mycic-alert-neutral',
            neutral: 'mycic-alert-neutral',
            info: 'mycic-alert-info',
            success: 'mycic-alert-success',
            warning: 'mycic-alert-warning',
            danger: 'mycic-alert-danger',
            error: 'mycic-alert-danger',
            primary: 'mycic-alert-brand',
            brand: 'mycic-alert-brand',
        };

        return tones[type] || tones.secondary;
    }

    badgeTone(type = 'secondary') {
        const tones = {
            primary: 'mycic-badge-brand',
            brand: 'mycic-badge-brand',
            secondary: 'mycic-badge-neutral',
            neutral: 'mycic-badge-neutral',
            success: 'mycic-badge-success',
            warning: 'mycic-badge-warning',
            danger: 'mycic-badge-danger',
            error: 'mycic-badge-danger',
            info: 'mycic-badge-info',
            dark: 'mycic-badge-neutral',
        };

        return tones[type] || tones.secondary;
    }

    statusPanelClass(tone = 'neutral') {
        const tones = {
            primary: 'mycic-status-panel-brand',
            brand: 'mycic-status-panel-brand',
            success: 'mycic-status-panel-success',
            warning: 'mycic-status-panel-warning',
            info: 'mycic-status-panel-info',
            secondary: 'mycic-status-panel-neutral',
            neutral: 'mycic-status-panel-neutral',
        };

        return tones[tone] || tones.neutral;
    }

    buttonClass(variant = 'primary', { small = false } = {}) {
        const variants = {
            primary: small ? 'mycic-btn-primary-sm' : 'mycic-btn-primary',
            secondary: small ? 'mycic-btn-secondary-sm' : 'mycic-btn-secondary',
            ghost: small ? 'mycic-btn-ghost-sm' : 'mycic-btn-ghost',
            destructive: 'mycic-btn-destructive',
            icon: 'mycic-btn-icon',
        };

        return variants[variant] || variants.primary;
    }

    textToneClass(tone = 'muted') {
        const tones = {
            brand: 'text-brand',
            primary: 'text-brand',
            success: 'text-success',
            warning: 'text-warning',
            danger: 'text-danger',
            error: 'text-danger',
            info: 'text-info',
            muted: 'text-ink-muted',
            secondary: 'text-ink-subtle',
        };

        return tones[tone] || tones.muted;
    }

    renderBadge(label, type = 'secondary') {
        return `<span class="${this.badgeTone(type)}">${this.escapeHtml(label)}</span>`;
    }

    renderPageHeader({ pretitle = '', title = '', description = '', badges = [], actions = [] } = {}) {
        const safeBadges = badges.filter(Boolean).join('');
        const safeActions = actions.filter(Boolean).join('');

        return `
            <div class="mb-6 print:hidden">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0 flex-1">
                        ${pretitle ? `<div class="text-caption font-semibold uppercase tracking-wide text-ink-subtle">${this.escapeHtml(pretitle)}</div>` : ''}
                        <h2 class="text-2xl font-extrabold tracking-tight text-ink sm:text-3xl">${this.escapeHtml(title)}</h2>
                        ${description ? `<div class="mt-1.5 text-sm text-ink-muted">${this.escapeHtml(description)}</div>` : ''}
                        ${safeBadges ? `<div class="mt-3 flex flex-wrap gap-2">${safeBadges}</div>` : ''}
                    </div>
                    ${safeActions ? `
                        <div class="flex shrink-0 flex-wrap gap-2 print:hidden">
                            ${safeActions}
                        </div>
                    ` : ''}
                </div>
            </div>
        `;
    }

    renderStatCards(items = [], _colClass = null) {
        const safeItems = items.filter((item) => item && item.value !== undefined);

        if (!safeItems.length) {
            return '';
        }

        return `
            <div class="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                ${safeItems.map((item) => `
                    <div class="mycic-metric">
                        <div class="mycic-metric-label">${this.escapeHtml(item.label || '')}</div>
                        <div class="mycic-metric-value">${this.escapeHtml(item.value || '-')}</div>
                        ${item.note ? `<div class="mycic-metric-note">${this.escapeHtml(item.note)}</div>` : ''}
                    </div>
                `).join('')}
            </div>
        `;
    }

    renderInlineStats(items = [], _colClass = null) {
        const safeItems = items.filter((item) => item && item.value !== undefined);

        if (!safeItems.length) {
            return '';
        }

        return `
            <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                ${safeItems.map((item) => `
                    <div>
                        <div class="mb-1 text-caption uppercase tracking-wide text-ink-subtle">${this.escapeHtml(item.label || '')}</div>
                        <div class="font-medium text-ink">${this.escapeHtml(item.value || '-')}</div>
                        ${item.note ? `<div class="mt-1 text-caption text-ink-subtle">${this.escapeHtml(item.note)}</div>` : ''}
                    </div>
                `).join('')}
            </div>
        `;
    }

    renderEmptyState(message, type = 'secondary', subtitle = '') {
        if (['danger', 'error', 'warning'].includes(type)) {
            return `<div class="${this.alertTone(type)} mb-0" role="alert">${this.escapeHtml(message)}</div>`;
        }

        return `
            <div class="mycic-empty">
                <p class="mycic-empty-title">${this.escapeHtml(message)}</p>
                ${subtitle ? `<p class="mycic-empty-subtitle">${this.escapeHtml(subtitle)}</p>` : ''}
            </div>
        `;
    }

    renderDisabledButton(label) {
        return `<span class="${this.buttonClass('secondary')} pointer-events-none opacity-50" aria-disabled="true">${this.escapeHtml(label)}</span>`;
    }

    storeNotice(message, type = 'info') {
        sessionStorage.setItem('portal-notice', JSON.stringify({ message, type }));
    }

    restoreNotice() {
        const rawNotice = sessionStorage.getItem('portal-notice');

        if (!rawNotice) {
            return;
        }

        sessionStorage.removeItem('portal-notice');

        try {
            const notice = JSON.parse(rawNotice);
            this.showNotice(notice.message, notice.type);
        } catch (error) {
            this.showNotice(rawNotice, 'info');
        }
    }

    toastIcon(tone = 'info') {
        const icons = {
            success: '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" /><path d="M9 12l2 2l4 -4" /></svg>',
            danger: '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 9v4" /><path d="M10.363 3.591l-8.106 13.534a1.914 1.914 0 0 0 1.636 2.871h16.214a1.914 1.914 0 0 0 1.636 -2.87l-8.106 -13.536a1.914 1.914 0 0 0 -3.274 0z" /><path d="M12 16h.01" /></svg>',
            warning: '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 9v4" /><path d="M10.363 3.591l-8.106 13.534a1.914 1.914 0 0 0 1.636 2.871h16.214a1.914 1.914 0 0 0 1.636 -2.87l-8.106 -13.536a1.914 1.914 0 0 0 -3.274 0z" /><path d="M12 16h.01" /></svg>',
            info: '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" /><path d="M12 9h.01" /><path d="M11 12h1v4h1" /></svg>',
        };

        return icons[tone] || icons.info;
    }

    showNotice(message, type = 'info') {
        const host = document.getElementById('notice-host');

        if (!host) {
            return;
        }

        const toneMap = {
            success: 'success',
            error: 'danger',
            warning: 'warning',
            info: 'info',
        };

        host.innerHTML = '';

        const notice = document.createElement('div');
        const tone = toneMap[type] || toneMap.info;

        notice.className = `mycic-toast mycic-toast-${tone}`;
        notice.setAttribute('role', 'alert');
        notice.innerHTML = `
            <span class="mycic-toast-icon mycic-toast-icon-${tone}">${this.toastIcon(tone)}</span>
            <span class="mycic-toast-message">${this.escapeHtml(message)}</span>
            <button type="button" class="mycic-toast-close" aria-label="Tutup notifikasi">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                    <path d="M18 6l-12 12" />
                    <path d="M6 6l12 12" />
                </svg>
            </button>
        `;

        host.appendChild(notice);
        notice.querySelector('[aria-label="Tutup notifikasi"]')?.addEventListener('click', () => {
            host.innerHTML = '';
        });

        window.clearTimeout(this.noticeTimeout);
        this.noticeTimeout = window.setTimeout(() => {
            host.innerHTML = '';
        }, 4200);
    }

    absoluteCampusUrl(path) {
        if (!path) {
            return null;
        }

        const rawPath = String(path).trim();

        if (rawPath === '') {
            return null;
        }

        let normalizedPath = rawPath;

        try {
            const parsed = new URL(rawPath);

            if (parsed.origin === this.campusBase && /^\/files\//i.test(parsed.pathname)) {
                parsed.pathname = `/portal${parsed.pathname}`;

                return parsed.toString();
            }

            return parsed.toString();
        } catch (error) {
            // Continue normalizing relative paths below.
        }

        if (/^(?:(?:\.\.\/)+|\.\/)?files\//i.test(normalizedPath)) {
            normalizedPath = `/portal/${normalizedPath.replace(/^(?:(?:\.\.\/)+|\.\/)?/i, '')}`;
        } else if (/^\/files\//i.test(normalizedPath)) {
            normalizedPath = `/portal${normalizedPath}`;
        }

        try {
            return new URL(normalizedPath, this.campusBase).toString();
        } catch (error) {
            return normalizedPath;
        }
    }

    buildCampusDownloadUrl(path, filename = null) {
        if (!path) {
            return null;
        }

        const params = new URLSearchParams({
            path: String(path),
        });

        if (filename) {
            params.set('name', String(filename));
        }

        return `/api/support/download?${params.toString()}`;
    }

    buildMeetingUrlFromHref(href) {
        if (!href) {
            return null;
        }

        const query = href.includes('?') ? href.split('?')[1] : href;
        const parameters = new URLSearchParams(query);
        const meetingId = parameters.get('id');
        const coursesId = parameters.get('courses_id') || parameters.get('course_id');

        if (!meetingId || !coursesId) {
            return null;
        }

        return `/meeting/${meetingId}?courses_id=${coursesId}`;
    }

    buildClassUrl(id, dosenId, courseId) {
        if (!id) {
            return null;
        }

        const query = new URLSearchParams();

        if (dosenId) {
            query.set('dosen_id', dosenId);
        }

        if (courseId) {
            query.set('course_id', courseId);
        }

        const suffix = query.toString();

        return `/class/${id}${suffix ? `?${suffix}` : ''}`;
    }

    parseQueryString(value) {
        if (!value) {
            return new URLSearchParams();
        }

        const trimmed = String(value).trim();

        if (trimmed === '') {
            return new URLSearchParams();
        }

        try {
            const url = new URL(trimmed, this.campusBase);

            return url.searchParams;
        } catch (error) {
            const query = trimmed.includes('?')
                ? trimmed.split('?').slice(1).join('?')
                : trimmed.replace(/^[?#&]/, '');

            return new URLSearchParams(query);
        }
    }

    extractAssignmentShortcut(value) {
        const params = this.parseQueryString(value);

        return {
            courses_id: params.get('courses_id') || '',
            id: params.get('id') || '',
            pertemuan: params.get('pertemuan') || '',
        };
    }

    extractAttendanceShortcut(value) {
        const params = this.parseQueryString(value);

        return {
            courseid: params.get('courseid') || params.get('course_id') || params.get('courses_id') || '',
            modulesid: params.get('modulesid') || params.get('id') || '',
            pertemuan: params.get('pertemuan') || '',
        };
    }

    formatDate(value) {
        if (!value) {
            return '-';
        }

        const date = new Date(value);

        if (Number.isNaN(date.getTime())) {
            return value;
        }

        return new Intl.DateTimeFormat('id-ID', {
            dateStyle: 'medium',
            timeStyle: 'short',
        }).format(date);
    }

    formatTimeOnly(value) {
        const date = value instanceof Date ? value : new Date(value);

        if (Number.isNaN(date.getTime())) {
            return '-';
        }

        return new Intl.DateTimeFormat('id-ID', {
            hour: '2-digit',
            minute: '2-digit',
        }).format(date);
    }

    escapeHtml(value) {
        return String(value)
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    renderKeyValueList(entries = []) {
        const rows = entries
            .filter((entry) => entry.value !== undefined && entry.value !== null && entry.value !== '')
            .map((entry) => `
                <div class="flex items-start justify-between gap-6 py-3 first:pt-0 last:pb-0">
                    <div class="shrink-0 text-sm text-ink-subtle">${this.escapeHtml(entry.label)}</div>
                    <div class="min-w-0 text-end text-sm font-semibold text-ink">${this.escapeHtml(entry.value)}</div>
                </div>
            `)
            .join('');

        return rows
            ? `<div class="flex flex-col divide-y divide-border-light">${rows}</div>`
            : this.renderEmptyState('Tidak ada data.', 'secondary');
    }
}

window.portalApp = new PortalApp();

document.addEventListener('DOMContentLoaded', () => {
    window.portalApp.init();
});
