/**
 * Charts without a library: progress bars, sparklines, column and stacked
 * bars, each with a hidden data table behind it.
 *
 * Methods of PortalApp (resources/js/app.js mixes them in): `this` is the
 * app, and every field they use is set in its constructor.
 */
export class ChartFeature {
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
}
