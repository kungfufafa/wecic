import * as actionItemRules from '../lib/action-items.js';

/**
 * The action centre (`GET /api/action-items`): reading the items, the row
 * each becomes, and the badges they light on the navigation (rules in
 * lib/action-items.js).
 *
 * Methods of PortalApp (resources/js/app.js mixes them in): `this` is the
 * app, and every field they use is set in its constructor.
 */
export class ActionItemFeature {
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
                    ${dueText ? `<span class="text-caption font-medium ${dueTone}">${this.escapeHtml(dueText)}</span>` : ''}
                </span>
                <span class="text-sm font-medium leading-snug text-ink">${this.escapeHtml(item.title || 'Perlu dicek')}</span>
                ${item.description ? `<span class="line-clamp-2 text-caption text-ink-subtle">${this.escapeHtml(item.description)}</span>` : ''}
            </span>
            ${href ? `
                <span class="inline-flex shrink-0 items-center gap-1 self-center text-xs font-medium text-brand-text">
                    ${this.escapeHtml(item.cta_label || 'Buka')}
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6l-6 6" /></svg>
                </span>
            ` : ''}
        `;

        return href
            ? `<a href="${this.escapeHtml(href)}" class="mycic-list-row mycic-list-row-interactive min-h-11"${external}>${content}</a>`
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
}
