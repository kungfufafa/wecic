/**
 * Action items (things a student must do) and the nav badges they light.
 * Pure, so tests/js runs them under Node.
 */

/**
 * Map an action-item severity (critical/high/warning/info/…) to a tone:
 * 'danger' | 'warning' | 'info' | 'success' | 'neutral'.
 *
 * @param {unknown} severity
 * @returns {'danger'|'warning'|'info'|'success'|'neutral'}
 */
export function severityTone(severity) {
    const value = String(severity ?? '').toLowerCase();

    if (['critical', 'urgent', 'high', 'danger', 'error', 'overdue'].includes(value)) {
        return 'danger';
    }

    if (['warning', 'medium', 'soon', 'attention'].includes(value)) {
        return 'warning';
    }

    if (['success', 'done', 'ok', 'clear', 'resolved', 'paid'].includes(value)) {
        return 'success';
    }

    if (['info', 'low', 'normal', 'notice'].includes(value)) {
        return 'info';
    }

    return 'neutral';
}

/**
 * The more urgent of two tones; `current` may be undefined.
 *
 * @param {string|undefined} current
 * @param {string} next
 * @returns {string|undefined}
 */
export function strongerTone(current, next) {
    const rank = { danger: 3, warning: 2, info: 1, success: 0, neutral: 0 };

    return (rank[next] ?? 0) > (rank[current] ?? -1) ? next : current;
}

/**
 * The item list of an `/api/action-items` payload ([] when it has none).
 *
 * @param {unknown} payload
 * @returns {Array<object>}
 */
export function extractActionItems(payload) {
    const data = payload?.data;

    if (Array.isArray(data)) {
        return data;
    }

    if (Array.isArray(data?.items)) {
        return data.items;
    }

    return Array.isArray(data?.action_items) ? data.action_items : [];
}

/**
 * Badge key for one action item: krs, grades, transcript, finance, skkm,
 * announcement, profile, password, exam or class (from `cta_url`, else
 * from `source`/`type`); null when it belongs nowhere in the nav.
 *
 * @param {{ cta_url?: unknown, source?: unknown, type?: unknown }|null|undefined} item
 * @param {string} origin The app's origin: a `cta_url` elsewhere has no nav slot.
 * @returns {string|null}
 */
export function actionItemBadgeKey(item, origin) {
    let path = '';

    try {
        const url = new URL(String(item?.cta_url || ''), origin);

        path = url.origin === origin ? url.pathname : '';
    } catch (error) {
        path = '';
    }

    const byPath = [
        [/^\/krs(?:\/|$)/, 'krs'],
        [/^\/grades(?:\/|$)/, 'grades'],
        [/^\/transcript(?:\/|$)/, 'transcript'],
        [/^\/finance(?:\/|$)/, 'finance'],
        [/^\/skkm(?:\/|$)/, 'skkm'],
        [/^\/announcement(?:\/|$)/, 'announcement'],
        [/^\/profile(?:\/|$)/, 'profile'],
        [/^\/password(?:\/|$)/, 'password'],
        [/^\/exam(?:\/|$)/, 'exam'],
        [/^\/(?:class|meeting)(?:\/|$)/, 'class'],
    ];
    const bySource = [
        [/krs|advising|perwalian/, 'krs'],
        [/grade|khs|nilai/, 'grades'],
        [/transcript|transkrip/, 'transcript'],
        [/payment|finance|tagihan|ukt|bill/, 'finance'],
        [/skkm/, 'skkm'],
        [/announcement|pengumuman/, 'announcement'],
        [/password/, 'password'],
        [/exam|ujian/, 'exam'],
        [/class|meeting|attendance|assignment/, 'class'],
    ];
    const source = `${item?.source ?? ''} ${item?.type ?? ''}`.toLowerCase();

    return byPath.find(([pattern]) => pattern.test(path))?.[1]
        || bySource.find(([pattern]) => pattern.test(source))?.[1]
        || null;
}

/**
 * Count action items per badge: tiles (krs, grades, transcript, finance,
 * skkm, announcement, profile, password, exam) and nav slots (akademik =
 * KRS/nilai/transkrip, keuangan = tagihan, lainnya = the mobile /more
 * slot with tagihan, lainnya-desktop = the desktop dropdown without it,
 * kuliah = exams and critical class items). Only items that need action
 * (critical or warning) light a badge; informational ones stay in the
 * action center.
 *
 * @param {unknown} items
 * @param {string} origin
 * @returns {{ counts: Map<string, number>, tones: Map<string, string> }}
 */
export function countActionItemBadges(items, origin) {
    const counts = new Map();
    const tones = new Map();
    const bump = (key, tone) => {
        counts.set(key, (counts.get(key) || 0) + 1);
        tones.set(key, strongerTone(tones.get(key), tone));
    };

    (Array.isArray(items) ? items : []).forEach((item) => {
        const key = actionItemBadgeKey(item, origin);
        const tone = severityTone(item?.severity);

        if (!key || (tone !== 'danger' && tone !== 'warning')) {
            return;
        }

        if (key === 'class') {
            if (tone === 'danger') {
                bump('kuliah', tone);
                bump('class', tone);
            }

            return;
        }

        bump(key, tone);

        if (key === 'exam') {
            bump('kuliah', tone);
        }

        if (['krs', 'grades', 'transcript'].includes(key)) {
            bump('akademik', tone);
        }

        if (key === 'finance') {
            bump('keuangan', tone);
        }

        if (['finance', 'skkm', 'announcement', 'profile', 'password'].includes(key)) {
            bump('lainnya', tone);
        }

        // The desktop "Lainnya" dropdown has no Keuangan entry (it is a top-level link).
        if (['skkm', 'announcement', 'profile', 'password'].includes(key)) {
            bump('lainnya-desktop', tone);
        }
    });

    return { counts, tones };
}
