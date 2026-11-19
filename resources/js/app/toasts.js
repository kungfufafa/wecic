import { ui } from './ui.js';

/**
 * Toasts (`app.showNotice()`), the notice kept across a navigation, and
 * the live regions that read them out.
 *
 * Methods of PortalApp (resources/js/app.js mixes them in): `this` is the
 * app, and every field they use is set in its constructor.
 */
export class ToastFeature {
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
            success: '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" /><path d="M9 12l2 2l4 -4" /></svg>',
            danger: '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 9v4" /><path d="M10.363 3.591l-8.106 13.534a1.914 1.914 0 0 0 1.636 2.871h16.214a1.914 1.914 0 0 0 1.636 -2.87l-8.106 -13.536a1.914 1.914 0 0 0 -3.274 0z" /><path d="M12 16h.01" /></svg>',
            warning: '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 9v4" /><path d="M10.363 3.591l-8.106 13.534a1.914 1.914 0 0 0 1.636 2.871h16.214a1.914 1.914 0 0 0 1.636 -2.87l-8.106 -13.536a1.914 1.914 0 0 0 -3.274 0z" /><path d="M12 16h.01" /></svg>',
            info: '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" /><path d="M12 9h.01" /><path d="M11 12h1v4h1" /></svg>',
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

        notice.className = ui.toast;
        notice.dataset.message = text;
        notice.dataset.tone = tone;
        notice.innerHTML = `
            <span class="${ui.toastIcon[tone] || ui.toastIcon.info}">${this.toastIcon(tone)}</span>
            <span class="${ui.toastMessage}">${this.escapeHtml(text)}</span>
            <button type="button" class="${ui.toastClose}" aria-label="Tutup notifikasi">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
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
}
