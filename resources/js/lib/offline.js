/**
 * How an offline session (portal unreachable at login) gets back online,
 * as the server reports it in `offline_reconnect`. Pure, so tests/js runs it
 * under Node; the texts match PortalAuthController::offlineReconnectMessage().
 */

/**
 * @type {Record<string, string>}
 */
const RECONNECT_MESSAGES = {
    notify: 'Sesi ini tidak menyimpan password, jadi tidak tersambung otomatis. WeCIC memberi tahu begitu portal kampus bisa diakses; setelah itu keluar lalu masuk lagi.',
    portal_back: 'Portal kampus sudah bisa diakses lagi. Keluar lalu masuk lagi untuk menyambungkan sesi.',
    manual: 'Keluar lalu masuk lagi saat portal kampus sudah bisa diakses.',
};

const AUTOMATIC_MESSAGE = 'WeCIC menyambung lagi otomatis begitu portal kampus bisa diakses.';

/**
 * The sentence telling the student how the offline session reconnects.
 * Unknown or missing modes read as the automatic reconnect.
 *
 * @param {string|null|undefined} mode `automatic`, `notify`, `portal_back` or `manual`.
 * @returns {string}
 */
export function offlineReconnectMessage(mode) {
    return RECONNECT_MESSAGES[String(mode || '')] ?? AUTOMATIC_MESSAGE;
}

/**
 * Whether the server reported a mode worth keeping on the page.
 *
 * @param {unknown} mode
 * @returns {boolean}
 */
export function isOfflineReconnectMode(mode) {
    return mode === 'automatic' || Object.hasOwn(RECONNECT_MESSAGES, String(mode || ''));
}
