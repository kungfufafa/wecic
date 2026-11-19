import { ui } from './ui.js';

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
            secondary: ui.alert.neutral,
            neutral: ui.alert.neutral,
            info: ui.alert.info,
            success: ui.alert.success,
            warning: ui.alert.warning,
            danger: ui.alert.danger,
            error: ui.alert.danger,
            primary: ui.alert.brand,
            brand: ui.alert.brand,
        };

        return tones[type] || tones.secondary;
    }

    badgeTone(type = 'secondary') {
        const tones = {
            primary: ui.badge.brand,
            brand: ui.badge.brand,
            secondary: ui.badge.neutral,
            neutral: ui.badge.neutral,
            accent: ui.badge.accent,
            success: ui.badge.success,
            warning: ui.badge.warning,
            danger: ui.badge.danger,
            error: ui.badge.danger,
            info: ui.badge.info,
            dark: ui.badge.neutral,
        };

        return tones[type] || tones.secondary;
    }

    statusPanelClass(tone = 'neutral') {
        const tones = {
            primary: ui.panel.brand,
            brand: ui.panel.brand,
            success: ui.panel.success,
            warning: ui.panel.warning,
            danger: ui.panel.danger,
            error: ui.panel.danger,
            info: ui.panel.info,
            secondary: ui.panel.neutral,
            neutral: ui.panel.neutral,
        };

        return tones[tone] || tones.neutral;
    }

    /**
     * Button classes (ui.button). Small buttons are compact from `sm` up but
     * keep the 44 px touch target on phones (§5).
     */
    buttonClass(variant = 'primary', { small = false } = {}) {
        const variants = {
            primary: small ? ui.button.primarySm : ui.button.primary,
            secondary: small ? ui.button.secondarySm : ui.button.secondary,
            ghost: small ? ui.button.ghostSm : ui.button.ghost,
            destructive: small ? ui.button.destructiveSm : ui.button.destructive,
            icon: small ? ui.button.iconSm : ui.button.icon,
        };

        return variants[variant] || variants.primary;
    }

    textToneClass(tone = 'muted') {
        const tones = {
            brand: ui.text.brand,
            primary: ui.text.brand,
            success: ui.text.success,
            warning: ui.text.warning,
            danger: ui.text.danger,
            error: ui.text.danger,
            info: ui.text.info,
            muted: ui.text.muted,
            secondary: ui.text.subtle,
        };

        return tones[tone] || tones.muted;
    }

    fillToneClass(tone = 'brand') {
        const tones = {
            brand: ui.fill.brand,
            primary: ui.fill.brand,
            success: ui.fill.success,
            warning: ui.fill.warning,
            danger: ui.fill.danger,
            error: ui.fill.danger,
            info: ui.fill.info,
            accent: ui.fill.accent,
            neutral: ui.fill.neutral,
            secondary: ui.fill.neutral,
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
                        ${pretitle ? `<div class="${ui.pageEyebrow}">${this.escapeHtml(pretitle)}</div>` : ''}
                        <h2 class="${ui.pageTitle}">${this.escapeHtml(title)}</h2>
                        ${description ? `<div class="${ui.pageSubtitle}">${this.escapeHtml(description)}</div>` : ''}
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
            <div class="mb-4 ${ui.statGrid} grid-cols-2 xl:grid-cols-4">
                ${safeItems.map((item) => `
                    <div class="${ui.metric}">
                        <div class="${ui.metricLabel}">${this.escapeHtml(item.label || '')}</div>
                        <div class="${ui.metricValue}">${this.escapeHtml(item.value === null || item.value === '' ? '-' : item.value)}</div>
                        ${item.note ? `<div class="${ui.metricNote}">${this.escapeHtml(item.note)}</div>` : ''}
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
                        <div class="mb-1 text-xs font-medium text-ink-subtle">${this.escapeHtml(item.label || '')}</div>
                        <div class="font-medium text-ink tabular-nums">${this.escapeHtml(item.value === null || item.value === '' ? '-' : item.value)}</div>
                        ${item.note ? `<div class="mt-1 text-xs text-ink-subtle">${this.escapeHtml(item.note)}</div>` : ''}
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
            <div class="${ui.card}">
                <div class="${ui.cardBody}">
                    <div class="${this.alertTone('error')}" role="alert">
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

    /**
     * An accent's class string (app.ui.accents): `accent` is brand or neutral
     * (an old section name reads neutral), `key` is tile, soft, text, ring,
     * dot, glow, border, navIcon or tabMarker. An unknown accent reads neutral.
     */
    accentClass(accent, key = 'tile') {
        return (ui.accents[accent] || ui.accents.neutral)[key] || '';
    }

    /**
     * The <svg> <x-icon name size> draws (app.ui.icons, app.ui.iconSize);
     * `className` adds to its classes. An unknown name draws nothing.
     */
    renderIcon(name, size = 'md', className = '') {
        const paths = ui.icons[name];

        if (!paths) {
            return '';
        }

        const pixels = { sm: 16, md: 18, lg: 20, xl: 24 };
        const sizeKey = pixels[size] ? size : 'md';
        const classes = className ? `${ui.iconSize[sizeKey]} ${className}` : ui.iconSize[sizeKey];

        return `<svg xmlns="http://www.w3.org/2000/svg" class="${classes}" width="${pixels[sizeKey]}" height="${pixels[sizeKey]}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" data-icon="${this.escapeHtml(name)}">${paths}</svg>`;
    }

    /**
     * An empty state as <x-empty-state> draws it; `icon` (an icon name) puts a
     * small neutral icon above the title. An `accent` option is ignored.
     */
    renderEmptyState(message, type = 'secondary', subtitle = '', { icon = '' } = {}) {
        if (['danger', 'error', 'warning'].includes(type)) {
            return `<div class="${this.alertTone(type)}" role="alert">${this.escapeHtml(message)}</div>`;
        }

        const iconChip = icon && ui.icons[icon]
            ? `<div class="${ui.emptyIcon}" aria-hidden="true">${this.renderIcon(icon, 'lg')}</div>`
            : '';

        return `
            <div class="${ui.empty}">
                ${iconChip}<p class="${ui.emptyTitle}">${this.escapeHtml(message)}</p>
                ${subtitle ? `<p class="${ui.emptySubtitle}">${this.escapeHtml(subtitle)}</p>` : ''}
            </div>
        `;
    }

    renderDisabledButton(label) {
        return `<span class="${this.buttonClass('secondary')}" aria-disabled="true">${this.escapeHtml(label)}</span>`;
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
