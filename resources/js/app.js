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
        this.protectedPageCacheName = 'mycic-pages-v1';
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
        this.bindPullToRefresh();
        this.updateConnectionState(navigator.onLine, { announce: false });
        this.pruneExpiredCacheEntries();
        this.refreshOnlineRequiredState();
        this.bindQrScannerUi();

        if (document.body.dataset.auth !== 'guest') {
            const authSnapshot = this.getAuthSnapshot();

            if (authSnapshot) {
                this.hydrateUser(authSnapshot);
            }
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

    bindQrScannerUi() {
        const modal = document.querySelector('[data-qr-scanner-modal]');

        if (!modal) {
            return;
        }

        modal.addEventListener('click', (event) => {
            if (event.target === modal) {
                void this.closeQrScanner();
            }
        });

        modal.querySelector('[data-qr-scanner-close]')?.addEventListener('click', () => {
            void this.closeQrScanner();
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

            await this.runSyncHandlers();

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
            element.dataset.state = isOnline ? 'online' : 'offline';
        });

        document.querySelectorAll('[data-connection-label]').forEach((element) => {
            element.textContent = isOnline ? 'Online' : 'Offline';
        });

        this.refreshOnlineRequiredState();

        if (!isOnline) {
            this.setSyncStatus('Internet terputus. Data terakhir akan dipakai bila tersedia.', 'warning');

            if (announce && previousState !== false) {
                this.showNotice('Koneksi internet terputus. Menampilkan data terakhir yang tersimpan.', 'warning');
            }

            return;
        }

        if (previousState === false) {
            this.setSyncStatus('Koneksi kembali. Menyegarkan data terbaru...', 'success');

            if (announce) {
                this.showNotice('Koneksi kembali. Data terbaru sedang disinkronkan.', 'success');
            }

            void this.runSyncHandlers();

            return;
        }

        if (!document.querySelector('[data-sync-status]')?.textContent?.trim()) {
            this.setSyncStatus('Online. Data terbaru akan disegarkan otomatis.', 'info');
        }
    }

    registerSyncHandler(key, handler) {
        if (!key || typeof handler !== 'function') {
            return;
        }

        this.syncHandlers.set(key, handler);
    }

    async runSyncHandlers() {
        if (this.isSyncing || !navigator.onLine || this.syncHandlers.size === 0) {
            return;
        }

        this.isSyncing = true;
        this.setSyncStatus('Online. Menyegarkan data terbaru...', 'info');

        let refreshedCount = 0;

        for (const handler of this.syncHandlers.values()) {
            try {
                await handler();
                refreshedCount += 1;
            } catch (error) {
                console.error('Sync handler failed', error);
            }
        }

        this.isSyncing = false;

        if (refreshedCount > 0) {
            this.setSyncStatus(`Online. Tersinkron ${this.formatTimeOnly(new Date())}.`, 'success');

            return;
        }

        this.setSyncStatus('Online. Tidak ada data yang perlu disegarkan.', 'info');
    }

    async request(url, options = {}) {
        const method = String(options.method || 'GET').toUpperCase();
        const requestUrl = new URL(url, window.location.origin);
        const cachePolicy = this.resolveCachePolicy(requestUrl, method, options);
        const cachedResponse = cachePolicy ? this.readCachedResponse(requestUrl) : null;

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
                this.storeNotice(payload?.message || 'Sesi kampus sudah berakhir.', 'warning');
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
                this.writeCachedResponse(requestUrl, payload, cachePolicy);
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
        const userInfo = payload?.data?.user_info || {};
        const userIdentity = userInfo.student_id || userInfo.nim || userInfo.username || '';

        document.querySelectorAll('[data-user-identity]').forEach((element) => {
            element.textContent = userIdentity;
            element.classList.toggle('hidden', userIdentity === '');
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

                this.clearOfflineData();
                window.location.href = '/login';
            });
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

    clearOfflineData() {
        this.clearAuthSnapshot();
        this.clearCachedResponses();
        this.clearProtectedPageCache();
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
        const digits = String(value || '').replace(/\D+/g, '');

        if (digits.length < 8) {
            return null;
        }

        return digits.slice(0, 8);
    }

    fillAttendanceCode(input, rawValue) {
        const code = this.extractAttendanceCode(rawValue);

        if (!code) {
            this.showNotice('QR terdeteksi, tetapi tidak menemukan 8 digit kode absensi.', 'warning');

            return false;
        }

        input.value = code;
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
        this.showNotice(`Kode absensi ${code} terisi otomatis.`, 'success');

        return true;
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

    async decodeQrFromFile(file, input) {
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

            return this.fillAttendanceCode(input, rawValue);
        } catch (error) {
            this.showNotice('Foto QR gagal diproses.', 'error');

            return false;
        }
    }

    async openQrScanner(input) {
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

            modal.classList.remove('hidden');
            modal.setAttribute('aria-hidden', 'false');
            this.setQrScannerStatus('Arahkan kamera ke QR absensi untuk mengambil 8 digit kode pertama.');

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

    async closeQrScanner() {
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

        if (modal) {
            modal.classList.add('hidden');
            modal.setAttribute('aria-hidden', 'true');
        }

        this.setQrScannerStatus('Arahkan kamera ke QR absensi untuk mengambil 8 digit kode pertama.');
    }

    setQrScannerStatus(message, tone = 'info') {
        const status = document.querySelector('[data-qr-scanner-status]');

        if (!status) {
            return;
        }

        status.textContent = message;
        status.dataset.tone = tone;
    }

    bindAttendanceQrTools(container, input) {
        const scanButton = container.querySelector('[data-qr-scan]');
        const fileInput = container.querySelector('[data-qr-file]');

        scanButton?.addEventListener('click', async () => {
            await this.openQrScanner(input);
        });

        fileInput?.addEventListener('change', async () => {
            const file = fileInput.files?.[0];

            if (!file) {
                return;
            }

            await this.decodeQrFromFile(file, input);
            fileInput.value = '';
        });
    }

    isInteractiveTarget(target) {
        return target instanceof Element && Boolean(target.closest('button, a, input, textarea, select, label, summary, [role="button"]'));
    }

    setPullIndicator({ distance = 0, armed = false, refreshing = false }) {
        const indicator = document.querySelector('[data-pull-indicator]');

        if (!indicator) {
            return;
        }

        indicator.classList.toggle('is-visible', distance > 0 || refreshing);
        indicator.dataset.state = refreshing ? 'refreshing' : armed ? 'armed' : 'idle';
        indicator.style.setProperty('--pull-distance', `${distance}px`);

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

        if (!indicator) {
            return;
        }

        indicator.dataset.state = 'idle';
        indicator.style.setProperty('--pull-distance', '0px');
        indicator.classList.remove('is-visible');

        const label = indicator.querySelector('[data-pull-copy]');

        if (label) {
            label.textContent = 'Tarik untuk memperbarui';
        }
    }

    getCachedResponse(url) {
        const requestUrl = new URL(url, window.location.origin);
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
            this.setSyncStatus(`Menampilkan ${cacheLabel} tersimpan. Memeriksa pembaruan...`, 'info');
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
        return `Offline. Menampilkan ${label} terakhir${isStale ? ' yang mungkin sudah lama' : ''}.`;
    }

    markViewSynced(label) {
        this.setSyncStatus(`Online. ${label} diperbarui ${this.formatTimeOnly(new Date())}.`, 'success');
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

    showNotice(message, type = 'info') {
        const host = document.getElementById('notice-host');

        if (!host) {
            return;
        }

        const toneMap = {
            success: 'var(--notice-success)',
            error: 'var(--notice-error)',
            warning: 'var(--notice-warning)',
            info: 'var(--notice-info)',
        };

        host.innerHTML = '';

        const notice = document.createElement('div');
        notice.className = 'notice-shell';
        notice.style.setProperty('--notice-accent', toneMap[type] || toneMap.info);
        notice.innerHTML = `
            <div class="notice-copy">${this.escapeHtml(message)}</div>
            <button type="button" class="notice-close" aria-label="Tutup notifikasi">Tutup</button>
        `;

        host.appendChild(notice);
        notice.querySelector('.notice-close')?.addEventListener('click', () => {
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
                <div class="data-row">
                    <dt>${this.escapeHtml(entry.label)}</dt>
                    <dd>${this.escapeHtml(entry.value)}</dd>
                </div>
            `)
            .join('');

        return rows ? `<dl class="data-grid">${rows}</dl>` : '<div class="empty-shell">Tidak ada data.</div>';
    }
}

window.portalApp = new PortalApp();

document.addEventListener('DOMContentLoaded', () => {
    window.portalApp.init();
});
