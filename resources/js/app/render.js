/**
 * Shared HTML the pages render with: tone → class maps, badges, page
 * headers, stat cards, empty and error states.
 *
 * Methods of PortalApp (resources/js/app.js mixes them in): `this` is the
 * app, and every field they use is set in its constructor.
 */
export class RenderFeature {
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
            destructive: small ? 'mycic-btn-destructive-sm min-h-11 sm:min-h-9' : 'mycic-btn-destructive',
            icon: small ? 'mycic-btn-icon-sm' : 'mycic-btn-icon',
        };

        return variants[variant] || variants.primary;
    }

    textToneClass(tone = 'muted') {
        const tones = {
            brand: 'text-brand-text',
            primary: 'text-brand-text',
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
                        ${pretitle ? `<div class="text-caption font-medium text-ink-subtle">${this.escapeHtml(pretitle)}</div>` : ''}
                        <h2 class="text-2xl font-semibold leading-[1.2] tracking-[-0.025em] text-ink sm:text-[1.75rem]">${this.escapeHtml(title)}</h2>
                        ${description ? `<div class="mt-1.5 text-sm leading-relaxed text-ink-muted">${this.escapeHtml(description)}</div>` : ''}
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
            <div class="mb-4 mycic-stat-strip grid-cols-2 xl:grid-cols-4">
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
                        <div class="mb-1 text-caption font-medium text-ink-subtle">${this.escapeHtml(item.label || '')}</div>
                        <div class="font-mono font-medium text-ink tabular-nums">${this.escapeHtml(item.value === null || item.value === '' ? '-' : item.value)}</div>
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
                        <div class="font-medium">${this.escapeHtml(message)}</div>
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

    renderKeyValueList(entries = []) {
        const rows = entries
            .filter((entry) => entry.value !== undefined && entry.value !== null && entry.value !== '')
            .map((entry) => `
                <div class="flex items-start justify-between gap-6 py-3 first:pt-0 last:pb-0">
                    <div class="shrink-0 text-sm text-ink-subtle">${this.escapeHtml(entry.label)}</div>
                    <div class="min-w-0 text-end text-sm font-medium text-ink tabular-nums">${this.escapeHtml(entry.value)}</div>
                </div>
            `)
            .join('');

        return rows
            ? `<div class="flex flex-col divide-y divide-border-light">${rows}</div>`
            : this.renderEmptyState('Tidak ada data.', 'secondary');
    }
}
