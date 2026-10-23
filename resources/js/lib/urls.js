/**
 * Links between the app's own pages and to the campus portal's files.
 * Pure: the page origin or campus base is passed in, so tests/js runs
 * them under Node.
 */

/**
 * The app's meeting page for a portal link such as
 * `...?id=12&courses_id=34`; null without both ids.
 *
 * @param {string|null|undefined} href
 * @returns {string|null}
 */
export function buildMeetingUrlFromHref(href) {
    if (!href) {
        return null;
    }

    const query = href.includes('?') ? href.split('?')[1] : href;
    const parameters = new URLSearchParams(query);
    const meetingId = parameters.get('id');
    const coursesId = parameters.get('courses_id') || parameters.get('course_id');

    if (!meetingId || !coursesId) {
        return null;
    }

    return `/meeting/${meetingId}?courses_id=${coursesId}`;
}

/**
 * The app's class page; null without a class id.
 *
 * @param {string|number|null|undefined} id
 * @param {string|number|null|undefined} dosenId
 * @param {string|number|null|undefined} courseId
 * @returns {string|null}
 */
export function buildClassUrl(id, dosenId, courseId) {
    if (!id) {
        return null;
    }

    const query = new URLSearchParams();

    if (dosenId) {
        query.set('dosen_id', dosenId);
    }

    if (courseId) {
        query.set('course_id', courseId);
    }

    const suffix = query.toString();

    return `/class/${id}${suffix ? `?${suffix}` : ''}`;
}

/**
 * The query of a full URL, a relative link or a bare `a=1&b=2` string.
 *
 * @param {unknown} value
 * @param {string} base The campus base relative links resolve against.
 * @returns {URLSearchParams}
 */
export function parseQueryString(value, base) {
    if (!value) {
        return new URLSearchParams();
    }

    const trimmed = String(value).trim();

    if (trimmed === '') {
        return new URLSearchParams();
    }

    try {
        const url = new URL(trimmed, base);

        return url.searchParams;
    } catch (error) {
        const query = trimmed.includes('?')
            ? trimmed.split('?').slice(1).join('?')
            : trimmed.replace(/^[?#&]/, '');

        return new URLSearchParams(query);
    }
}

/**
 * @param {unknown} value
 * @param {string} base
 * @returns {{ courses_id: string, id: string, pertemuan: string }}
 */
export function extractAssignmentShortcut(value, base) {
    const params = parseQueryString(value, base);

    return {
        courses_id: params.get('courses_id') || '',
        id: params.get('id') || '',
        pertemuan: params.get('pertemuan') || '',
    };
}

/**
 * @param {unknown} value
 * @param {string} base
 * @returns {{ courseid: string, modulesid: string, pertemuan: string }}
 */
export function extractAttendanceShortcut(value, base) {
    const params = parseQueryString(value, base);

    return {
        courseid: params.get('courseid') || params.get('course_id') || params.get('courses_id') || '',
        modulesid: params.get('modulesid') || params.get('id') || '',
        pertemuan: params.get('pertemuan') || '',
    };
}

/**
 * An absolute URL on the campus portal. Its `files/` links (relative, or
 * `/files/` on the portal itself) live under `/portal/files/`.
 *
 * @param {unknown} path
 * @param {string} campusBase The portal origin.
 * @returns {string|null}
 */
export function absoluteCampusUrl(path, campusBase) {
    if (!path) {
        return null;
    }

    const rawPath = String(path).trim();

    if (rawPath === '') {
        return null;
    }

    let normalizedPath = rawPath;

    try {
        const parsed = new URL(rawPath);

        if (parsed.origin === campusBase && /^\/files\//i.test(parsed.pathname)) {
            parsed.pathname = `/portal${parsed.pathname}`;

            return parsed.toString();
        }

        return parsed.toString();
    } catch (error) {
        // Continue normalizing relative paths below.
    }

    if (/^(?:(?:\.\.\/)+|\.\/)?files\//i.test(normalizedPath)) {
        normalizedPath = `/portal/${normalizedPath.replace(/^(?:(?:\.\.\/)+|\.\/)?/i, '')}`;
    } else if (/^\/files\//i.test(normalizedPath)) {
        normalizedPath = `/portal${normalizedPath}`;
    }

    try {
        return new URL(normalizedPath, campusBase).toString();
    } catch (error) {
        return normalizedPath;
    }
}

/**
 * The app's download proxy for a campus file.
 *
 * @param {unknown} path
 * @param {unknown} filename
 * @param {{ disposition?: 'inline'|'attachment'|null }} options
 * @returns {string|null}
 */
export function buildCampusDownloadUrl(path, filename = null, { disposition = null } = {}) {
    if (!path) {
        return null;
    }

    const params = new URLSearchParams({
        path: String(path),
    });

    if (filename) {
        params.set('name', String(filename));
    }

    if (disposition === 'inline' || disposition === 'attachment') {
        params.set('disposition', disposition);
    }

    return `/api/support/download?${params.toString()}`;
}

/**
 * Only links a student can safely follow: same-site paths, http(s),
 * mailto and tel. Anything else (javascript:, data:) gives null.
 *
 * @param {unknown} url
 * @param {string} origin The page origin relative links resolve against.
 * @returns {string|null}
 */
export function safeHref(url, origin) {
    if (!url) {
        return null;
    }

    try {
        const parsed = new URL(String(url), origin);

        return ['http:', 'https:', 'mailto:', 'tel:'].includes(parsed.protocol) ? String(url) : null;
    } catch (error) {
        return null;
    }
}

/**
 * Whether a link leaves the app for another http(s) site.
 *
 * @param {unknown} url
 * @param {string} origin
 * @returns {boolean}
 */
export function isExternalHref(url, origin) {
    try {
        const parsed = new URL(String(url), origin);

        return ['http:', 'https:'].includes(parsed.protocol) && parsed.origin !== origin;
    } catch (error) {
        return false;
    }
}
