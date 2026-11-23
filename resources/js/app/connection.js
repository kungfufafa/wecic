import { ui } from './ui.js';

/**
 * Online/offline state and the sync strip: the connection chip, the sync
 * status line, the handlers a page registers to re-read itself, background
 * work, and the "Sinkronkan data" button that asks the server to re-scrape
 * the portal.
 *
 * Methods of PortalApp (resources/js/app.js mixes them in): `this` is the
 * app, and every field they use is set in its constructor.
 */
export class ConnectionFeature {
    bindConnectionListeners() {
        window.addEventListener('online', () => {
            this.updateConnectionState(true);
        });

        window.addEventListener('offline', () => {
            this.updateConnectionState(false);
        });
    }

    updateConnectionState(isOnline, { announce = true } = {}) {
        const previousState = this.onlineState;
        this.onlineState = isOnline;

        document.querySelectorAll('[data-connection-chip]').forEach((element) => {
            element.className = isOnline ? ui.chipOnline : ui.chipOffline;
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
            'notebook-review': [],
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

    setSyncStatus(message, tone = 'info') {
        document.querySelectorAll('[data-sync-status]').forEach((element) => {
            element.textContent = message;
            element.dataset.tone = tone;
        });

        const stripClass = {
            success: ui.syncStrip.success,
            warning: ui.syncStrip.warning,
            error: ui.syncStrip.danger,
            danger: ui.syncStrip.danger,
        }[tone] || ui.syncStrip.base;

        document.querySelectorAll('[data-sync-alert]').forEach((element) => {
            element.className = stripClass;
        });
    }

    defaultSyncStatusMessage() {
        return 'Data siap dipakai.';
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
}
