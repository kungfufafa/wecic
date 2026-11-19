import { ui } from './ui.js';

/**
 * The MyCIC outbox: attendance and uploads queued in an offline session,
 * shown on every page with a [data-outbox-list] host, never read as sent.
 *
 * Methods of PortalApp (resources/js/app.js mixes them in): `this` is the
 * app, and every field they use is set in its constructor.
 */
export class OutboxFeature {
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

        this.showNotice(result?.message || 'Belum terkirim ke portal. Disimpan di antrean WeCIC dan dikirim begitu portal bisa diakses.', 'warning');
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

        const tones = { queued: 'warning', sending: 'info', sent: 'success', failed: 'danger', unverified: 'warning', expired: 'danger' };
        const title = heading || 'Antrean offline';

        return `
            <section class="${ui.card}" aria-label="${this.escapeHtml(title)}">
                <div class="${ui.cardHeader} flex-wrap">
                    <h3 class="${ui.cardTitle}">${this.escapeHtml(title)}</h3>
                    <span class="text-xs text-ink-subtle">Disimpan di WeCIC, dikirim saat portal bisa diakses</span>
                </div>
                <div class="${ui.cardBody}">
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
                            <div class="${ui.listRow}" data-outbox-item="${this.escapeHtml(item.id)}" data-outbox-status="${this.escapeHtml(item.status)}">
                                <div class="min-w-0 flex-1">
                                    <div class="mb-1 flex flex-wrap items-center gap-2">
                                        ${this.renderBadge(item.action_label || item.action, 'brand')}
                                        ${this.renderBadge(statusLabel, tone)}
                                    </div>
                                    <div class="text-sm font-medium leading-snug text-ink">${this.escapeHtml(item.subject || item.action_label || 'Aksi portal')}</div>
                                    ${meta ? `<div class="text-caption text-ink-subtle">${this.escapeHtml(meta)}</div>` : ''}
                                    ${item.status === 'failed' || item.status === 'expired' || item.status === 'unverified'
                                        ? `<div class="mt-1 text-caption ${this.textToneClass(tone)}">${this.escapeHtml(item.last_error || statusLabel)}</div>`
                                        : ''}
                                    ${item.status === 'unverified'
                                        ? '<div class="mt-1 text-caption text-ink-subtle">Cek halamannya di portal sebelum mengirim ulang, supaya file tidak terkirim dua kali.</div>'
                                        : ''}
                                    ${isQueued
                                        ? `<div class="mt-1 text-caption text-ink-subtle">Portal belum menerima ini. WeCIC mengirimnya otomatis begitu portal bisa diakses${item.deadline_at ? ', selama tenggatnya belum lewat' : ''}.</div>`
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
        if (!Number.isInteger(id) || !window.confirm('Batalkan item ini? Portal belum menerimanya, dan WeCIC tidak akan mengirimnya.')) {
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
}
