/**
 * Dates as the pages show them, in Indonesian and in the device's own
 * time zone. Pure, so tests/js runs them under Node.
 */

/**
 * Turn any date-like value into a Date. A plain `YYYY-MM-DD` is read as a
 * local calendar day (not UTC midnight), so it never shifts by a day.
 *
 * @param {unknown} value
 * @returns {Date|null}
 */
export function toDate(value) {
    if (value instanceof Date) {
        return Number.isNaN(value.getTime()) ? null : value;
    }

    if (value === null || value === undefined || value === '') {
        return null;
    }

    if (typeof value === 'number') {
        const fromNumber = new Date(value);

        return Number.isNaN(fromNumber.getTime()) ? null : fromNumber;
    }

    const text = String(value).trim();
    const dateOnly = text.match(/^(\d{4})-(\d{2})-(\d{2})$/);
    const date = dateOnly
        ? new Date(Number(dateOnly[1]), Number(dateOnly[2]) - 1, Number(dateOnly[3]))
        : new Date(text.replace(/^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2})/, '$1T$2'));

    return Number.isNaN(date.getTime()) ? null : date;
}

/**
 * "20 Oktober 2026"; `{ weekday: true }` gives "Selasa, 20 Oktober 2026",
 * `{ month: 'short', year: false }` gives "20 Okt". Empty input gives `fallback`.
 *
 * @param {unknown} value
 * @param {{ weekday?: boolean, month?: string, year?: boolean, time?: boolean, fallback?: string }} options
 * @returns {string}
 */
export function formatIndonesianDate(value, { weekday = false, month = 'long', year = true, time = false, fallback = '–' } = {}) {
    const date = toDate(value);

    if (!date) {
        return fallback;
    }

    const options = { day: 'numeric', month };

    if (year) {
        options.year = 'numeric';
    }

    if (weekday) {
        options.weekday = 'long';
    }

    if (time) {
        options.hour = '2-digit';
        options.minute = '2-digit';
    }

    return new Intl.DateTimeFormat('id-ID', options).format(date);
}

/**
 * Whole calendar days from `now` to `value` (negative when it has passed),
 * or null when `value` is not a date.
 *
 * @param {unknown} value
 * @param {unknown} now
 * @returns {number|null}
 */
export function daysUntil(value, now = new Date()) {
    const date = toDate(value);
    const base = toDate(now);

    if (!date || !base) {
        return null;
    }

    const start = Date.UTC(base.getFullYear(), base.getMonth(), base.getDate());
    const end = Date.UTC(date.getFullYear(), date.getMonth(), date.getDate());

    return Math.round((end - start) / 86400000);
}

/**
 * "hari ini", "besok", "3 hari lagi", "kemarin", "lewat 2 hari".
 * `{ natural: false }` keeps "1 hari lagi" / "lewat 1 hari".
 *
 * @param {unknown} value
 * @param {{ now?: unknown, natural?: boolean, fallback?: string }} options
 * @returns {string}
 */
export function formatRelativeDays(value, { now = new Date(), natural = true, fallback = '' } = {}) {
    const days = daysUntil(value, now);

    if (days === null) {
        return fallback;
    }

    if (days === 0) {
        return 'hari ini';
    }

    if (natural && days === 1) {
        return 'besok';
    }

    if (natural && days === -1) {
        return 'kemarin';
    }

    return days > 0 ? `${days} hari lagi` : `lewat ${Math.abs(days)} hari`;
}

/**
 * "baru saja", "5 menit lalu", "2 jam lalu", "kemarin", "3 hari lalu":
 * for honest "Diperbarui …" labels.
 *
 * @param {unknown} value
 * @param {{ now?: unknown }} options
 * @returns {string}
 */
export function formatTimeAgo(value, { now = new Date() } = {}) {
    const date = toDate(value);
    const base = toDate(now);

    if (!date || !base) {
        return '';
    }

    const minutes = Math.floor((base.getTime() - date.getTime()) / 60000);

    if (minutes < 1) {
        return 'baru saja';
    }

    if (minutes < 60) {
        return `${minutes} menit lalu`;
    }

    if (minutes < 24 * 60) {
        return `${Math.floor(minutes / 60)} jam lalu`;
    }

    const days = Math.abs(daysUntil(date, base) ?? 0);

    return days <= 1 ? 'kemarin' : `${days} hari lalu`;
}

/**
 * "20 Okt 2026, 08.30"; '-' when empty, the value itself when unreadable.
 *
 * @param {unknown} value
 * @returns {unknown}
 */
export function formatDate(value) {
    if (!value) {
        return '-';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return new Intl.DateTimeFormat('id-ID', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(date);
}

/**
 * "08.30"; '-' when unreadable.
 *
 * @param {unknown} value
 * @returns {string}
 */
export function formatTimeOnly(value) {
    const date = value instanceof Date ? value : new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '-';
    }

    return new Intl.DateTimeFormat('id-ID', {
        hour: '2-digit',
        minute: '2-digit',
    }).format(date);
}

/**
 * When the portal data in a payload was read: `meta.synced_at`, else the
 * first section's `extracted_at`; null when neither is a date.
 *
 * @param {unknown} payload
 * @returns {Date|null}
 */
export function portalDataTime(payload) {
    if (payload?.meta?.synced_at) {
        const synced = new Date(payload.meta.synced_at);
        if (!Number.isNaN(synced.getTime())) {
            return synced;
        }
    }

    const entries = payload?.data;

    if (!entries || typeof entries !== 'object') {
        return null;
    }

    for (const value of Object.values(entries)) {
        if (value && typeof value === 'object' && value.extracted_at) {
            const extracted = new Date(value.extracted_at);

            return Number.isNaN(extracted.getTime()) ? null : extracted;
        }
    }

    return null;
}
