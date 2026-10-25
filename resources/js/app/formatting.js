import * as dateRules from '../lib/dates.js';
import * as formatRules from '../lib/format.js';

/**
 * Dates, amounts and text as the pages show them (rules in lib/dates.js
 * and lib/format.js).
 *
 * Methods of PortalApp (resources/js/app.js mixes them in): `this` is the
 * app, and every field they use is set in its constructor.
 */
export class FormattingFeature {
    capitalize(value) {
        return formatRules.capitalize(value);
    }

    formatDate(value) {
        return dateRules.formatDate(value);
    }

    formatTimeOnly(value) {
        return dateRules.formatTimeOnly(value);
    }

    escapeHtml(value) {
        return formatRules.escapeHtml(value);
    }

    /**
     * Turn any date-like value into a Date. A plain `YYYY-MM-DD` is read as a
     * local calendar day (not UTC midnight), so it never shifts by a day.
     */
    toDate(value) {
        return dateRules.toDate(value);
    }

    /**
     * "20 Oktober 2026"; `{ weekday: true }` gives "Selasa, 20 Oktober 2026",
     * `{ month: 'short', year: false }` gives "20 Okt". Empty input gives `fallback`.
     */
    formatIndonesianDate(value, options) {
        return dateRules.formatIndonesianDate(value, options);
    }

    /**
     * Whole calendar days from `now` to `value` (negative when it has passed),
     * or null when `value` is not a date.
     */
    daysUntil(value, now) {
        return dateRules.daysUntil(value, now);
    }

    /**
     * "hari ini", "besok", "3 hari lagi", "kemarin", "lewat 2 hari".
     * `{ natural: false }` keeps "1 hari lagi" / "lewat 1 hari".
     */
    formatRelativeDays(value, options) {
        return dateRules.formatRelativeDays(value, options);
    }

    /**
     * "baru saja", "5 menit lalu", "2 jam lalu", "kemarin", "3 hari lalu":
     * for honest "Diperbarui …" labels.
     */
    formatTimeAgo(value, options) {
        return dateRules.formatTimeAgo(value, options);
    }

    /**
     * A number from 1250000, "1.250.000", "Rp 1.250.000,00" or "1250000.00";
     * null when there is no amount at all.
     */
    parseAmount(value) {
        return formatRules.parseAmount(value);
    }

    /**
     * "Rp 1.250.000". A missing amount gives `fallback` ("–"), never "Rp 0".
     */
    formatRupiah(value, options) {
        return formatRules.formatRupiah(value, options);
    }
}
