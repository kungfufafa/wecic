/**
 * Text and amounts as the pages print them. Pure, so tests/js runs them
 * under Node.
 */

/**
 * @param {unknown} value
 * @returns {string}
 */
export function escapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

/**
 * @param {unknown} value
 * @returns {string}
 */
export function capitalize(value) {
    if (!value) {
        return '';
    }

    return String(value).charAt(0).toUpperCase() + String(value).slice(1);
}

/**
 * A number from 1250000, "1.250.000", "Rp 1.250.000,00" or "1250000.00";
 * null when there is no amount at all.
 *
 * @param {unknown} value
 * @returns {number|null}
 */
export function parseAmount(value) {
    if (typeof value === 'number') {
        return Number.isFinite(value) ? value : null;
    }

    if (value === null || value === undefined) {
        return null;
    }

    let text = String(value).trim();
    const negative = text.startsWith('-');

    text = text.replace(/^-/, '').replace(/rp\.?/i, '').replace(/\s/g, '');

    if (text === '' || text === '-' || text === '–') {
        return null;
    }

    if (/^\d+\.\d{1,2}$/.test(text)) {
        return (negative ? -1 : 1) * Number(text);
    }

    const digits = text.replace(/,\d{1,2}$/, '').replace(/\D/g, '');

    if (digits === '') {
        return null;
    }

    return (negative ? -1 : 1) * Number(digits);
}

/**
 * "Rp 1.250.000". A missing amount gives `fallback` ("–"), never "Rp 0".
 *
 * @param {unknown} value
 * @param {{ fallback?: string, signed?: boolean }} options
 * @returns {string}
 */
export function formatRupiah(value, { fallback = '–', signed = false } = {}) {
    const amount = parseAmount(value);

    if (amount === null) {
        return fallback;
    }

    const formatted = new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    }).format(Math.abs(amount));

    if (amount < 0) {
        return `-${formatted}`;
    }

    return signed && amount > 0 ? `+${formatted}` : formatted;
}
