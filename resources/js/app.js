import { createPortalEcho } from './echo';
import { csrfHeaders } from './csrf';
import jsQR from 'jsqr';
import * as actionItemRules from './lib/action-items.js';
import * as announcementRules from './lib/announcements.js';
import * as attendanceRules from './lib/attendance.js';
import * as cacheRules from './lib/cache.js';
import * as contactRules from './lib/contacts.js';
import * as dateRules from './lib/dates.js';
import * as formatRules from './lib/format.js';
import * as httpRules from './lib/http.js';
import * as offlineRules from './lib/offline.js';
import * as urlRules from './lib/urls.js';

class PortalApp {
    constructor() {
        this.campusBase = document.documentElement.dataset.campusBase || window.location.origin;
        this.onlineState = navigator.onLine;
        this.syncHandlers = new Map();
        this.isSyncing = false;
        this.cacheNamespace = 'mycic-cache-v3';
        this.authSnapshotKey = `${this.cacheNamespace}:auth`;
        this.cacheOwnerKey = `${this.cacheNamespace}:owner`;
        // Stored as the owner once a login dropped every earlier copy; an
        // owner is a 32-character hex digest, so it never matches this.
        this.clearedCacheOwner = 'cleared';
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
            opening: false,
            openingSince: 0,
            attempt: 0,
            returnFocus: null,
        };
        // A camera request the browser never settles (a permission prompt
        // dismissed without an answer) stops blocking a new tap after this.
        this.qrScannerOpeningLimitMs = 8000;
        this.qrScannerOptions = new WeakMap();
        this.echo = null;
        this.echoChannel = null;
        this.realtimeConnected = false;
        this.isLoggingOut = false;
        this.quickQrTarget = null;
        this.quickQrEmptyMessage = null;
        // Set by the meeting page once it opened the scanner for `?qr=1`.
        this.qrFromUrlHandled = false;
        this.backgroundRefreshTimer = null;
        this.activeOperations = new Map();
        this.operationWaiters = new Map();
        this.finishedOperations = new Map();
        this.operationStorageKey = 'mycic-active-operations';
        this.operationPollIntervalMs = 2000;
        this.operationPollLimitMs = 4 * 60 * 1000;
        this.realtimeRefreshTimers = new Map();
        this.actionItemsReloadTimer = null;
        // PortalSessionMiddleware::BACKGROUND_HEADER: a request the app makes on
        // its own checks the session without counting as the student's activity.
        this.backgroundRequestHeader = 'X-MyCIC-Background';
        // Background work running its synchronous start; see runAsBackground().
        this.backgroundWork = 0;
        this.cachePolicies = cacheRules.CACHE_POLICIES;
        this.syncAliases = new Map();
        this.actionItems = null;
        this.actionItemsRequest = null;
        this.navBadgeOverrides = new Map();
        this.sheetState = { host: null, returnFocus: null, onClose: null };
        this.pendingQuickAttendance = false;
        this.outboxItems = [];
        this.pendingQuickAttendanceTimer = null;
        this.toastLimit = 3;
        this.notificationUnreadCount = null;
        this.notificationToastBuffer = [];
        this.notificationToastTimer = null;
        // public/offline.html counts its reload attempts here.
        this.offlineRetryKey = 'mycic-offline-retry';
    }

    init() {
        this.forgetOfflineRetries();
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
        this.bindContactSheetTriggers();
        this.bindDownloadLinks();
        this.bindHistoryRestore();

        if (document.body.dataset.auth !== 'guest') {
            const authSnapshot = this.getAuthSnapshot();

            if (authSnapshot) {
                this.hydrateUser(authSnapshot);
            }

            this.initRealtime();
            this.resumeActiveOperations();
            this.scheduleActionItemBadges();
            this.scheduleNotificationBadge();
            this.scheduleOutbox();
            this.consumeQuickAttendanceRequest();
        }
    }

    /**
     * Pages with a [data-outbox-list] host show what waits in the MyCIC
     * outbox (not sent to the portal yet); realtime events with the `outbox`
     * sync key re-read it as items are queued, cancelled or settled.
     */
    scheduleOutbox() {
        this.registerSyncHandler('outbox', ({ force = false } = {}) => this.loadOutbox({ force }));

        if (!document.querySelector('[data-outbox-list]')) {
            return;
        }

        const load = () => void this.loadOutbox();

        if ('requestIdleCallback' in window) {
            window.requestIdleCallback(load, { timeout: 4000 });
        } else {
            window.setTimeout(load, 1200);
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

        const panel = modal.querySelector('[data-qr-scanner-panel]');

        this.qrScannerState.modal = {
            // A dialog like the app sheet: focus moves in and stays there, the
            // page behind stops scrolling, and focus returns on close.
            show: () => {
                this.qrScannerState.returnFocus = document.activeElement instanceof HTMLElement && document.activeElement !== document.body
                    ? document.activeElement
                    : null;
                modal.classList.remove('hidden');
                modal.setAttribute('aria-hidden', 'false');
                document.documentElement.classList.add('overflow-hidden');
                (modal.querySelector('[data-qr-scanner-close]') || panel)?.focus();
            },
            hide: () => {
                const returnFocus = this.qrScannerState.returnFocus;
                const wasOpen = !modal.classList.contains('hidden');

                this.qrScannerState.returnFocus = null;
                modal.classList.add('hidden');
                modal.setAttribute('aria-hidden', 'true');

                if (!wasOpen) {
                    return;
                }

                if (!this.sheetState.host || this.sheetState.host.classList.contains('hidden')) {
                    document.documentElement.classList.remove('overflow-hidden');
                }

                if (returnFocus?.isConnected) {
                    returnFocus.focus();
                }
            },
        };

        modal.querySelector('[data-qr-scanner-close]')?.addEventListener('click', () => {
            void this.closeQrScanner();
        });

        modal.querySelector('[data-qr-scanner-backdrop]')?.addEventListener('click', () => {
            void this.closeQrScanner();
        });

        modal.addEventListener('keydown', (event) => {
            if (event.key === 'Tab') {
                this.trapTabFocus(event, panel);
            }
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

                if (input) {
                    await this.openQrScanner(input);

                    return;
                }

                // No attendance form on this page: a page can name the meeting
                // where the scanner should open instead (see the dashboard).
                if (this.quickQrTarget) {
                    window.location.href = this.quickQrTarget;

                    return;
                }

                // Pages that say nothing about attendance fall back to today's
                // schedule already stored on this device (no portal request).
                if (this.quickQrEmptyMessage === null) {
                    const cachedTarget = this.quickQrTargetFromCache();

                    if (cachedTarget === undefined) {
                        window.location.href = '/dashboard?absen=1';

                        return;
                    }

                    if (cachedTarget) {
                        window.location.href = cachedTarget;

                        return;
                    }
                }

                this.showNotice(this.quickQrEmptyMessage || 'Tidak ada absensi yang bisa diisi sekarang. Buka pertemuan yang sedang berlangsung dari halaman Kuliah.', 'warning');
            });
        });
    }

    /**
     * Where the quick QR button should take the student when the current page
     * has no attendance form. `null` keeps the explanatory message.
     */
    setQuickQrTarget(url, emptyMessage = null) {
        this.quickQrTarget = url || null;
        this.quickQrEmptyMessage = emptyMessage;

        if (this.quickQrTarget) {
            this.resolvePendingQuickAttendance();
        }
    }

    /**
     * The meeting to scan for, read from today's dashboard snapshot cached on
     * this device. `null` when today has nothing to scan, `undefined` when the
     * device has no usable copy of today's schedule.
     */
    quickQrTargetFromCache() {
        const summary = this.getCachedResponse('/api/dashboard')?.payload?.data?.classes_summary;

        return attendanceRules.quickQrTargetFromSummary(summary, new Date().toLocaleDateString('sv-SE'));
    }

    /**
     * `?absen=1` (the "Absen sekarang" PWA shortcut, or the quick QR button
     * on a page without today's schedule) opens the scanner as soon as the
     * page knows which class is running.
     */
    consumeQuickAttendanceRequest() {
        const url = new URL(window.location.href);

        if (url.searchParams.get('absen') !== '1') {
            return;
        }

        url.searchParams.delete('absen');
        window.history.replaceState({}, '', url.toString());
        this.pendingQuickAttendance = true;
        this.pendingQuickAttendanceTimer = window.setTimeout(() => {
            this.resolvePendingQuickAttendance({ final: true });
        }, 30000);

        const input = document.querySelector('[data-field="kode"]') || document.querySelector('#attendance-code');

        if (input) {
            this.resolvePendingQuickAttendance();
        }
    }

    resolvePendingQuickAttendance({ final = false } = {}) {
        if (!this.pendingQuickAttendance) {
            return;
        }

        const input = document.querySelector('[data-field="kode"]') || document.querySelector('#attendance-code');

        if (!input && !this.quickQrTarget && !final) {
            return;
        }

        this.pendingQuickAttendance = false;
        window.clearTimeout(this.pendingQuickAttendanceTimer);

        if (input) {
            void this.openQrScanner(input);

            return;
        }

        if (this.quickQrTarget) {
            window.location.href = this.quickQrTarget;

            return;
        }

        this.showNotice(this.quickQrEmptyMessage || 'Tidak ada absensi yang bisa diisi sekarang.', 'warning');
    }

    /**
     * Account menu and the desktop "Lainnya" menu: one open at a time,
     * closed by an outside click or Escape.
     */
    bindAccountMenu() {
        const panelSelector = '[data-account-menu-panel], [data-dropdown-panel]';
        const triggerSelector = '[data-account-menu-trigger], [data-dropdown-trigger]';
        const closeAll = () => {
            document.querySelectorAll(panelSelector).forEach((panel) => panel.classList.add('hidden'));
            document.querySelectorAll(triggerSelector).forEach((trigger) => trigger.setAttribute('aria-expanded', 'false'));
        };

        document.querySelectorAll('[data-account-menu], [data-dropdown]').forEach((menu) => {
            const trigger = menu.querySelector(triggerSelector);
            const panel = menu.querySelector(panelSelector);

            if (!trigger || !panel) {
                return;
            }

            trigger.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopPropagation();

                const isOpen = !panel.classList.contains('hidden');

                closeAll();

                if (isOpen) {
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

        document.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape') {
                return;
            }

            const openTrigger = document.querySelector(
                '[data-account-menu-trigger][aria-expanded="true"], [data-dropdown-trigger][aria-expanded="true"]',
            );

            if (openTrigger) {
                closeAll();
                openTrigger.focus();
            }
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

            if (event.target instanceof Element && event.target.closest('[data-app-sheet], [data-qr-scanner-modal]')) {
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
            void this.runSyncHandlers({ background: true });

            return;
        }

        if (document.body.dataset.auth !== 'guest') {
            this.initRealtime();
        }

        if (!document.querySelector('[data-sync-status]')?.textContent?.trim()) {
            this.setSyncStatus(this.defaultSyncStatusMessage(), 'info');
        }
    }

    /**
     * `aliases`: other sync keys (from realtime events) that should re-run
     * this handler, e.g. `registerSyncHandler('more-index', load, { aliases: ['portal-context'] })`.
     * The handler still runs once per manual sync.
     */
    registerSyncHandler(key, handler, { aliases = [] } = {}) {
        if (!key || typeof handler !== 'function') {
            return;
        }

        this.syncHandlers.set(key, handler);
        aliases.filter(Boolean).forEach((alias) => this.syncAliases.set(alias, key));
    }

    /**
     * `background`: the app re-reads on its own (a reconnect, a timer), not
     * because the student asked. Each handler gets it and passes it on to the
     * reads it makes; see runAsBackground().
     */
    async runSyncHandlers({ force = false, background = false } = {}) {
        if (this.isSyncing || !navigator.onLine || this.syncHandlers.size === 0) {
            return;
        }

        this.isSyncing = true;
        this.setSyncRefreshButtonState({ syncing: true, force });
        this.setSyncStatus(force ? 'Memeriksa server kampus...' : 'Memuat ulang data aplikasi...', 'info');

        let refreshedCount = 0;

        for (const handler of this.syncHandlers.values()) {
            try {
                await (background
                    ? this.runAsBackground(() => handler({ force, background }))
                    : handler({ force }));
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

    /**
     * Work the app starts on its own (a realtime event, a timer, a
     * reconnect). Its reads carry the background header, so an open tab
     * nobody touches never keeps the campus session from idling out.
     *
     * `task` gets `{ background: true }` to pass on to every read it makes
     * (`request(url, { background })`). A read it starts before its first
     * `await` is marked even without it. The scope deliberately ends there
     * (`return`, not `return await`): JavaScript runs one piece of code at a
     * time, so nothing the student does can start a read inside it, and a
     * read the student starts while the task is still waiting stays the
     * student's own.
     */
    async runAsBackground(task) {
        this.backgroundWork += 1;

        try {
            return task({ background: true });
        } finally {
            this.backgroundWork -= 1;
        }
    }

    async request(url, options = {}) {
        // Decided as the read starts: one begun by background work before it
        // first waits is the app's own too (see runAsBackground()).
        const requestOptions = this.backgroundWork > 0 ? { ...options, background: true } : options;

        try {
            return await this.performRequest(url, requestOptions);
        } finally {
            if (this.pendingQuickAttendance && new URL(url, window.location.origin).pathname === '/api/dashboard') {
                // Runs after the page has rendered the dashboard it asked for.
                // While today's attendance is still being checked the request
                // stays open: a later dashboard read (or the 30 s timer) ends it.
                window.setTimeout(() => {
                    if (this.quickQrTargetFromCache() !== undefined) {
                        this.resolvePendingQuickAttendance({ final: true });
                    }
                }, 0);
            }
        }
    }

    async performRequest(url, options = {}) {
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

        // Only a read marked as the app's own carries the header; a POST is
        // always the student's action and is never marked.
        const background = method === 'GET' && options.background === true;
        const config = {
            method,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                ...csrfHeaders(),
                ...(background ? { [this.backgroundRequestHeader]: '1' } : {}),
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

        const timeoutMs = httpRules.requestTimeoutMs(method, options);
        const controller = typeof AbortController === 'function' ? new AbortController() : null;
        let timedOut = false;
        const timeoutTimer = controller && timeoutMs > 0
            ? window.setTimeout(() => {
                timedOut = true;
                controller.abort();
            }, timeoutMs)
            : null;

        if (controller) {
            config.signal = controller.signal;
        }

        try {
            const response = await fetch(requestUrl.toString(), config);

            // An answer arrived, so a "network error" earlier was a hiccup (an
            // iOS app switch, a Wi-Fi handoff) the browser never reported as
            // offline: the shell comes back online on its own.
            if (this.onlineState === false && navigator.onLine) {
                this.updateConnectionState(true, { announce: false });
            }

            const contentType = response.headers.get('content-type') || '';
            let payload = null;
            let unreadable = false;

            if (contentType.includes('application/json')) {
                try {
                    payload = await response.json();
                } catch (parseError) {
                    if (!(parseError instanceof SyntaxError)) {
                        throw parseError;
                    }

                    unreadable = true;
                }
            }

            const outcome = httpRules.responseOutcome(response, payload, {
                unreadable,
                redirectOnAuth: options.redirectOnAuth,
                authMode: document.body.dataset.auth,
            });

            if (outcome.action === 'login') {
                this.sendToLogin(outcome.message, outcome.tone);

                return null;
            }

            if (outcome.action === 'fail') {
                throw outcome.error;
            }

            if (cachePolicy && payload && typeof payload === 'object') {
                this.writeCachedResponse(cacheUrl, payload, cachePolicy);
            }

            return payload;
        } catch (error) {
            if (timedOut) {
                if (cachePolicy && cachedResponse) {
                    this.setSyncStatus(`Server lambat merespons. ${this.buildOfflineCacheMessage(cachePolicy.label, cachedResponse.isStale)}`, 'warning');

                    return this.decorateCachedPayload(cachedResponse, 'fallback');
                }

                throw this.createTimeoutError(
                    method === 'GET'
                        ? 'Server kampus lambat merespons. Coba lagi sebentar lagi.'
                        : 'Portal kampus lambat merespons. Status aksi akan diperiksa ulang.'
                );
            }

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
        } finally {
            window.clearTimeout(timeoutTimer);
        }
    }

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

    initRealtime() {
        if (!navigator.onLine || document.body.dataset.auth === 'guest' || this.echo) {
            return;
        }

        this.echo = createPortalEcho();

        if (!this.echo) {
            this.setRealtimeStatus('Pembaruan otomatis belum aktif.', 'warning');

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

        // Only report "active" once the private channel is actually joined;
        // operations are polled meanwhile, so a failed join is not fatal.
        this.setRealtimeStatus('Menghubungkan real-time...', 'warning');
        this.echoChannel
            .subscribed(() => {
                this.realtimeConnected = true;
                this.setRealtimeStatus('Pembaruan otomatis aktif.', 'success');
            })
            .error(() => {
                this.realtimeConnected = false;
                this.setRealtimeStatus('Pembaruan otomatis tidak tersedia.', 'warning');
            });
        this.watchRealtimeConnection(this.echo);
    }

    /**
     * The socket can drop without the browser going offline (a locked phone,
     * a Reverb restart). The chip follows the connection, and after a
     * reconnect whatever was broadcast meanwhile (and lost) is read again.
     */
    watchRealtimeConnection(echo) {
        const connection = echo?.connector?.pusher?.connection;

        if (!connection || typeof connection.bind !== 'function') {
            return;
        }

        let dropped = false;

        connection.bind('state_change', ({ previous, current } = {}) => {
            // Ignore an instance this page already closed (offline, logout).
            if (this.echo !== echo || current === 'disconnected') {
                return;
            }

            if (current === 'connected') {
                if (dropped) {
                    dropped = false;
                    void this.catchUpAfterRealtimeGap();
                }

                return;
            }

            if (previous === 'connected' || current === 'unavailable') {
                dropped = true;
            }

            this.realtimeConnected = false;

            if (current === 'unavailable' || current === 'failed') {
                this.setRealtimeStatus('Pembaruan otomatis tidak tersedia.', 'warning');
            } else if (current === 'connecting' && dropped) {
                this.setRealtimeStatus('Menyambung ulang pembaruan otomatis...', 'warning');
            }
        });
    }

    /**
     * Events broadcast while the socket was down are not replayed: the bell
     * count is read again, and so is the page, unless the student is in the
     * middle of filling a form a re-render would wipe.
     */
    async catchUpAfterRealtimeGap() {
        void this.loadNotificationCount();

        if (this.hasUnsentFormInput()) {
            return;
        }

        await this.runSyncHandlers({ force: false, background: true });
    }

    /**
     * Whether a form on the page holds input the student has not sent yet
     * (a typed code, a chosen file).
     */
    hasUnsentFormInput() {
        return Array.from(document.querySelectorAll('form input, form textarea')).some((field) => {
            if (field.type === 'file') {
                return (field.files?.length || 0) > 0;
            }

            if (['hidden', 'checkbox', 'radio', 'button', 'submit', 'reset'].includes(field.type)) {
                return false;
            }

            return field.value !== field.defaultValue;
        });
    }

    disconnectRealtime() {
        if (this.echo) {
            this.echo.disconnect();
        }

        this.echo = null;
        this.echoChannel = null;
        this.realtimeConnected = false;
        this.setRealtimeStatus('Pembaruan otomatis terputus.', 'warning');
    }

    async queueBackgroundRefresh() {
        const domains = this.refreshDomainsForCurrentPage();

        // Detail pages show a single parameterized dataset: rebuilding it
        // inline is cheap and needs the page's own parameters.
        if (domains.length === 0) {
            if (this.syncHandlers.size === 0) {
                this.setSyncStatus('Halaman ini tidak punya data untuk disinkronkan.', 'info');

                return;
            }

            await this.runSyncHandlers({ force: true });

            return;
        }

        this.isSyncing = true;
        this.setSyncRefreshButtonState({ syncing: true, force: true });
        this.setSyncStatus('Menghubungkan ke portal kampus...', 'info');

        try {
            await this.request('/api/refresh', {
                method: 'POST',
                json: { domains },
            });
            // The portal is read page by page; the button stays busy until the
            // sync reports back (or a fallback re-read after a while).
            this.setSyncStatus('Mengambil data dari portal kampus... biasanya 15–60 detik.', 'info');
            this.awaitBackgroundRefresh();
        } catch (error) {
            this.isSyncing = false;
            this.setSyncRefreshButtonState();
            this.setSyncStatus('Gagal memulai refresh dari portal.', 'error');
            this.showNotice(this.describeError(error), 'error');
            await this.runSyncHandlers({ force: true });
        }
    }

    awaitBackgroundRefresh() {
        window.clearTimeout(this.backgroundRefreshTimer);
        this.backgroundRefreshTimer = window.setTimeout(() => {
            this.finishBackgroundRefresh();
            // No realtime confirmation arrived: re-read the snapshots anyway.
            void this.runSyncHandlers({ force: false, background: true });
        }, 90000);
    }

    finishBackgroundRefresh() {
        if (!this.backgroundRefreshTimer) {
            return;
        }

        window.clearTimeout(this.backgroundRefreshTimer);
        this.backgroundRefreshTimer = null;
        this.isSyncing = false;
        this.setSyncRefreshButtonState();
    }

    refreshDomainsForCurrentPage() {
        const page = document.body.dataset.page || 'app';

        const pageDomains = {
            dashboard: ['dashboard', 'assignments', 'portal_context', 'krs', 'payments', 'announcements'],
            'class-index': ['classes'],
            'class-show': [],
            'meeting-show': [],
            'exam-index': ['exams'],
            'exam-show': [],
            profile: ['profile'],
            finance: ['payments'],
            'krs-index': ['krs', 'advising'],
            'krs-show': [],
            'grades-index': ['grades'],
            'transcript-index': ['grades'],
            'announcement-index': ['announcements'],
            'announcement-show': ['announcements'],
            'skkm-index': ['skkm'],
            'thesis-index': ['thesis', 'grades', 'payments'],
            'library-index': ['library', 'thesis_repository'],
            'library-theses': ['thesis_repository'],
            'more-index': ['portal_context', 'payments', 'announcements', 'skkm'],
            // Pages over MyCIC's own data: the button re-reads them directly.
            'notification-index': [],
            'archive-index': [],
            'archive-show': [],
            'notebook-index': [],
            'notebook-show': [],
            password: [],
        };

        // An unlisted page re-reads its own handlers rather than paying a
        // portal scrape for a dataset (the dashboard) it never shows.
        return this.filterRefreshableDomains(pageDomains[page] ?? []);
    }

    /**
     * Drop datasets the server cannot refresh in the background (the layout
     * lists the refreshable ones), so one unknown name never fails the sync.
     */
    filterRefreshableDomains(domains) {
        const declared = document.body.dataset.refreshableDatasets;

        if (declared === undefined) {
            return domains;
        }

        const refreshable = new Set(declared.split(',').map((name) => name.trim()).filter(Boolean));

        return domains.filter((domain) => refreshable.has(domain));
    }

    handleRealtimePayload(payload) {
        const cacheKeys = Array.isArray(payload?.cache_keys) ? payload.cache_keys : [];
        const syncKeys = Array.isArray(payload?.sync_keys) ? payload.sync_keys : [];

        if (['completed', 'failed', 'relogin'].includes(payload?.status)) {
            this.finishBackgroundRefresh();
        }

        cacheKeys.forEach((cacheKey) => this.clearCachedResponse(cacheKey));

        if (cacheKeys.includes('/api/action-items')) {
            // A background job announces each dataset as it lands, then once
            // more when it completes: one re-read per burst is enough. It stays
            // shorter than the 250 ms handler delay below, so page handlers
            // reuse this request instead of asking again.
            window.clearTimeout(this.actionItemsReloadTimer);
            this.actionItemsReloadTimer = window.setTimeout(() => {
                void this.loadActionItems({ force: true, background: true });
            }, 100);
        }

        if (payload?.message) {
            this.setSyncStatus(payload.message, this.toneFromUrgency(payload.urgency));
        }

        const handlerKeys = new Set(syncKeys
            .map((syncKey) => (this.syncHandlers.has(syncKey) ? syncKey : this.syncAliases.get(syncKey)))
            .filter(Boolean));

        handlerKeys.forEach((syncKey) => {
            window.clearTimeout(this.realtimeRefreshTimers.get(syncKey));
            this.realtimeRefreshTimers.set(syncKey, window.setTimeout(() => {
                const handler = this.syncHandlers.get(syncKey);

                // The server already re-synced the snapshot behind this
                // view; re-reading it must not trigger another portal scrape,
                // nor count as the student's activity.
                if (handler) {
                    void this.runAsBackground(() => handler({ force: false, background: true }));
                }
            }, 250));
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

    /**
     * The 202 answer of an attendance or upload request made in an offline
     * session: the item is in the MyCIC outbox, NOT at the portal. Returns
     * true when the result was such an answer, so callers skip their
     * "sent" wording.
     */
    handleQueuedActionResult(result) {
        if (result?.data?.status !== 'queued_offline') {
            return false;
        }

        this.showNotice(result?.message || 'Belum terkirim ke portal. Disimpan di antrean MyCIC dan dikirim begitu portal bisa diakses.', 'warning');
        this.clearCachedResponse('/api/outbox');
        void this.loadOutbox({ force: true });

        return true;
    }

    /**
     * Read the outbox and render it into every [data-outbox-list] host on the
     * page. A host may limit itself with data-outbox-match (a space-separated
     * list of `meeting:<id>`, `course:<id>`, `exam:<id>` or `action:<name>`
     * keys; an item is shown when it carries any of them).
     */
    async loadOutbox({ force = false } = {}) {
        const hosts = Array.from(document.querySelectorAll('[data-outbox-list]'));

        if (!hosts.length || document.body.dataset.auth !== 'required' || !navigator.onLine) {
            return;
        }

        if (force) {
            this.clearCachedResponse('/api/outbox');
        }

        try {
            const payload = await this.request('/api/outbox', { redirectOnAuth: false });
            const items = Array.isArray(payload?.data?.items) ? payload.data.items : [];

            this.outboxItems = items;
            hosts.forEach((host) => this.renderOutboxHost(host, items));
        } catch (error) {
            // The queue is secondary to the page; a failed read leaves the hosts as they are.
            console.warn('Outbox could not be loaded', error);
        }
    }

    renderOutboxHost(host, items) {
        const matchKeys = String(host.dataset.outboxMatch || '').split(/\s+/).filter(Boolean);
        const visible = matchKeys.length
            ? items.filter((item) => (item.match_keys || []).some((key) => matchKeys.includes(key)))
            : items;
        const queuedCount = visible.filter((item) => item.status === 'queued').length;

        host.innerHTML = this.renderOutboxItems(visible, { heading: host.dataset.outboxHeading });
        host.classList.toggle('hidden', visible.length === 0);
        host.dataset.outboxQueued = String(queuedCount);

        host.querySelectorAll('[data-outbox-cancel]').forEach((button) => {
            button.addEventListener('click', () => this.cancelOutboxItem(Number(button.dataset.outboxCancel), button));
        });
    }

    /**
     * Every row says in words whether the portal has it; a queued row never
     * reads as received.
     */
    renderOutboxItems(items, { heading = null } = {}) {
        if (!items.length) {
            return '';
        }

        const tones = { queued: 'warning', sent: 'success', failed: 'danger', expired: 'danger' };
        const title = heading || 'Antrean offline';

        return `
            <section class="mycic-card" aria-label="${this.escapeHtml(title)}">
                <div class="mycic-card-header flex flex-wrap items-center justify-between gap-2">
                    <h3 class="mycic-card-title">${this.escapeHtml(title)}</h3>
                    <span class="text-caption text-ink-subtle">Disimpan di MyCIC, dikirim saat portal bisa diakses</span>
                </div>
                <div class="divide-y divide-border-light">
                    ${items.map((item) => {
                        const isQueued = item.status === 'queued';
                        const deadlinePassed = isQueued && item.deadline_passed;
                        const tone = deadlinePassed ? 'danger' : (tones[item.status] || 'secondary');
                        const statusLabel = deadlinePassed ? 'Tenggat lewat, belum terkirim ke portal' : (item.status_label || item.status);
                        const meta = [
                            item.file_name,
                            item.deadline_at ? `Tenggat ${this.formatDate(item.deadline_at)}` : null,
                            item.created_at ? `Diminta ${this.formatTimeAgo(item.created_at)}` : null,
                            item.sent_at ? `Terkirim ${this.formatTimeAgo(item.sent_at)}` : null,
                        ].filter(Boolean).join(' · ');

                        return `
                            <div class="mycic-list-row" data-outbox-item="${this.escapeHtml(item.id)}" data-outbox-status="${this.escapeHtml(item.status)}">
                                <div class="min-w-0 flex-1">
                                    <div class="mb-1 flex flex-wrap items-center gap-2">
                                        ${this.renderBadge(item.action_label || item.action, 'brand')}
                                        ${this.renderBadge(statusLabel, tone)}
                                    </div>
                                    <div class="font-medium text-ink">${this.escapeHtml(item.subject || item.action_label || 'Aksi portal')}</div>
                                    ${meta ? `<div class="text-caption text-ink-subtle">${this.escapeHtml(meta)}</div>` : ''}
                                    ${item.status === 'failed' || item.status === 'expired'
                                        ? `<div class="mt-1 text-caption ${this.textToneClass('danger')}">${this.escapeHtml(item.last_error || statusLabel)}</div>`
                                        : ''}
                                    ${isQueued
                                        ? `<div class="mt-1 text-caption text-ink-subtle">Portal belum menerima ini. MyCIC mengirimnya otomatis begitu portal bisa diakses${item.deadline_at ? ', selama tenggatnya belum lewat' : ''}.</div>`
                                        : ''}
                                </div>
                                <div class="flex shrink-0 flex-col items-end gap-2">
                                    ${item.url ? `<a href="${this.escapeHtml(item.url)}" class="${this.buttonClass('secondary', { small: true })}">Buka</a>` : ''}
                                    ${isQueued ? `<button type="button" class="${this.buttonClass('destructive', { small: true })}" data-outbox-cancel="${this.escapeHtml(item.id)}">Batalkan</button>` : ''}
                                </div>
                            </div>
                        `;
                    }).join('')}
                </div>
            </section>
        `;
    }

    async cancelOutboxItem(id, button = null) {
        if (!Number.isInteger(id) || !window.confirm('Batalkan item ini? Portal belum menerimanya, dan MyCIC tidak akan mengirimnya.')) {
            return;
        }

        const run = async () => {
            try {
                await this.request(`/api/outbox/${id}`, { method: 'DELETE' });
                this.showNotice('Item antrean dibatalkan. Tidak ada yang dikirim ke portal.', 'info');
                await this.loadOutbox({ force: true });
            } catch (error) {
                this.showNotice(error.payload?.message || error.message, 'error');
            }
        };

        if (button) {
            await this.withSubmitLoading(button, run);
        } else {
            await run();
        }
    }

    /**
     * A new notification: the bell count comes with it, and a burst (one
     * sync can find several changes) becomes a single toast.
     */
    handlePortalNotification(payload) {
        if (Number.isFinite(payload?.unread_count)) {
            this.setNotificationUnreadCount(payload.unread_count);
        }

        this.notificationToastBuffer.push(payload);
        window.clearTimeout(this.notificationToastTimer);
        this.notificationToastTimer = window.setTimeout(() => this.flushNotificationToasts(), 600);
        this.handleRealtimePayload(payload);
        window.dispatchEvent(new CustomEvent('portal:notification', { detail: payload }));
    }

    flushNotificationToasts() {
        const buffered = this.notificationToastBuffer;
        this.notificationToastBuffer = [];

        if (buffered.length === 0) {
            return;
        }

        const tone = buffered
            .map((payload) => this.toneFromUrgency(payload?.urgency))
            .reduce((current, next) => (['error', 'warning', 'info'].indexOf(next) < ['error', 'warning', 'info'].indexOf(current) ? next : current), 'info');

        if (buffered.length === 1) {
            this.showNotice(buffered[0]?.message || buffered[0]?.title || 'Ada pembaruan portal.', tone);

            return;
        }

        this.showNotice(`${buffered.length} notifikasi baru. Buka lonceng untuk melihatnya.`, tone);
    }

    /**
     * Unread count for the header bell, read when the browser is idle so the
     * page's own data loads first. Realtime events keep it current afterwards.
     */
    scheduleNotificationBadge() {
        if (document.body.dataset.auth !== 'required' || !navigator.onLine) {
            return;
        }

        const run = () => {
            void this.loadNotificationCount();
        };

        if (typeof window.requestIdleCallback === 'function') {
            window.requestIdleCallback(run, { timeout: 3000 });
        } else {
            window.setTimeout(run, 1200);
        }
    }

    /**
     * Read on its own (after the page loaded, after a reconnect), so it never
     * counts as the student's activity.
     */
    async loadNotificationCount() {
        try {
            const payload = await this.request('/api/notifications/unread-count', { redirectOnAuth: false, timeout: 15000, background: true });

            this.setNotificationUnreadCount(payload?.data?.unread_count);
        } catch (error) {
            // The bell simply stays as it was; the list page shows the error.
        }
    }

    setNotificationUnreadCount(count) {
        const value = Math.max(0, Math.floor(Number(count) || 0));

        this.notificationUnreadCount = value;
        this.setNavBadge('notifications', value, { tone: 'danger', label: `${value} belum dibaca` });
    }

    /**
     * Progress of a background operation, from a realtime event or a poll.
     * Both may deliver the same stage, so stale or repeated updates are
     * ignored and the outcome is announced once.
     */
    handleUploadProgress(payload) {
        const operationId = payload?.operation_id;

        if (!operationId || this.finishedOperations.has(operationId)) {
            return;
        }

        const current = this.activeOperations.get(operationId);
        const percent = payload.percent || this.percentForStage(payload.status);
        const isFinal = ['completed', 'failed', 'unverified'].includes(payload.status);

        if (!isFinal && current && percent < current.percent) {
            return;
        }

        this.activeOperations.set(operationId, {
            label: current?.label || 'Upload',
            status: payload.status,
            message: payload.message,
            detail: payload.detail,
            nextStep: payload.next_step,
            percent,
        });

        this.renderOperationStatus(operationId);

        if (payload.status === 'completed') {
            this.showNotice(payload.message || 'Upload selesai.', 'success');
            this.handleRealtimePayload(payload);
            this.finishOperation(operationId, 'completed');
        }

        if (payload.status === 'failed') {
            const failureMessage = payload.next_step
                ? `${payload.message} ${payload.next_step}`
                : payload.message;
            this.showNotice(failureMessage, 'error');
            this.finishOperation(operationId, 'failed');
        }

        // The portal neither confirmed nor refused the file: the student
        // checks the page (re-read below) before uploading again.
        if (payload.status === 'unverified') {
            const message = payload.message || 'Status upload belum pasti.';

            this.showNotice(payload.next_step ? `${message} ${payload.next_step}` : message, 'warning');
            this.handleRealtimePayload({ ...payload, urgency: payload.urgency || 'warning' });
            this.finishOperation(operationId, 'unknown');
        }
    }

    /**
     * Follow a background operation until it completes or fails. Resolves
     * with 'completed', 'failed' or 'unknown' (status could not be read, or
     * the portal left the upload unverified).
     */
    trackOperation(operationId, label, { startedAt = Date.now(), announce = true } = {}) {
        // A realtime event can report the outcome before the upload request
        // itself returns; the operation is then already settled.
        if (this.finishedOperations.has(operationId)) {
            return Promise.resolve(this.finishedOperations.get(operationId));
        }

        const existing = this.operationWaiters.get(operationId);

        if (existing) {
            return existing.promise;
        }

        if (announce) {
            this.showNotice(`${label} diterima. Mengirim ke portal...`, 'info');
        }

        if (!this.activeOperations.has(operationId)) {
            this.activeOperations.set(operationId, {
                label,
                status: 'connecting',
                message: `${label} diterima. Menyiapkan pengiriman...`,
                percent: 5,
            });
        }

        this.renderOperationStatus(operationId);

        let resolve;
        const promise = new Promise((resolver) => {
            resolve = resolver;
        });

        this.operationWaiters.set(operationId, { label, startedAt, resolve, promise, timer: null });
        this.persistActiveOperations();
        void this.pollOperation(operationId);

        return promise;
    }

    async pollOperation(operationId) {
        const waiter = this.operationWaiters.get(operationId);

        if (!waiter) {
            return;
        }

        if (Date.now() - waiter.startedAt > this.operationPollLimitMs) {
            this.showNotice(`Status ${waiter.label.toLowerCase()} belum diketahui. Muat ulang halaman untuk memeriksa.`, 'warning');
            this.finishOperation(operationId, 'unknown');

            return;
        }

        try {
            // A timer polls this, not the student: the upload itself counted.
            const payload = await this.request(`/api/operations/${encodeURIComponent(operationId)}`, {
                timeout: 10000,
                redirectOnAuth: false,
                background: true,
            });

            if (payload?.data) {
                this.handleUploadProgress({ ...payload.data, operation_id: operationId });
            }
        } catch (error) {
            const label = waiter.label.toLowerCase();

            // The campus session ended (often the upload itself hit the
            // portal's login page): the outcome can only be checked after
            // signing in again.
            if (error.status === 401) {
                this.finishOperation(operationId, 'unknown');
                this.sendToLogin(`Sesi kampus berakhir sebelum status ${label} terbaca. Masuk lagi lalu cek apakah file sudah terkirim.`, 'warning');

                return;
            }

            if (error.status === 404) {
                this.finishOperation(operationId, 'unknown');
                this.showNotice(`Status ${label} tidak ditemukan lagi. Cek halaman tugas atau ujian untuk memastikan file sudah terkirim.`, 'warning');

                return;
            }
            // Connection hiccups are retried on the next tick.
        }

        const next = this.operationWaiters.get(operationId);

        if (next) {
            next.timer = window.setTimeout(() => this.pollOperation(operationId), this.operationPollIntervalMs);
        }
    }

    finishOperation(operationId, outcome) {
        const waiter = this.operationWaiters.get(operationId);

        this.finishedOperations.set(operationId, outcome);

        if (waiter) {
            window.clearTimeout(waiter.timer);
            this.operationWaiters.delete(operationId);
            waiter.resolve(outcome);
        }

        this.persistActiveOperations();

        window.setTimeout(() => {
            this.activeOperations.delete(operationId);
            const host = document.querySelector('[data-operation-status]');

            if (host && this.activeOperations.size === 0) {
                host.innerHTML = '';
            }
        }, outcome === 'completed' ? 6000 : 12000);
    }

    persistActiveOperations() {
        const operations = {};

        this.operationWaiters.forEach((waiter, operationId) => {
            operations[operationId] = { label: waiter.label, startedAt: waiter.startedAt };
        });

        try {
            if (Object.keys(operations).length === 0) {
                sessionStorage.removeItem(this.operationStorageKey);
            } else {
                sessionStorage.setItem(this.operationStorageKey, JSON.stringify(operations));
            }
        } catch (error) {
            // Storage can be unavailable (private mode); tracking still works in memory.
        }
    }

    /**
     * Pick up operations started before a reload, so their outcome is not lost.
     */
    resumeActiveOperations() {
        let operations = {};

        try {
            operations = JSON.parse(sessionStorage.getItem(this.operationStorageKey) || '{}') || {};
        } catch (error) {
            operations = {};
        }

        Object.entries(operations).forEach(([operationId, operation]) => {
            void this.trackOperation(operationId, operation?.label || 'Upload', {
                startedAt: Number(operation?.startedAt) || Date.now(),
                announce: false,
            });
        });
    }

    /**
     * Run a form's submit handler once at a time. The guard is always
     * released, so a failed attempt (wrong code, timeout) never locks the form.
     */
    async guardFormSubmit(form, callback) {
        if (!form || form.dataset.submitting === 'true') {
            return undefined;
        }

        form.dataset.submitting = 'true';

        try {
            return await callback();
        } finally {
            delete form.dataset.submitting;
        }
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
            unverified: 100,
        }[stage] || 10;
    }

    renderOperationStatus(operationId) {
        const operation = this.activeOperations.get(operationId);
        const host = document.querySelector('[data-operation-status]');

        if (!operation || !host) {
            return;
        }

        const tone = { failed: 'danger', unverified: 'warning' }[operation.status] || 'info';

        host.innerHTML = `
            <div class="${this.alertTone(tone)}">
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

    /**
     * The session ended: its device copies go, and the login page says why.
     */
    sendToLogin(message, tone = 'error') {
        this.clearAuthSnapshot();
        this.storeNotice(message, tone);
        window.location.href = '/login';
    }

    clearCachedResponses() {
        try {
            Object.keys(localStorage).forEach((key) => {
                if (key.startsWith(`${this.cacheNamespace}:response:`)) {
                    localStorage.removeItem(key);
                }
            });
        } catch (error) {
            // Storage blocked: nothing is cached on this device anyway.
        }
    }

    clearCachedResponse(url) {
        if (!url) {
            return;
        }

        try {
            const requestUrl = this.normalizeCacheUrl(new URL(url, window.location.origin));
            localStorage.removeItem(this.buildCacheKey(requestUrl));
        } catch (error) {
            // Storage blocked: nothing to clear.
        }
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
        return attendanceRules.extractAttendanceCode(value);
    }

    meetingAttendanceView(attendanceInfo, meetingDay, todayIso) {
        return attendanceRules.meetingAttendanceView(attendanceInfo, meetingDay, todayIso);
    }

    todayAttendanceNote(status) {
        return attendanceRules.todayAttendanceNote(status);
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

        // One camera at a time: a second tap while the camera starts says so.
        // A request the browser never settled (a permission prompt dismissed
        // without an answer) is given up after a while, so a tap can retry;
        // its stream, should it still arrive, is stopped as stale.
        if (this.qrScannerState.stream) {
            return;
        }

        if (this.qrScannerState.opening && Date.now() - this.qrScannerState.openingSince < this.qrScannerOpeningLimitMs) {
            this.showNotice('Kamera sedang dibuka. Izinkan akses kamera bila diminta.', 'info');

            return;
        }

        // Shortcuts (the Absen button, ?qr=1, ?absen=1) scan with what the
        // page bound to this input, so a scan sends attendance from anywhere.
        const scanOptions = { ...this.qrScannerOptionsFor(input), ...options };
        const attempt = this.qrScannerState.attempt + 1;

        this.qrScannerState.attempt = attempt;
        this.qrScannerState.opening = true;
        this.qrScannerState.openingSince = Date.now();

        try {
            const stream = await navigator.mediaDevices.getUserMedia({
                video: {
                    facingMode: { ideal: 'environment' },
                },
                audio: false,
            });

            // Closed while the camera was starting: release it right away.
            if (attempt !== this.qrScannerState.attempt) {
                stream.getTracks().forEach((track) => track.stop());

                return;
            }

            this.qrScannerState.stream = stream;
            this.qrScannerState.input = input;
            this.qrScannerState.busy = false;

            video.srcObject = stream;
            await video.play();

            if (attempt !== this.qrScannerState.attempt) {
                return;
            }

            this.qrScannerState.modal?.show();
            this.setQrScannerStatus('Arahkan kamera ke QR absensi untuk mengambil 8 karakter pertama kode.');

            const scanFrame = async () => {
                if (!this.qrScannerState.stream || attempt !== this.qrScannerState.attempt) {
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
                            scanOptions.onDetected?.(input.value, {
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
            // Closed meanwhile (play() is aborted when the stream goes away).
            if (attempt !== this.qrScannerState.attempt) {
                return;
            }

            this.showNotice('Kamera tidak bisa dibuka. Pastikan izin kamera diberikan.', 'error');
            await this.closeQrScanner();
        } finally {
            if (attempt === this.qrScannerState.attempt) {
                this.qrScannerState.opening = false;
            }
        }
    }

    /**
     * What a scan into this input does, as the page bound it with
     * bindAttendanceQrTools (the page's form handler sends the attendance).
     * An input the page never bound is only filled in.
     */
    qrScannerOptionsFor(input) {
        return (input && this.qrScannerOptions.get(input)) || {};
    }

    async closeQrScanner({ preserveHiddenState = false } = {}) {
        const modal = document.querySelector('[data-qr-scanner-modal]');
        const video = modal?.querySelector('[data-qr-scanner-video]');

        // A camera still starting is released as soon as it arrives.
        this.qrScannerState.attempt += 1;
        this.qrScannerState.opening = false;

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

        if (input) {
            this.qrScannerOptions.set(input, options);
        }

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
        try {
            const requestUrl = this.normalizeCacheUrl(new URL(url, window.location.origin));
            const record = this.readCachedResponse(requestUrl);

            if (!record) {
                return null;
            }

            return {
                ...record,
                payload: this.decorateCachedPayload(record, 'prefill'),
            };
        } catch (error) {
            return null;
        }
    }

    primeCachedView(url, render, label = null) {
        const cached = this.getCachedResponse(url);

        if (!cached) {
            return false;
        }

        try {
            render(cached.payload);
        } catch (error) {
            // A device copy from an older payload shape must not break the page;
            // the live request renders it again.
            console.warn('Cached view could not be rendered', error);

            return false;
        }

        const cacheLabel = label || cached.label || 'data';

        if (navigator.onLine) {
            this.setSyncStatus(`Menampilkan ${cacheLabel} tersimpan. Sinkronkan ke server bila perlu.`, 'info');
        } else {
            this.setSyncStatus(this.buildOfflineCacheMessage(cacheLabel, cached.isStale), 'warning');
        }

        return true;
    }

    resolveCachePolicy(requestUrl, method, options) {
        return cacheRules.resolveCachePolicy(this.cachePolicies, requestUrl, method, options, window.location.origin);
    }

    buildRequestUrl(url, options = {}) {
        return cacheRules.buildRequestUrl(url, options, window.location.origin);
    }

    normalizeCacheUrl(requestUrl) {
        return cacheRules.normalizeCacheUrl(requestUrl);
    }

    buildCacheKey(requestUrl) {
        return cacheRules.buildCacheKey(this.cacheNamespace, requestUrl);
    }

    /**
     * Keep a device copy of a response. Never throws: a full or blocked
     * storage must not turn a successful response into an error.
     */
    writeCachedResponse(requestUrl, payload, policy) {
        try {
            localStorage.setItem(this.buildCacheKey(requestUrl), JSON.stringify(cacheRules.createCacheRecord(payload, policy)));
        } catch (error) {
            // Storage full or blocked: the copy is a convenience, skip it.
        }
    }

    readCachedResponse(requestUrl) {
        const cacheKey = this.buildCacheKey(requestUrl);
        let rawRecord = null;

        try {
            rawRecord = localStorage.getItem(cacheKey);
        } catch (error) {
            return null;
        }

        if (!rawRecord) {
            return null;
        }

        const record = cacheRules.readCacheRecord(rawRecord);

        if (!record) {
            try {
                localStorage.removeItem(cacheKey);
            } catch (error) {
                // Storage blocked: ignore.
            }
        }

        return record;
    }

    pruneExpiredCacheEntries() {
        let keys = [];

        try {
            keys = Object.keys(localStorage);
        } catch (error) {
            return;
        }

        keys.forEach((key) => {
            if (!key.startsWith(`${this.cacheNamespace}:response:`)) {
                return;
            }

            try {
                if (!cacheRules.readCacheRecord(localStorage.getItem(key) || '{}')) {
                    localStorage.removeItem(key);
                }
            } catch (error) {
                // Storage blocked: ignore.
            }
        });
    }

    decorateCachedPayload(record, mode) {
        return cacheRules.decorateCachedPayload(record, mode);
    }

    isLikelyNetworkError(error) {
        return httpRules.isLikelyNetworkError(error);
    }

    createTimeoutError(message) {
        return httpRules.createTimeoutError(message);
    }

    createOfflineError(message) {
        return httpRules.createOfflineError(message);
    }

    buildOfflineCacheMessage(label, isStale) {
        return cacheRules.buildOfflineCacheMessage(label, isStale);
    }

    /**
     * The shared freshness line, in the same words as the pages' own
     * "Diperbarui …" lines: "Data diperbarui 5 menit lalu · sedang diperbarui".
     */
    markViewSynced(label, payload = null, { force = false } = {}) {
        const dataOrigin = payload?.meta?.data_origin || null;
        const portalTime = this.portalDataTime(payload);
        const updatedAgo = portalTime ? this.formatTimeAgo(portalTime) : '';
        const updatedLine = updatedAgo ? `Data diperbarui ${updatedAgo}` : 'Data baru saja dimuat';
        const integrity = payload?.meta?.integrity || null;

        if (integrity?.status === 'suspect') {
            this.setSyncStatus(`Data ${label} dari portal terlihat janggal. MyCIC tetap menampilkan versi tersimpan; periksa di menu Arsip.`, 'warning');

            return;
        }

        if (payload?.meta?.status === 'offline') {
            this.setSyncStatus(`Mode offline. Menampilkan arsip MyCIC${updatedAgo ? ` (terakhir dari portal ${updatedAgo})` : ''}.`, 'warning');

            return;
        }

        if (dataOrigin === 'archive') {
            const statusSuffix = payload?.meta?.status === 'syncing' ? ' · sedang diperbarui dari portal' : '';
            this.setSyncStatus(`Menampilkan arsip MyCIC${updatedAgo ? ` dari ${updatedAgo}` : ''}${statusSuffix}`, payload?.meta?.status === 'failed' ? 'warning' : 'info');

            return;
        }

        if (payload?.meta?.status === 'failed') {
            this.setSyncStatus(`Portal belum bisa dihubungi. Menampilkan data terakhir${updatedAgo ? ` (diperbarui ${updatedAgo})` : ''}.`, 'warning');

            return;
        }

        if (force) {
            if (dataOrigin === 'device_cache') {
                this.setSyncStatus('Server belum terjangkau. Menampilkan data tersimpan di perangkat.', 'warning');

                return;
            }

            this.setSyncStatus(updatedLine, 'success');

            return;
        }

        if (dataOrigin === 'snapshot' || dataOrigin === 'server_cache') {
            const statusSuffix = payload?.meta?.status === 'syncing' ? ' · sedang diperbarui dari portal' : '';
            this.setSyncStatus(`${updatedLine}${statusSuffix}`, 'info');

            return;
        }

        this.setSyncStatus(updatedLine, 'success');
    }

    portalDataTime(payload) {
        return dateRules.portalDataTime(payload);
    }

    /**
     * A queueable action (attendance, uploads) only needs MyCIC itself: in an
     * offline session it is stored in the outbox and sent once the portal is back.
     */
    requireOnlineAction(message = 'Fitur ini butuh koneksi internet.', { queueable = false } = {}) {
        if (this.isPortalOfflineSession() && !queueable) {
            this.showNotice(`Kamu sedang di mode offline. ${this.portalOfflineReconnectMessage()}`, 'warning');

            return false;
        }

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
        return formatRules.capitalize(value);
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

    /**
     * Button classes. Small buttons stay compact from `sm` up but keep the
     * 44 px touch target on phones (§5).
     */
    buttonClass(variant = 'primary', { small = false } = {}) {
        const variants = {
            primary: small ? 'mycic-btn-primary-sm min-h-11 sm:min-h-9' : 'mycic-btn-primary',
            secondary: small ? 'mycic-btn-secondary-sm min-h-11 sm:min-h-9' : 'mycic-btn-secondary',
            ghost: small ? 'mycic-btn-ghost-sm min-h-11 sm:min-h-9' : 'mycic-btn-ghost',
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
                        <div class="mycic-metric-value">${this.escapeHtml(item.value === null || item.value === '' ? '-' : item.value)}</div>
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
                        <div class="font-medium text-ink">${this.escapeHtml(item.value === null || item.value === '' ? '-' : item.value)}</div>
                        ${item.note ? `<div class="mt-1 text-caption text-ink-subtle">${this.escapeHtml(item.note)}</div>` : ''}
                    </div>
                `).join('')}
            </div>
        `;
    }

    /**
     * A load failure that stays on screen (toasts vanish after a few seconds),
     * with a retry button when the page knows how to try again.
     */
    renderErrorState(message, { subtitle = '', retryLabel = 'Coba lagi', retry = true } = {}) {
        return `
            <div class="mycic-card">
                <div class="mycic-card-body">
                    <div class="${this.alertTone('error')} mb-0" role="alert">
                        <div class="font-semibold">${this.escapeHtml(message)}</div>
                        ${subtitle ? `<p class="mt-1 text-sm">${this.escapeHtml(subtitle)}</p>` : ''}
                        ${retry ? `<div class="mt-3"><button type="button" class="${this.buttonClass('secondary', { small: true })}" data-error-retry>${this.escapeHtml(retryLabel)}</button></div>` : ''}
                    </div>
                </div>
            </div>
        `;
    }

    mountErrorState(host, message, onRetry = null, options = {}) {
        if (!host) {
            return;
        }

        host.innerHTML = this.renderErrorState(message, { ...options, retry: typeof onRetry === 'function' });
        host.querySelector('[data-error-retry]')?.addEventListener('click', () => {
            host.innerHTML = this.renderEmptyState('Memuat ulang...', 'secondary');
            void onRetry();
        });
    }

    /**
     * Message for a failed request, as a student should read it.
     */
    describeError(error, fallback) {
        return httpRules.describeError(error, fallback);
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

    /**
     * A page of the app loaded, so the server answers again: the offline
     * page's next retries start from the shortest wait.
     */
    forgetOfflineRetries() {
        try {
            sessionStorage.removeItem(this.offlineRetryKey);
        } catch (error) {
            // Storage blocked: the offline page could not count either.
        }
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

        const tone = toneMap[type] || toneMap.info;
        const text = String(message ?? '').trim();

        if (text === '') {
            return;
        }

        // The same message again only restarts its timer.
        const duplicate = Array.from(host.children).find((element) => element.dataset.message === text && element.dataset.tone === tone);

        if (duplicate) {
            this.scheduleToastDismiss(duplicate, tone);

            return;
        }

        while (host.children.length >= this.toastLimit) {
            this.dismissToast(host.firstElementChild);
        }

        const notice = document.createElement('div');

        notice.className = `mycic-toast mycic-toast-${tone}`;
        notice.dataset.message = text;
        notice.dataset.tone = tone;
        notice.innerHTML = `
            <span class="mycic-toast-icon mycic-toast-icon-${tone}">${this.toastIcon(tone)}</span>
            <span class="mycic-toast-message">${this.escapeHtml(text)}</span>
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
            this.dismissToast(notice);
        });

        this.announce(text, tone === 'danger' ? 'assertive' : 'polite');
        this.scheduleToastDismiss(notice, tone);
    }

    /**
     * Read a message out through the live regions that exist from page load
     * (a live region inserted together with its text is often not announced).
     */
    announce(message, politeness = 'polite') {
        const region = document.querySelector(politeness === 'assertive' ? '[data-live-assertive]' : '[data-live-polite]');

        if (!region) {
            return;
        }

        region.textContent = '';
        window.clearTimeout(Number(region.dataset.timer));
        region.dataset.timer = String(window.setTimeout(() => {
            region.textContent = message;
        }, 50));
    }

    /**
     * Errors stay long enough to be read (8 s); other toasts leave after 4.2 s.
     */
    scheduleToastDismiss(notice, tone) {
        window.clearTimeout(Number(notice.dataset.timer));
        notice.dataset.timer = String(window.setTimeout(() => {
            this.dismissToast(notice);
        }, tone === 'danger' ? 8000 : 4200));
    }

    dismissToast(notice) {
        if (!notice) {
            return;
        }

        window.clearTimeout(Number(notice.dataset.timer));
        notice.remove();
    }

    absoluteCampusUrl(path) {
        return urlRules.absoluteCampusUrl(path, this.campusBase);
    }

    buildCampusDownloadUrl(path, filename, options) {
        return urlRules.buildCampusDownloadUrl(path, filename, options);
    }

    /**
     * Download links (buildCampusDownloadUrl) answer a failure with JSON, so
     * opened in the app's own tab they would replace the page with it. They
     * are held back when they cannot work, and otherwise open in a new tab:
     * a file still downloads, an error no longer takes the app with it.
     * The installed app keeps them in its own window: on iOS a new tab opens
     * in a browser that does not share the app's cookies, so the download
     * would arrive signed out.
     */
    bindDownloadLinks() {
        document.addEventListener('click', (event) => {
            const link = event.target instanceof Element ? event.target.closest('a[href]') : null;

            if (!link) {
                return;
            }

            let url;

            try {
                url = new URL(link.getAttribute('href'), window.location.origin);
            } catch (error) {
                return;
            }

            if (url.origin !== window.location.origin || url.pathname !== '/api/support/download') {
                return;
            }

            if (this.isPortalOfflineSession() || !navigator.onLine) {
                event.preventDefault();
                this.showNotice(
                    this.isPortalOfflineSession()
                        ? `Unduhan belum tersedia di mode offline. ${this.portalOfflineReconnectMessage()}`
                        : 'Unduhan butuh koneksi internet.',
                    'warning'
                );

                return;
            }

            if (!link.target && !link.hasAttribute('download') && !this.isInstalledApp()) {
                link.target = '_blank';
                link.rel = 'noopener';
            }
        }, true);
    }

    /**
     * Whether MyCIC runs as the installed (home-screen) app rather than in a
     * browser tab.
     */
    isInstalledApp() {
        try {
            return window.navigator.standalone === true
                || Boolean(window.matchMedia?.('(display-mode: standalone)').matches);
        } catch (error) {
            return false;
        }
    }

    buildMeetingUrlFromHref(href) {
        return urlRules.buildMeetingUrlFromHref(href);
    }

    buildClassUrl(id, dosenId, courseId) {
        return urlRules.buildClassUrl(id, dosenId, courseId);
    }

    parseQueryString(value) {
        return urlRules.parseQueryString(value, this.campusBase);
    }

    extractAssignmentShortcut(value) {
        return urlRules.extractAssignmentShortcut(value, this.campusBase);
    }

    extractAttendanceShortcut(value) {
        return urlRules.extractAttendanceShortcut(value, this.campusBase);
    }

    formatDate(value) {
        return dateRules.formatDate(value);
    }

    formatTimeOnly(value) {
        return dateRules.formatTimeOnly(value);
    }

    escapeHtml(value) {
        return formatRules.escapeHtml(value);
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

    /**
     * Turn any date-like value into a Date. A plain `YYYY-MM-DD` is read as a
     * local calendar day (not UTC midnight), so it never shifts by a day.
     */
    toDate(value) {
        return dateRules.toDate(value);
    }

    /**
     * "20 Oktober 2026"; `{ weekday: true }` gives "Selasa, 20 Oktober 2026",
     * `{ month: 'short', year: false }` gives "20 Okt". Empty input gives `fallback`.
     */
    formatIndonesianDate(value, options) {
        return dateRules.formatIndonesianDate(value, options);
    }

    /**
     * Whole calendar days from `now` to `value` (negative when it has passed),
     * or null when `value` is not a date.
     */
    daysUntil(value, now) {
        return dateRules.daysUntil(value, now);
    }

    /**
     * "hari ini", "besok", "3 hari lagi", "kemarin", "lewat 2 hari".
     * `{ natural: false }` keeps "1 hari lagi" / "lewat 1 hari".
     */
    formatRelativeDays(value, options) {
        return dateRules.formatRelativeDays(value, options);
    }

    /**
     * "baru saja", "5 menit lalu", "2 jam lalu", "kemarin", "3 hari lalu":
     * for honest "Diperbarui …" labels.
     */
    formatTimeAgo(value, options) {
        return dateRules.formatTimeAgo(value, options);
    }

    /**
     * A number from 1250000, "1.250.000", "Rp 1.250.000,00" or "1250000.00";
     * null when there is no amount at all.
     */
    parseAmount(value) {
        return formatRules.parseAmount(value);
    }

    /**
     * "Rp 1.250.000". A missing amount gives `fallback` ("–"), never "Rp 0".
     */
    formatRupiah(value, options) {
        return formatRules.formatRupiah(value, options);
    }

    /**
     * Only links a student can safely follow: same-site paths, http(s),
     * mailto and tel. Anything else (javascript:, data:) gives null.
     */
    safeHref(url) {
        return urlRules.safeHref(url, window.location.origin);
    }

    isExternalHref(url) {
        return urlRules.isExternalHref(url, window.location.origin);
    }

    /**
     * Map an action-item severity (critical/high/warning/info/…) to a tone:
     * 'danger' | 'warning' | 'info' | 'success' | 'neutral'.
     */
    severityTone(severity) {
        return actionItemRules.severityTone(severity);
    }

    strongerTone(current, next) {
        return actionItemRules.strongerTone(current, next);
    }

    renderSeverityBadge(severity, label = null) {
        const tone = this.severityTone(severity);
        const defaults = {
            danger: 'Mendesak',
            warning: 'Segera',
            info: 'Info',
            success: 'Beres',
            neutral: 'Catatan',
        };

        return this.renderBadge(label || defaults[tone], tone === 'neutral' ? 'secondary' : tone);
    }

    fillToneClass(tone = 'brand') {
        const tones = {
            brand: 'bg-brand',
            primary: 'bg-brand',
            success: 'bg-success',
            warning: 'bg-warning',
            danger: 'bg-danger',
            error: 'bg-danger',
            info: 'bg-info',
            accent: 'bg-accent',
            neutral: 'bg-ink-subtle/50',
            secondary: 'bg-ink-subtle/50',
        };

        return tones[tone] || tones.brand;
    }

    /**
     * Progress bar. One value: `{ value: 62, max: 144, label: 'SKS lulus', valueLabel: '62 dari 144 SKS' }`.
     * Segments: `{ max: 144, segments: [{ value: 62, label: 'Lulus' }, { value: 20, striped: true, label: 'Sedang ditempuh' }], remainderLabel: 'Sisa' }`.
     * A null value draws an empty track with "–", never 0 %.
     */
    renderProgressBar({ value = null, max = 100, tone = 'brand', label = '', valueLabel = '', caption = '', segments = null, remainderLabel = '', size = 'md' } = {}) {
        const isNumber = (amount) => amount !== null && amount !== undefined && amount !== '' && Number.isFinite(Number(amount));
        const safeMax = Number(max) > 0 ? Number(max) : 100;
        const parts = Array.isArray(segments) && segments.length ? segments : [{ value, tone }];
        const known = parts.some((part) => isNumber(part?.value));
        const heights = { sm: 'h-1.5', md: 'h-2.5', lg: 'h-3.5' };
        let used = 0;

        const fills = parts.map((part) => {
            if (!isNumber(part?.value) || Number(part.value) <= 0) {
                return '';
            }

            const width = Math.min(100 - used, (Number(part.value) / safeMax) * 100);

            if (width <= 0) {
                return '';
            }

            used += width;

            return `<div class="mycic-progress-fill ${this.fillToneClass(part.tone || tone)}${part.striped ? ' mycic-progress-striped' : ''}" style="width: ${width.toFixed(2)}%"${part.label ? ` title="${this.escapeHtml(part.label)}"` : ''}></div>`;
        }).join('');

        const total = parts.reduce((sum, part) => sum + (isNumber(part?.value) ? Number(part.value) : 0), 0);
        const shownValue = valueLabel || (known ? `${Math.round(Math.min(100, (total / safeMax) * 100))}%` : '–');
        const legendParts = Array.isArray(segments) && segments.length
            ? [
                ...segments.filter((part) => part?.label).map((part) => `
                    <li class="inline-flex items-center gap-1.5">
                        <span class="h-2.5 w-2.5 shrink-0 rounded-sm ${this.fillToneClass(part.tone || tone)}${part.striped ? ' mycic-progress-striped' : ''}" aria-hidden="true"></span>
                        <span>${this.escapeHtml(part.label)}</span>
                        ${isNumber(part.value) ? `<span class="font-semibold tabular-nums text-ink">${this.escapeHtml(part.valueLabel ?? part.value)}</span>` : ''}
                    </li>
                `),
                remainderLabel ? `
                    <li class="inline-flex items-center gap-1.5">
                        <span class="h-2.5 w-2.5 shrink-0 rounded-sm border border-border bg-surface" aria-hidden="true"></span>
                        <span>${this.escapeHtml(remainderLabel)}</span>
                        ${known ? `<span class="font-semibold tabular-nums text-ink">${this.escapeHtml(Math.max(0, safeMax - total))}</span>` : ''}
                    </li>
                ` : '',
            ].join('')
            : '';

        return `
            <div class="min-w-0">
                ${label || valueLabel ? `
                    <div class="mb-1.5 flex items-baseline justify-between gap-3 text-sm">
                        <span class="min-w-0 truncate font-semibold text-ink">${this.escapeHtml(label)}</span>
                        <span class="shrink-0 tabular-nums text-ink-muted">${this.escapeHtml(shownValue)}</span>
                    </div>
                ` : ''}
                <div
                    class="mycic-progress-track ${heights[size] || heights.md}"
                    role="progressbar"
                    aria-valuemin="0"
                    aria-valuemax="${safeMax}"
                    ${known ? `aria-valuenow="${Math.min(safeMax, total)}"` : ''}
                    aria-valuetext="${this.escapeHtml(shownValue)}"
                    ${label ? `aria-label="${this.escapeHtml(label)}"` : ''}
                >${fills}</div>
                ${legendParts ? `<ul class="mt-2 flex flex-wrap gap-x-4 gap-y-1.5 text-caption text-ink-muted">${legendParts}</ul>` : ''}
                ${caption ? `<p class="mt-1.5 text-caption text-ink-subtle">${this.escapeHtml(caption)}</p>` : ''}
            </div>
        `;
    }

    /**
     * Hidden data table behind every chart, so values never depend on sight.
     */
    renderChartTable(caption, rows, format) {
        return `
            <table class="sr-only">
                <caption>${this.escapeHtml(caption)}</caption>
                <thead><tr><th scope="col">Periode</th><th scope="col">Nilai</th></tr></thead>
                <tbody>
                    ${rows.map((row) => `<tr><th scope="row">${this.escapeHtml(row.label)}</th><td>${this.escapeHtml(row.value === null ? '–' : format(row.value))}</td></tr>`).join('')}
                </tbody>
            </table>
        `;
    }

    /**
     * Inline SVG line for a trend such as IPS per semester:
     * `renderSparkline([{ label: 'Smt 1', value: 3.2 }, …], { label: 'IPS per semester', min: 0, max: 4, valueFormat: (v) => v.toFixed(2) })`.
     * Points without a value are left out.
     */
    renderSparkline(points = [], { label = '', min = null, max = null, width = 280, height = 72, tone = 'brand', area = true, dots = 'all', axisLabels = true, valueFormat = null } = {}) {
        const format = typeof valueFormat === 'function' ? valueFormat : (amount) => String(amount);
        const valid = (Array.isArray(points) ? points : [])
            .filter((point) => point && point.value !== null && point.value !== undefined && point.value !== '' && Number.isFinite(Number(point.value)))
            .map((point) => ({ label: String(point.label ?? ''), value: Number(point.value) }));

        if (!valid.length) {
            return this.renderEmptyState('Belum ada data untuk grafik.', 'secondary');
        }

        const values = valid.map((point) => point.value);
        const low = min ?? Math.min(...values);
        const high = max ?? Math.max(...values);
        const span = high - low || 1;
        const padX = 8;
        const padY = 8;
        const baseline = height - padY;
        const coords = valid.map((point, index) => ({
            ...point,
            x: valid.length === 1 ? width / 2 : padX + (index * (width - padX * 2)) / (valid.length - 1),
            y: padY + (1 - (Math.min(high, Math.max(low, point.value)) - low) / span) * (height - padY * 2),
        }));
        const line = coords.map((point, index) => `${index ? 'L' : 'M'}${point.x.toFixed(1)} ${point.y.toFixed(1)}`).join(' ');
        const last = coords[coords.length - 1];
        const areaPath = area && coords.length > 1
            ? `${line} L${last.x.toFixed(1)} ${baseline} L${coords[0].x.toFixed(1)} ${baseline} Z`
            : '';
        const marks = coords.map((point, index) => {
            const showDot = dots === 'all' || (dots === 'end' && index === coords.length - 1) || coords.length === 1;

            return `
                <g>
                    ${showDot ? `<circle cx="${point.x.toFixed(1)}" cy="${point.y.toFixed(1)}" r="4" fill="currentColor" stroke="var(--color-panel)" stroke-width="2"></circle>` : ''}
                    <circle cx="${point.x.toFixed(1)}" cy="${point.y.toFixed(1)}" r="12" fill="transparent"><title>${this.escapeHtml(point.label)}: ${this.escapeHtml(format(point.value))}</title></circle>
                </g>
            `;
        }).join('');
        const summary = label || 'Grafik tren';

        return `
            <figure class="m-0 min-w-0">
                <svg class="block h-auto w-full overflow-visible ${this.textToneClass(tone)}" viewBox="0 0 ${width} ${height}" role="img" aria-label="${this.escapeHtml(summary)}">
                    <line x1="${padX}" y1="${baseline}" x2="${width - padX}" y2="${baseline}" stroke="var(--color-border-light)" stroke-width="1"></line>
                    ${areaPath ? `<path d="${areaPath}" fill="currentColor" fill-opacity="0.1" stroke="none"></path>` : ''}
                    ${coords.length > 1 ? `<path d="${line}" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path>` : ''}
                    ${marks}
                </svg>
                ${axisLabels ? `
                    <figcaption class="mt-1 flex items-baseline justify-between gap-3 text-caption text-ink-subtle">
                        <span class="truncate">${coords.length > 1 ? this.escapeHtml(coords[0].label) : ''}</span>
                        <span class="shrink-0 tabular-nums">${this.escapeHtml(last.label)} · <span class="font-semibold text-ink">${this.escapeHtml(format(last.value))}</span></span>
                    </figcaption>
                ` : ''}
                ${this.renderChartTable(summary, coords, format)}
            </figure>
        `;
    }

    /**
     * Column chart (CSS, no library) for a value per period such as IPS:
     * `renderBarChart([{ label: 'Smt 1', value: 3.2 }, { label: 'Smt 2', value: null }], { label: 'IPS per semester', max: 4 })`.
     * A null value shows "–" without a bar. The last column with a value is
     * emphasised (`highlight: 'last'`); pass `highlight: null` for equal bars.
     */
    renderBarChart(items = [], { label = '', max = null, tone = 'brand', valueFormat = null, heightClass = 'h-36', highlight = 'last', emptyLabel = '–' } = {}) {
        const format = typeof valueFormat === 'function' ? valueFormat : (amount) => String(amount);
        const rows = (Array.isArray(items) ? items : []).map((item) => {
            const raw = item?.value;
            const hasValue = raw !== null && raw !== undefined && raw !== '' && Number.isFinite(Number(raw));

            return {
                label: String(item?.label ?? ''),
                value: hasValue ? Number(raw) : null,
                tone: item?.tone || null,
            };
        });

        if (!rows.length) {
            return this.renderEmptyState('Belum ada data untuk grafik.', 'secondary');
        }

        const numeric = rows.filter((row) => row.value !== null).map((row) => row.value);
        const top = max ?? (Math.max(0, ...numeric) || 1);
        let emphasised = -1;

        if (highlight === 'last') {
            rows.forEach((row, index) => {
                if (row.value !== null) {
                    emphasised = index;
                }
            });
        } else if (Number.isInteger(highlight)) {
            emphasised = highlight;
        }

        const columns = rows.map((row, index) => {
            const percent = row.value === null ? 0 : Math.max(0, Math.min(100, (row.value / top) * 100));
            const muted = emphasised !== -1 && index !== emphasised;
            const valueText = row.value === null ? emptyLabel : format(row.value);

            return `
                <div class="flex min-w-0 flex-1 flex-col items-center gap-1" title="${this.escapeHtml(row.label)}: ${this.escapeHtml(valueText)}">
                    <span class="text-caption font-semibold tabular-nums ${row.value === null ? 'text-ink-subtle' : 'text-ink'}">${this.escapeHtml(valueText)}</span>
                    <div class="flex w-full flex-1 items-end justify-center border-b border-border px-0.5 sm:px-1.5">
                        ${row.value === null ? '' : `<div class="w-full max-w-6 rounded-t ${this.fillToneClass(row.tone || tone)}${muted ? ' opacity-40' : ''}" style="height: ${percent.toFixed(1)}%"></div>`}
                    </div>
                    <span class="w-full truncate text-center text-[0.65rem] font-medium text-ink-subtle">${this.escapeHtml(row.label)}</span>
                </div>
            `;
        }).join('');
        const summary = label || 'Grafik batang';

        return `
            <figure class="m-0 min-w-0">
                <div class="flex ${heightClass} items-stretch" role="img" aria-label="${this.escapeHtml(summary)}">${columns}</div>
                ${this.renderChartTable(summary, rows, format)}
            </figure>
        `;
    }

    /**
     * One horizontal stacked bar with a legend, e.g. the A–E grade spread:
     * `renderStackedBar([{ label: 'A', value: 3, tone: 'success' }, { label: 'B', value: 3, tone: 'info' }], { label: 'Sebaran nilai' })`.
     */
    renderStackedBar(segments = [], { label = '', valueFormat = null, legend = true, size = 'md' } = {}) {
        const format = typeof valueFormat === 'function' ? valueFormat : (amount) => String(amount);
        const rows = (Array.isArray(segments) ? segments : [])
            .filter((segment) => segment && segment.label !== undefined)
            .map((segment) => ({
                label: String(segment.label),
                value: Math.max(0, Number(segment.value) || 0),
                tone: segment.tone || 'brand',
            }));
        const total = rows.reduce((sum, row) => sum + row.value, 0);
        const heights = { sm: 'h-2', md: 'h-3', lg: 'h-4' };
        const summary = label || 'Komposisi';
        const fills = total > 0
            ? rows.filter((row) => row.value > 0).map((row) => `
                <div class="mycic-progress-fill ${this.fillToneClass(row.tone)}" style="width: ${((row.value / total) * 100).toFixed(2)}%" title="${this.escapeHtml(row.label)}: ${this.escapeHtml(format(row.value))}"></div>
            `).join('')
            : '';

        return `
            <figure class="m-0 min-w-0">
                <div class="mycic-progress-track ${heights[size] || heights.md}" role="img" aria-label="${this.escapeHtml(summary)}">${fills}</div>
                ${legend && rows.length ? `
                    <ul class="mt-2 flex flex-wrap gap-x-4 gap-y-1.5 text-caption text-ink-muted">
                        ${rows.map((row) => `
                            <li class="inline-flex items-center gap-1.5">
                                <span class="h-2.5 w-2.5 shrink-0 rounded-sm ${this.fillToneClass(row.tone)}" aria-hidden="true"></span>
                                <span>${this.escapeHtml(row.label)}</span>
                                <span class="font-semibold tabular-nums text-ink">${this.escapeHtml(format(row.value))}</span>
                            </li>
                        `).join('')}
                    </ul>
                ` : ''}
                ${this.renderChartTable(summary, rows, format)}
            </figure>
        `;
    }

    /**
     * One row of the action centre, as served by `GET /api/action-items`:
     * `{type, title, description, due_at, days_left, severity, cta_label, cta_url, cta_external, source}`.
     */
    renderActionItem(item = {}) {
        const days = Number.isInteger(item.days_left) ? item.days_left : (item.due_at ? this.daysUntil(item.due_at) : null);
        let dueText = '';

        if (days !== null) {
            const dateText = item.due_at ? ` · ${this.formatIndonesianDate(item.due_at, { month: 'short', year: false })}` : '';
            const relative = days === 0 ? 'hari ini' : (days === 1 ? 'besok' : `${days} hari lagi`);

            dueText = days < 0
                ? `Lewat ${Math.abs(days)} hari${dateText}`
                : `Tenggat ${relative}${dateText}`;
        }

        const dueTone = days !== null && days <= 1 ? 'text-danger' : (days !== null && days <= 3 ? 'text-warning' : 'text-ink-subtle');
        const href = this.safeHref(item.cta_url);
        const external = href && (item.cta_external === true || this.isExternalHref(href)) ? ' target="_blank" rel="noopener noreferrer"' : '';
        const content = `
            <span class="flex min-w-0 flex-1 flex-col gap-1">
                <span class="flex flex-wrap items-center gap-2">
                    ${this.renderSeverityBadge(item.severity)}
                    ${dueText ? `<span class="text-caption font-semibold ${dueTone}">${this.escapeHtml(dueText)}</span>` : ''}
                </span>
                <span class="text-sm font-semibold text-ink">${this.escapeHtml(item.title || 'Perlu dicek')}</span>
                ${item.description ? `<span class="line-clamp-2 text-caption text-ink-subtle">${this.escapeHtml(item.description)}</span>` : ''}
            </span>
            ${href ? `
                <span class="inline-flex shrink-0 items-center gap-1 self-center text-xs font-semibold text-brand-pressed">
                    ${this.escapeHtml(item.cta_label || 'Buka')}
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6l-6 6" /></svg>
                </span>
            ` : ''}
        `;

        return href
            ? `<a href="${this.escapeHtml(href)}" class="mycic-list-row min-h-11 rounded-md transition-colors hover:bg-surface/60 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand"${external}>${content}</a>`
            : `<div class="mycic-list-row">${content}</div>`;
    }

    /**
     * Action items (`GET /api/action-items`), read once per page load: a fresh
     * device copy is used as is, a stale or missing one is fetched once.
     * Resolves to the item list ([] when unavailable) and fires
     * `portal:action-items` on window with `{ items }`. `background`: read on
     * the app's own initiative (nav badges, a realtime event).
     */
    loadActionItems({ force = false, background = undefined } = {}) {
        if (document.body.dataset.auth !== 'required') {
            return Promise.resolve([]);
        }

        if (this.actionItemsRequest && !force) {
            return this.actionItemsRequest;
        }

        const cached = force ? null : this.getCachedResponse('/api/action-items');

        if (cached && !cached.isStale) {
            const items = this.extractActionItems(cached.payload);

            this.receiveActionItems(items);
            this.actionItemsRequest = Promise.resolve(items);

            return this.actionItemsRequest;
        }

        this.actionItemsRequest = (async () => {
            try {
                const payload = await this.request('/api/action-items', { redirectOnAuth: false, timeout: 15000, background });
                const items = this.extractActionItems(payload);

                this.receiveActionItems(items);

                return items;
            } catch (error) {
                return this.actionItems || [];
            }
        })();

        return this.actionItemsRequest;
    }

    extractActionItems(payload) {
        return actionItemRules.extractActionItems(payload);
    }

    /**
     * Whether this device marked an announcement read, under its id or the
     * wording-based id (`legacy_id`) marks were saved with before stable ids.
     */
    isAnnouncementRead(readIds, item) {
        return announcementRules.isAnnouncementRead(readIds, item);
    }

    /**
     * Announcement read marks with those saved under an old wording-based id
     * moved to the id `items` carry now: `{ ids, changed }`.
     */
    migrateAnnouncementReadIds(ids, items) {
        return announcementRules.migrateAnnouncementReadIds(ids, items);
    }

    /**
     * A record keyed by announcement id (ticked steps) with the entry saved
     * under `item`'s old wording-based id moved to its id: `{ record, changed }`.
     */
    migrateAnnouncementRecord(record, item) {
        return announcementRules.migrateAnnouncementRecord(record, item);
    }

    receiveActionItems(items) {
        this.actionItems = Array.isArray(items) ? items : [];
        this.applyActionItemBadges(this.actionItems);
        window.dispatchEvent(new CustomEvent('portal:action-items', { detail: { items: this.actionItems } }));
    }

    /**
     * Paint stored badges at once, then read action items when the browser is
     * idle so the page's own data loads first.
     */
    scheduleActionItemBadges() {
        if (document.body.dataset.auth !== 'required') {
            return;
        }

        const cached = this.getCachedResponse('/api/action-items');

        if (cached && !cached.isStale) {
            void this.loadActionItems({ background: true });

            return;
        }

        if (cached) {
            this.receiveActionItems(this.extractActionItems(cached.payload));
        }

        if (!navigator.onLine) {
            return;
        }

        const run = () => {
            void this.loadActionItems({ background: true });
        };

        if (typeof window.requestIdleCallback === 'function') {
            window.requestIdleCallback(run, { timeout: 3000 });
        } else {
            window.setTimeout(run, 1200);
        }
    }

    /**
     * Badge key for one action item: krs, grades, transcript, finance, skkm,
     * announcement, profile, password, exam or class (from `cta_url`, else
     * from `source`/`type`); null when it belongs nowhere in the nav.
     */
    actionItemBadgeKey(item) {
        return actionItemRules.actionItemBadgeKey(item, window.location.origin);
    }

    /**
     * Count action items per badge: tiles (krs, grades, transcript, finance,
     * skkm, announcement, profile, password, exam) and nav slots (akademik =
     * KRS/nilai/transkrip, keuangan = tagihan, lainnya = the mobile /more
     * slot with tagihan, lainnya-desktop = the desktop dropdown without it,
     * kuliah = exams and critical class items). Only items that need action
     * (critical or warning) light a badge; informational ones stay in the
     * action center. A value set with setNavBadge wins.
     */
    applyActionItemBadges(items = []) {
        const { counts, tones } = actionItemRules.countActionItemBadges(items, window.location.origin);

        const keys = new Set([
            ...Array.from(document.querySelectorAll('[data-nav-badge]')).map((element) => element.dataset.navBadge),
            ...counts.keys(),
            ...this.navBadgeOverrides.keys(),
        ]);

        keys.forEach((key) => {
            const override = this.navBadgeOverrides.get(key);

            if (override) {
                this.paintNavBadge(key, override.value, override);

                return;
            }

            const tone = tones.get(key);

            this.paintNavBadge(key, counts.get(key) || 0, {
                tone: tone === 'danger' || tone === 'warning' ? tone : 'brand',
            });
        });
    }

    /**
     * Show a badge on every `[data-nav-badge="<key>"]` element: nav slots
     * (kuliah, akademik, keuangan, lainnya) and /more tiles (krs, grades,
     * transcript, finance, skkm, announcement, profile, password). A page can
     * add its own `data-nav-badge` element and it is painted too.
     * `value`: a count, `true` for a dot, 0/false to hide; `null` drops the
     * page's value and returns to the action-item count.
     * `tone`: 'danger' (default) | 'warning' | 'brand'.
     */
    setNavBadge(key, value, { tone = 'danger', label = '' } = {}) {
        if (!key) {
            return;
        }

        if (value === null || value === undefined) {
            this.navBadgeOverrides.delete(key);
            this.applyActionItemBadges(this.actionItems || []);

            return;
        }

        this.navBadgeOverrides.set(key, { value, tone, label });
        this.paintNavBadge(key, value, { tone, label });
    }

    paintNavBadge(key, value, { tone = 'danger', label = '' } = {}) {
        const isDot = value === true;
        const count = typeof value === 'number' ? Math.max(0, Math.floor(value)) : (value ? 1 : 0);
        const description = label || `${count} hal perlu dicek`;

        document.querySelectorAll(`[data-nav-badge="${CSS.escape(String(key))}"]`).forEach((badge) => {
            if (!count) {
                badge.classList.add('hidden');
                badge.textContent = '';
                badge.removeAttribute('title');
                delete badge.dataset.variant;

                return;
            }

            badge.classList.remove('hidden');
            badge.dataset.tone = tone;
            badge.title = description;

            if (isDot) {
                badge.dataset.variant = 'dot';
                badge.innerHTML = `<span class="sr-only">${this.escapeHtml(description)}</span>`;

                return;
            }

            delete badge.dataset.variant;
            badge.innerHTML = `<span aria-hidden="true">${count > 9 ? '9+' : count}</span><span class="sr-only">${this.escapeHtml(description)}</span>`;
        });
    }

    /**
     * Digits for wa.me: "0812-3456-7890", "+62 812…" and "812…" all become
     * "62812…". Only the first number of "0812… / 0813…" is used. Null when
     * no plausible number is left.
     */
    normalizeWhatsAppNumber(value) {
        return contactRules.normalizeWhatsAppNumber(value);
    }

    /**
     * Links for a contact: `{ whatsapp, tel, mailto, phoneLabel, email }`.
     * WhatsApp uses `contact.whatsapp`, else `contact.phone` when it is a
     * mobile number (62 8…); `contact.message` pre-fills the chat.
     */
    buildContactLinks(contact) {
        return contactRules.buildContactLinks(contact);
    }

    /**
     * The action list of a contact sheet (WhatsApp, Telepon, Email, Salin),
     * usable inline in a card too.
     */
    renderContactActions(contact = {}) {
        const links = this.buildContactLinks(contact);
        const icons = {
            whatsapp: '<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 21l1.65 -3.8a9 9 0 1 1 3.4 2.9l-5.05 .9" /><path d="M9 10a.5 .5 0 0 0 1 0v-1a.5 .5 0 0 0 -1 0v1a5 5 0 0 0 5 5h1a.5 .5 0 0 0 0 -1h-1a.5 .5 0 0 0 0 1" /></svg>',
            tel: '<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 4h4l2 5l-2.5 1.5a11 11 0 0 0 5 5l1.5 -2.5l5 2v4a2 2 0 0 1 -2 2a16 16 0 0 1 -15 -15a2 2 0 0 1 2 -2" /></svg>',
            mail: '<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7a2 2 0 0 1 2 -2h14a2 2 0 0 1 2 2v10a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2v-10z" /><path d="M3 7l9 6l9 -6" /></svg>',
            copy: '<svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 7m0 2.667a2.667 2.667 0 0 1 2.667 -2.667h8.666a2.667 2.667 0 0 1 2.667 2.667v8.666a2.667 2.667 0 0 1 -2.667 2.667h-8.666a2.667 2.667 0 0 1 -2.667 -2.667z" /><path d="M4.012 16.737a2.005 2.005 0 0 1 -1.012 -1.737v-10c0 -1.1 .9 -2 2 -2h10c.75 0 1.158 .385 1.5 1" /></svg>',
        };
        const row = (href, icon, title, detail, external = false) => `
            <a href="${this.escapeHtml(href)}" class="mycic-sheet-action"${external ? ' target="_blank" rel="noopener noreferrer"' : ''}>
                <span class="mycic-sheet-action-icon">${icon}</span>
                <span class="min-w-0 flex-1">
                    <span class="block font-semibold text-ink">${this.escapeHtml(title)}</span>
                    ${detail ? `<span class="block truncate text-caption text-ink-subtle">${this.escapeHtml(detail)}</span>` : ''}
                </span>
            </a>
        `;
        const rows = [
            links.whatsapp ? row(links.whatsapp, icons.whatsapp, 'WhatsApp', contact.message ? 'Pesan sudah disiapkan, tinggal kirim' : links.phoneLabel, true) : '',
            links.tel ? row(links.tel, icons.tel, 'Telepon', links.phoneLabel) : '',
            links.mailto ? row(links.mailto, icons.mail, 'Email', links.email) : '',
        ].filter(Boolean);

        if (!rows.length) {
            return this.renderEmptyState('Kontak belum tersedia di portal.', 'secondary', 'Tanyakan lewat BAAK atau buka Portal kampus.');
        }

        const copies = [
            links.phoneDigits ? `<button type="button" class="${this.buttonClass('secondary', { small: true })}" data-copy-text="${this.escapeHtml(links.phoneLocal)}" data-copy-label="Nomor">${icons.copy}Salin nomor</button>` : '',
            links.email ? `<button type="button" class="${this.buttonClass('secondary', { small: true })}" data-copy-text="${this.escapeHtml(links.email)}" data-copy-label="Email">${icons.copy}Salin email</button>` : '',
        ].filter(Boolean).join('');

        return `
            <div class="flex flex-col gap-2">
                ${rows.join('')}
                ${copies ? `<div class="flex flex-wrap gap-2 pt-1">${copies}</div>` : ''}
            </div>
        `;
    }

    /**
     * Open the contact bottom sheet (K6) for a lecturer or DPA:
     * `openContactSheet({ name, role, email, phone, whatsapp, message })`.
     */
    openContactSheet(contact = {}) {
        return this.openSheet({
            pretitle: contact.role || 'Kontak',
            title: contact.name || 'Kontak',
            body: this.renderContactActions(contact),
        });
    }

    /**
     * Same as openContactSheet (the K6 name): opens the sheet and returns its panel.
     */
    renderContactSheet(contact = {}) {
        return this.openContactSheet(contact);
    }

    /**
     * A button that opens the contact sheet, safe to put in any rendered HTML.
     */
    renderContactButton(contact = {}, { label = 'Hubungi', variant = 'secondary', small = true, className = '' } = {}) {
        return `
            <button type="button" class="${this.buttonClass(variant, { small })}${className ? ` ${this.escapeHtml(className)}` : ''}" data-contact-sheet="${this.escapeHtml(JSON.stringify(contact || {}))}">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 21l1.65 -3.8a9 9 0 1 1 3.4 2.9l-5.05 .9" /></svg>
                ${this.escapeHtml(label)}
            </button>
        `;
    }

    bindContactSheetTriggers() {
        document.addEventListener('click', (event) => {
            const target = event.target instanceof Element ? event.target : null;
            const contactTrigger = target?.closest('[data-contact-sheet]');

            if (contactTrigger) {
                event.preventDefault();

                let contact = {};

                try {
                    contact = JSON.parse(contactTrigger.getAttribute('data-contact-sheet') || '{}') || {};
                } catch (error) {
                    contact = {};
                }

                this.openContactSheet(contact);

                return;
            }

            const copyTrigger = target?.closest('[data-copy-text]');

            if (copyTrigger) {
                event.preventDefault();
                void this.copyText(copyTrigger.getAttribute('data-copy-text'), copyTrigger.getAttribute('data-copy-label') || 'Teks');
            }
        });
    }

    /**
     * Copy text and say so in a toast ("Nomor disalin."). Resolves to true on success.
     */
    async copyText(text, label = 'Teks') {
        const value = String(text ?? '');

        if (value === '') {
            return false;
        }

        try {
            if (navigator.clipboard?.writeText && window.isSecureContext) {
                await navigator.clipboard.writeText(value);
            } else {
                const field = document.createElement('textarea');

                field.value = value;
                field.setAttribute('readonly', '');
                field.className = 'sr-only';
                document.body.appendChild(field);
                field.select();

                const copied = document.execCommand('copy');

                field.remove();

                if (!copied) {
                    throw new Error('Copy failed');
                }
            }

            this.showNotice(`${label} disalin.`, 'success');

            return true;
        } catch (error) {
            this.showNotice(`${label} gagal disalin. Salin manual: ${value}`, 'warning');

            return false;
        }
    }

    ensureSheetHost() {
        if (this.sheetState.host?.isConnected) {
            return this.sheetState.host;
        }

        const host = document.createElement('div');

        host.className = 'mycic-sheet hidden';
        host.setAttribute('data-app-sheet', '');
        host.setAttribute('aria-hidden', 'true');
        host.innerHTML = `
            <div class="mycic-sheet-backdrop" data-app-sheet-close></div>
            <div class="mycic-sheet-panel" role="dialog" aria-modal="true" aria-labelledby="app-sheet-title" tabindex="-1" data-app-sheet-panel>
                <div class="mycic-sheet-handle" aria-hidden="true"></div>
                <div class="mycic-modal-header">
                    <div class="min-w-0">
                        <div class="text-caption uppercase tracking-wide text-ink-subtle" data-app-sheet-pretitle></div>
                        <h2 class="text-subtitle font-bold text-ink" id="app-sheet-title" data-app-sheet-title></h2>
                    </div>
                    <button type="button" class="mycic-modal-close" aria-label="Tutup" data-app-sheet-close>
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6l-12 12" /><path d="M6 6l12 12" /></svg>
                    </button>
                </div>
                <div class="mycic-modal-body" data-app-sheet-body></div>
            </div>
        `;

        host.addEventListener('click', (event) => {
            if (event.target instanceof Element && event.target.closest('[data-app-sheet-close]')) {
                this.closeSheet();
            }
        });

        host.addEventListener('keydown', (event) => this.handleSheetKeydown(event));
        document.body.appendChild(host);
        this.sheetState.host = host;

        return host;
    }

    /**
     * Generic bottom sheet (dialog on md+): `openSheet({ title, pretitle, body, onClose })`.
     * `body` is an HTML string or a Node. Focus is trapped inside, Escape and
     * the backdrop close it, and focus returns to where it was. Returns the panel.
     */
    openSheet({ title = '', pretitle = '', body = '', onClose = null } = {}) {
        const host = this.ensureSheetHost();
        const panel = host.querySelector('[data-app-sheet-panel]');
        const pretitleNode = host.querySelector('[data-app-sheet-pretitle]');
        const bodyHost = host.querySelector('[data-app-sheet-body]');
        const alreadyOpen = !host.classList.contains('hidden');

        if (alreadyOpen) {
            this.sheetState.onClose?.();
        } else {
            this.sheetState.returnFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        }

        host.querySelector('[data-app-sheet-title]').textContent = title;
        pretitleNode.textContent = pretitle;
        pretitleNode.classList.toggle('hidden', !pretitle);

        if (body instanceof Node) {
            bodyHost.replaceChildren(body);
        } else {
            bodyHost.innerHTML = body;
        }

        this.sheetState.onClose = typeof onClose === 'function' ? onClose : null;
        host.classList.remove('hidden');
        host.setAttribute('aria-hidden', 'false');
        document.documentElement.classList.add('overflow-hidden');
        panel.focus();

        return panel;
    }

    closeSheet({ restoreFocus = true } = {}) {
        const host = this.sheetState.host;

        if (!host || host.classList.contains('hidden')) {
            return;
        }

        host.classList.add('hidden');
        host.setAttribute('aria-hidden', 'true');
        document.documentElement.classList.remove('overflow-hidden');
        host.querySelector('[data-app-sheet-body]').innerHTML = '';

        const onClose = this.sheetState.onClose;
        const returnFocus = this.sheetState.returnFocus;

        this.sheetState.onClose = null;
        this.sheetState.returnFocus = null;

        if (restoreFocus && returnFocus?.isConnected) {
            returnFocus.focus();
        }

        onClose?.();
    }

    focusableElements(root) {
        if (!root) {
            return [];
        }

        return Array.from(root.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'))
            .filter((element) => element.getClientRects().length > 0);
    }

    handleSheetKeydown(event) {
        if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            this.closeSheet();

            return;
        }

        if (event.key !== 'Tab') {
            return;
        }

        this.trapTabFocus(event, this.sheetState.host?.querySelector('[data-app-sheet-panel]'));
    }

    /**
     * Keep Tab and Shift+Tab inside an open dialog panel.
     */
    trapTabFocus(event, panel) {
        const focusable = this.focusableElements(panel);

        if (!focusable.length) {
            event.preventDefault();
            panel?.focus();

            return;
        }

        const first = focusable[0];
        const last = focusable[focusable.length - 1];

        if (event.shiftKey && (document.activeElement === first || document.activeElement === panel)) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }
}

window.portalApp = new PortalApp();
// Before any page script can prime a view from the device cache.
window.portalApp.isolateDeviceCache();

document.addEventListener('DOMContentLoaded', () => {
    window.portalApp.init();
});
