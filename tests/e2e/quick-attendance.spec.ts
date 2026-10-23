import type { Page } from '@playwright/test';
import { expect, test, trackNavigations } from './support/fixtures';
import { answerSignedInPages } from './support/portal-data';

/**
 * "Absen sekarang" (the PWA shortcut, or the quick QR button on a page
 * without today's schedule) opens /dashboard?absen=1: once today's schedule
 * names a class whose attendance is open, the app goes to that meeting with
 * the scanner (`&qr=1`).
 */
test.describe('quick attendance from ?absen=1', () => {
    /**
     * The meeting page itself is not under test: its document is answered
     * here, so it reads nothing.
     */
    async function stubMeetingPages(page: Page): Promise<string[]> {
        const opened: string[] = [];

        await page.route((url) => url.pathname.startsWith('/meeting/'), async (route) => {
            opened.push(new URL(route.request().url()).pathname + new URL(route.request().url()).search);
            await route.fulfill({ contentType: 'text/html', body: '<!doctype html><html lang="id"><title>Pertemuan</title><h1>Pertemuan</h1></html>' });
        });

        return opened;
    }

    test('goes to the meeting of the class whose attendance is open, with the scanner', async ({ page, api, campusSession }) => {
        await campusSession.start();
        answerSignedInPages(api, {
            todayClasses: [
                {
                    course_name: 'Basis Data',
                    course_code: 'IF205',
                    attendance: { actionable: false, state: 'done', meeting_url: '/meeting/9000?courses_id=4100' },
                },
                {
                    course_name: 'Pemrograman Web',
                    course_code: 'IF301',
                    attendance: { actionable: true, state: 'open', meeting_url: '/meeting/9001?courses_id=4242' },
                },
            ],
        });

        const opened = await stubMeetingPages(page);

        await page.goto('/dashboard?absen=1');
        await page.waitForURL((url) => url.pathname === '/meeting/9001');

        expect(new URL(page.url()).search).toBe('?courses_id=4242&qr=1');
        expect(opened).toEqual(['/meeting/9001?courses_id=4242&qr=1']);
        expect(api.callsTo('GET', '/api/dashboard').length).toBeGreaterThan(0);
    });

    test('stays on the dashboard and says so when no attendance is open today', async ({ page, api, campusSession }) => {
        await campusSession.start();
        answerSignedInPages(api, {
            todayClasses: [
                {
                    course_name: 'Pemrograman Web',
                    course_code: 'IF301',
                    attendance: { actionable: false, state: 'not_open', meeting_url: '/meeting/9001?courses_id=4242' },
                },
            ],
        });

        const opened = await stubMeetingPages(page);

        // The request gives up after 30 s (pendingQuickAttendanceTimer): the
        // fake clock runs past that before the page is checked again.
        await page.clock.install();
        await page.goto('/dashboard?absen=1');

        const navigations = trackNavigations(page);

        await expect(page.getByText('Absensi untuk kelas hari ini belum dibuka dosen. Coba lagi saat kelas berlangsung.').first()).toBeVisible();
        await page.clock.runFor(31_000);

        await expect(page).toHaveURL(/\/dashboard$/);
        expect(opened).toEqual([]);
        expect(navigations.count(), 'the request only drops ?absen=1 from the address, then and 30 s later').toBe(0);
    });
});
