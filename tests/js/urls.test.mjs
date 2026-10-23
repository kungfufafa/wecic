import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import {
    absoluteCampusUrl,
    buildCampusDownloadUrl,
    buildClassUrl,
    buildMeetingUrlFromHref,
    extractAssignmentShortcut,
    extractAttendanceShortcut,
    isExternalHref,
    parseQueryString,
    safeHref,
} from '../../resources/js/lib/urls.js';

const campus = 'https://portal.invalid';
const app = 'https://app.invalid';

describe('buildMeetingUrlFromHref', () => {
    it('builds the meeting page from a portal link', () => {
        assert.equal(buildMeetingUrlFromHref('https://portal.invalid/sipandai/meeting?id=12&courses_id=34'), '/meeting/12?courses_id=34');
        assert.equal(buildMeetingUrlFromHref('detail?course_id=9&id=3'), '/meeting/3?courses_id=9');
        assert.equal(buildMeetingUrlFromHref('id=5&courses_id=6'), '/meeting/5?courses_id=6');
    });

    it('gives null without both ids', () => {
        assert.equal(buildMeetingUrlFromHref(''), null);
        assert.equal(buildMeetingUrlFromHref(null), null);
        assert.equal(buildMeetingUrlFromHref('?id=5'), null);
        assert.equal(buildMeetingUrlFromHref('?courses_id=6'), null);
    });
});

describe('buildClassUrl', () => {
    it('adds only the ids it has', () => {
        assert.equal(buildClassUrl(7, 'd1', 'c2'), '/class/7?dosen_id=d1&course_id=c2');
        assert.equal(buildClassUrl(7, null, 'c2'), '/class/7?course_id=c2');
        assert.equal(buildClassUrl(7), '/class/7');
    });

    it('encodes the query and needs a class id', () => {
        assert.equal(buildClassUrl('7', 'a b', 'x&y'), '/class/7?dosen_id=a+b&course_id=x%26y');
        assert.equal(buildClassUrl(null, 'd1', 'c2'), null);
        assert.equal(buildClassUrl('', 'd1'), null);
    });
});

describe('parseQueryString and shortcuts', () => {
    it('reads the query of a full URL, a relative link or a bare query', () => {
        assert.equal(parseQueryString('https://portal.invalid/a?x=1&y=2', campus).get('y'), '2');
        assert.equal(parseQueryString('/absen?x=3', campus).get('x'), '3');
        assert.equal(parseQueryString('?x=4', campus).get('x'), '4');
        assert.equal(parseQueryString('', campus).toString(), '');
        assert.equal(parseQueryString(null, campus).toString(), '');
    });

    it('falls back to the raw text when the base cannot resolve it', () => {
        assert.equal(parseQueryString('a?x=1?y=2', 'not a base').get('x'), '1?y=2');
        assert.equal(parseQueryString('&x=5', 'not a base').get('x'), '5');
    });

    it('reads an assignment shortcut', () => {
        assert.deepEqual(
            extractAssignmentShortcut('https://portal.invalid/tugas?courses_id=11&id=22&pertemuan=3', campus),
            { courses_id: '11', id: '22', pertemuan: '3' },
        );
        assert.deepEqual(extractAssignmentShortcut('', campus), { courses_id: '', id: '', pertemuan: '' });
    });

    it('reads an attendance shortcut under any of the portal\'s parameter names', () => {
        assert.deepEqual(
            extractAttendanceShortcut('/absen?courseid=1&modulesid=2&pertemuan=3', campus),
            { courseid: '1', modulesid: '2', pertemuan: '3' },
        );
        assert.deepEqual(
            extractAttendanceShortcut('/absen?courses_id=4&id=5', campus),
            { courseid: '4', modulesid: '5', pertemuan: '' },
        );
        assert.equal(extractAttendanceShortcut('?course_id=6', campus).courseid, '6');
    });
});

describe('absoluteCampusUrl', () => {
    it('moves the portal\'s files links under /portal/files', () => {
        assert.equal(absoluteCampusUrl('files/materi.pdf', campus), 'https://portal.invalid/portal/files/materi.pdf');
        assert.equal(absoluteCampusUrl('../../files/materi.pdf', campus), 'https://portal.invalid/portal/files/materi.pdf');
        assert.equal(absoluteCampusUrl('./files/materi.pdf', campus), 'https://portal.invalid/portal/files/materi.pdf');
        assert.equal(absoluteCampusUrl('/files/materi.pdf', campus), 'https://portal.invalid/portal/files/materi.pdf');
        assert.equal(absoluteCampusUrl('https://portal.invalid/files/materi.pdf', campus), 'https://portal.invalid/portal/files/materi.pdf');
    });

    it('keeps other links as they are', () => {
        assert.equal(absoluteCampusUrl('https://cdn.invalid/files/materi.pdf', campus), 'https://cdn.invalid/files/materi.pdf');
        assert.equal(absoluteCampusUrl('/portal/sipandai/x', campus), 'https://portal.invalid/portal/sipandai/x');
        assert.equal(absoluteCampusUrl('  ', campus), null);
        assert.equal(absoluteCampusUrl(null, campus), null);
    });
});

describe('buildCampusDownloadUrl', () => {
    it('goes through the download proxy with an encoded path and name', () => {
        assert.equal(
            buildCampusDownloadUrl('files/Materi 1&2.pdf', 'Materi 1.pdf'),
            '/api/support/download?path=files%2FMateri+1%262.pdf&name=Materi+1.pdf',
        );
        assert.equal(buildCampusDownloadUrl('files/a.pdf', null, { disposition: 'inline' }), '/api/support/download?path=files%2Fa.pdf&disposition=inline');
    });

    it('drops an unknown disposition and needs a path', () => {
        assert.equal(buildCampusDownloadUrl('files/a.pdf', null, { disposition: 'evil' }), '/api/support/download?path=files%2Fa.pdf');
        assert.equal(buildCampusDownloadUrl(''), null);
    });
});

describe('safeHref and isExternalHref', () => {
    it('allows same-site paths, http(s), mailto and tel only', () => {
        for (const href of ['/class/1', 'https://elsewhere.invalid/x', 'http://elsewhere.invalid', 'mailto:a@b.invalid', 'tel:+62812']) {
            assert.equal(safeHref(href, app), href);
        }

        for (const href of ['javascript:alert(1)', ' JavaScript:alert(1)', 'data:text/html,<b>x</b>', 'vbscript:x', '', null]) {
            assert.equal(safeHref(href, app), null, String(href));
        }
    });

    it('marks only links to another http(s) site as external', () => {
        assert.equal(isExternalHref('https://elsewhere.invalid/x', app), true);
        assert.equal(isExternalHref('//elsewhere.invalid/x', app), true);
        assert.equal(isExternalHref('/class/1', app), false);
        assert.equal(isExternalHref('https://app.invalid/class/1', app), false);
        assert.equal(isExternalHref('mailto:a@b.invalid', app), false);
        assert.equal(isExternalHref('http://[bad', app), false);
    });
});
