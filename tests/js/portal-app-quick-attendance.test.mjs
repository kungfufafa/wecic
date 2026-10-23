import { afterEach, describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { createPortalApp, json } from './support/browser.mjs';

/**
 * The "Absen sekarang" request (`?absen=1`) on the dashboard: it opens the
 * scanner once today's schedule says which class is running, and it does
 * not give up while the attendance of today's classes is still being read.
 */
let browser;

afterEach(() => browser?.dispose());

const dashboard = (attendance) => ({ status: 'success', data: { classes_summary: { today_classes: [{ attendance }] } } });

async function waitingDashboard(firstAnswer) {
    const created = await createPortalApp({ url: '/dashboard' });
    const notices = [];

    browser = created.browser;
    browser.answer = firstAnswer;
    browser.server = (request) => (request.url.pathname === '/api/dashboard' ? json(200, browser.answer) : json(404, { message: 'Not Found' }));
    created.app.showNotice = (message, type) => notices.push([message, type]);
    created.app.pendingQuickAttendance = true;

    return { app: created.app, notices };
}

describe('quick attendance from ?absen=1', () => {
    it('keeps waiting while today\'s attendance is still being checked, then opens the class', async () => {
        const { app, notices } = await waitingDashboard(dashboard({ actionable: false, state: 'checking' }));

        await app.request('/api/dashboard');
        await browser.clock.advance(0);

        assert.equal(app.pendingQuickAttendance, true, 'a schedule still being checked does not end the request');
        assert.deepEqual(notices, []);

        browser.answer = dashboard({ actionable: true, meeting_url: '/meeting/7?courses_id=3' });
        await app.request('/api/dashboard');
        app.setQuickQrTarget('/meeting/7?courses_id=3&qr=1');
        await browser.clock.advance(0);

        assert.equal(app.pendingQuickAttendance, false);
        assert.deepEqual(browser.location.assignments, ['/meeting/7?courses_id=3&qr=1']);
        assert.deepEqual(notices, []);
    });

    it('says so when today has nothing to scan', async () => {
        const { app, notices } = await waitingDashboard(dashboard({ actionable: false, state: 'done' }));

        await app.request('/api/dashboard');
        await browser.clock.advance(0);

        assert.equal(app.pendingQuickAttendance, false);
        assert.deepEqual(browser.location.assignments, []);
        assert.equal(notices.length, 1);
        assert.equal(notices[0][1], 'warning');
    });
});
