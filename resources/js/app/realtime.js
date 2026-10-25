import { createPortalEcho } from '../echo';

/**
 * Realtime updates over Laravel Echo (Reverb): the private session channel,
 * the connection chip, catching up after a dropped socket, and the sync and
 * invalidation events the server broadcasts.
 *
 * Methods of PortalApp (resources/js/app.js mixes them in): `this` is the
 * app, and every field they use is set in its constructor.
 */
export class RealtimeFeature {
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

    setRealtimeStatus(message, tone = 'info') {
        document.querySelectorAll('[data-realtime-label]').forEach((element) => {
            element.textContent = message;
        });

        document.querySelectorAll('[data-realtime-chip]').forEach((element) => {
            element.classList.remove('mycic-chip-online', 'mycic-chip-offline');
            element.classList.add(tone === 'success' ? 'mycic-chip-online' : 'mycic-chip-offline');
        });
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
}
