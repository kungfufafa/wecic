/**
 * Portal notifications: the bell's unread count and the toast a burst of
 * new notifications becomes.
 *
 * Methods of PortalApp (resources/js/app.js mixes them in): `this` is the
 * app, and every field they use is set in its constructor.
 */
export class NotificationFeature {
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
}
