import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { capitalize, escapeHtml, formatRupiah, parseAmount } from '../../resources/js/lib/format.js';

const nbsp = '\u00a0';

describe('escapeHtml', () => {
    it('escapes every character that can open markup or an attribute', () => {
        assert.equal(escapeHtml('<img src=x onerror="a(\'b\')">&'), '&lt;img src=x onerror=&quot;a(&#039;b&#039;)&quot;&gt;&amp;');
    });

    it('prints nothing for null and undefined but keeps 0', () => {
        assert.equal(escapeHtml(null), '');
        assert.equal(escapeHtml(undefined), '');
        assert.equal(escapeHtml(0), '0');
    });
});

describe('capitalize', () => {
    it('raises the first letter only', () => {
        assert.equal(capitalize('lulus'), 'Lulus');
        assert.equal(capitalize('sKS'), 'SKS');
        assert.equal(capitalize(''), '');
        assert.equal(capitalize(null), '');
    });
});

describe('parseAmount', () => {
    it('reads the portal\'s ways of writing rupiah', () => {
        assert.equal(parseAmount(1250000), 1250000);
        assert.equal(parseAmount('1.250.000'), 1250000);
        assert.equal(parseAmount('Rp 1.250.000,00'), 1250000);
        assert.equal(parseAmount('Rp. 750.000'), 750000);
        assert.equal(parseAmount('1250000.00'), 1250000);
        assert.equal(parseAmount('1250000.5'), 1250000.5);
        assert.equal(parseAmount('-Rp 500.000'), -500000);
    });

    it('gives null when there is no amount at all', () => {
        for (const value of [null, undefined, '', '-', '–', 'Rp', 'belum ada', Number.NaN, Number.POSITIVE_INFINITY]) {
            assert.equal(parseAmount(value), null, String(value));
        }
    });
});

describe('formatRupiah', () => {
    it('writes rupiah without decimals', () => {
        assert.equal(formatRupiah('1.250.000'), `Rp${nbsp}1.250.000`);
        assert.equal(formatRupiah(0), `Rp${nbsp}0`);
        assert.equal(formatRupiah(-500000), `-Rp${nbsp}500.000`);
    });

    it('signs a credit only when asked', () => {
        assert.equal(formatRupiah(25000, { signed: true }), `+Rp${nbsp}25.000`);
        assert.equal(formatRupiah(0, { signed: true }), `Rp${nbsp}0`);
    });

    it('never turns a missing amount into Rp 0', () => {
        assert.equal(formatRupiah(null), '–');
        assert.equal(formatRupiah('', { fallback: 'Belum ada tagihan' }), 'Belum ada tagihan');
    });
});
