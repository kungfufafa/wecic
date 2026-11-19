import { afterEach, beforeEach, describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { createPortalApp } from './support/browser.mjs';

/**
 * Charts drawn without a library (resources/js/app/charts.js): a value that
 * is not known never draws as zero, widths never pass 100 %, and every chart
 * carries a hidden table with its values.
 */
let browser;
let app;

beforeEach(async () => {
    ({ app, browser } = await createPortalApp());
});

afterEach(() => browser?.dispose());

const widths = (html) => [...html.matchAll(/style="width: ([\d.]+)%"/g)].map((match) => Number(match[1]));

describe('renderProgressBar', () => {
    it('draws an unknown value as an empty track with a dash, never 0 %', () => {
        const html = app.renderProgressBar({ value: null, max: 144, label: 'SKS lulus' });

        assert.deepEqual(widths(html), []);
        assert.match(html, /aria-valuetext="–"/);
        assert.doesNotMatch(html, /aria-valuenow/);
    });

    it('fills the share of the maximum and reports it', () => {
        const html = app.renderProgressBar({ value: 62, max: 144, label: 'SKS lulus', valueLabel: '62 dari 144 SKS' });

        assert.deepEqual(widths(html), [43.06]);
        assert.match(html, /aria-valuenow="62"/);
        assert.match(html, /aria-valuetext="62 dari 144 SKS"/);
    });

    it('stops segments at the end of the track and names the remainder', () => {
        const html = app.renderProgressBar({
            max: 144,
            segments: [{ value: 100, label: 'Lulus' }, { value: 100, striped: true, label: 'Sedang ditempuh' }],
            remainderLabel: 'Sisa',
        });

        assert.deepEqual(widths(html), [69.44, 30.56]);
        assert.match(html, /mycic-progress-striped/);
        assert.match(html, /Sisa<\/span>\s*<span class="font-medium tabular-nums text-ink">0<\/span>/);
        assert.doesNotMatch(html, /font-semibold/);
    });

    it('falls back to a 100 maximum when the maximum is missing', () => {
        assert.deepEqual(widths(app.renderProgressBar({ value: 25, max: 0 })), [25]);
    });
});

describe('renderBarChart', () => {
    it('shows a missing value as a dash without a bar and stresses the last known value', () => {
        const html = app.renderBarChart([{ label: 'Smt 1', value: 3.2 }, { label: 'Smt 2', value: null }, { label: 'Smt 3', value: 3.6 }], { max: 4, valueFormat: (value) => value.toFixed(2) });
        const bars = [...html.matchAll(/rounded-t (bg-\S+)" style="height: ([\d.]+)%"/g)];

        assert.equal(bars.length, 2);
        assert.deepEqual(bars.map((bar) => [Number(bar[2]), bar[1]]), [[80, 'bg-border'], [90, 'bg-brand']]);
        assert.match(html, /title="Smt 2: –"/);
        assert.match(html, /<th scope="row">Smt 3<\/th><td>3\.60<\/td>/);
    });

    it('says there is nothing to draw without items', () => {
        assert.match(app.renderBarChart([]), /Belum ada data untuk grafik\./);
    });
});

describe('renderStackedBar', () => {
    it('splits the bar by share and keeps an empty part in the legend only', () => {
        const html = app.renderStackedBar([{ label: 'A', value: 3, tone: 'success' }, { label: 'B', value: 1 }, { label: 'E', value: 0, tone: 'danger' }]);

        assert.deepEqual(widths(html), [75, 25]);
        assert.match(html, /<span>E<\/span>/);
        assert.match(html, /<span class="font-medium tabular-nums text-ink">3<\/span>/);
        assert.doesNotMatch(html, /font-semibold/);
    });
});

describe('renderSparkline', () => {
    it('leaves out points without a value and draws nothing without any', () => {
        const html = app.renderSparkline([{ label: 'Smt 1', value: 3.1 }, { label: 'Smt 2', value: '' }, { label: 'Smt 3', value: 3.5 }], { label: 'IPS' });

        assert.equal((html.match(/<circle /g) || []).length, 4);
        assert.match(html, /aria-label="IPS"/);
        assert.doesNotMatch(html, /Smt 2/);
        assert.match(app.renderSparkline([{ label: 'x', value: null }]), /Belum ada data untuk grafik\./);
    });
});
