import type { Page } from '@playwright/test';
import { expect, test, trackNavigations } from './support/fixtures';

/**
 * public/offline.html is what the service worker shows when a page could not
 * load. With the device online but the server not answering, it must wait
 * for the server (a probe of /up, longer after each miss) instead of
 * reloading itself in a loop, and reload once the server answers. The page
 * runs on Playwright's fake clock, so ten minutes pass in seconds.
 */
test.describe('offline page while the server is unreachable', () => {
    type ProbeLog = { startedAt: () => Promise<number>; probes: () => Promise<number[]>; settle: () => Promise<void> };
    type ProbeWindow = Window & { e2eProbes: number[]; e2eStartedAt: number };

    /**
     * Answers the page's /up probes (unreachable until `serverUp()` says
     * otherwise) and notes, on the fake clock, when the page started and
     * when each probe was sent.
     */
    async function probeServer(page: Page, serverUp: () => boolean): Promise<ProbeLog> {
        let settled = 0;
        const isProbe = (url: string): boolean => new URL(url).pathname === '/up';

        await page.addInitScript(() => {
            const sentAt: number[] = [];
            const originalFetch = window.fetch.bind(window);

            (window as unknown as ProbeWindow).e2eProbes = sentAt;
            (window as unknown as ProbeWindow).e2eStartedAt = Date.now();
            window.fetch = (input, init) => {
                if (new URL(String(input instanceof Request ? input.url : input), window.location.href).pathname === '/up') {
                    sentAt.push(Date.now());
                }

                return originalFetch(input, init);
            };
        });
        await page.route((url) => url.pathname === '/up', async (route) => {
            if (serverUp()) {
                await route.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><title>up</title>' });
            } else {
                await route.abort('connectionrefused');
            }
        });
        page.on('requestfailed', (request) => {
            settled += isProbe(request.url()) ? 1 : 0;
        });
        page.on('requestfinished', (request) => {
            settled += isProbe(request.url()) ? 1 : 0;
        });

        const probes = (): Promise<number[]> => page.evaluate(() => [...(window as unknown as ProbeWindow).e2eProbes]);

        return {
            startedAt: () => page.evaluate(() => (window as unknown as ProbeWindow).e2eStartedAt),
            probes,
            settle: async () => {
                const sent = (await probes()).length;

                await expect.poll(() => settled, { message: 'every probe sent so far has its answer' }).toBeGreaterThanOrEqual(sent);
            },
        };
    }

    test('probes with a growing wait and never reloads until the server answers', async ({ page }) => {
        let serverUp = false;

        await page.clock.install();

        const log = await probeServer(page, () => serverUp);

        await page.goto('/offline.html');

        const navigations = trackNavigations(page);
        const loadedAt = await log.startedAt();

        await expect(page.locator('#offline-status-label')).toHaveText('Mencoba menyambung lagi…');
        await expect(page.locator('#offline-title')).toHaveText('WeCIC belum bisa dihubungi');
        await expect(page.locator('#retry-button')).toBeEnabled();

        // Ten minutes, a second at a time; each probe gets its answer before the clock moves on.
        for (let second = 0; second < 600; second += 1) {
            await page.clock.runFor(1000);
            await log.settle();
        }

        expect(navigations.count(), 'no reload while the server does not answer').toBe(0);
        await expect(page.locator('#offline-status-label')).toHaveText('Mencoba menyambung lagi…');

        const probes = await log.probes();
        const gaps = probes.map((at, index) => at - (index === 0 ? loadedAt : probes[index - 1]));

        expect(gaps.length, 'the server is probed again and again').toBeGreaterThanOrEqual(8);
        expect(gaps.length, 'but never in a tight loop').toBeLessThanOrEqual(16);
        expect(gaps[0], 'the first probe waits about 2 s').toBeGreaterThanOrEqual(2000);
        expect(gaps[0]).toBeLessThanOrEqual(3000);
        gaps.slice(1).forEach((gap, index) => {
            expect(gap, `the wait before probe ${index + 2} does not shrink`).toBeGreaterThanOrEqual(gaps[index] - 2000);
            expect(gap, `the wait before probe ${index + 2} stays under the cap`).toBeLessThanOrEqual(63_000);
        });
        expect(gaps[1], 'the second wait doubles').toBeGreaterThanOrEqual(4000);
        expect(gaps.at(-1), 'the wait grows to the one-minute cap').toBeGreaterThanOrEqual(59_000);

        // The server answers: the next probe reloads the page, once.
        serverUp = true;

        const reloaded = page.waitForEvent('load');

        await page.clock.runFor(63_000);
        await reloaded;

        expect(navigations.count()).toBe(1);
    });

    test('waits while the device is offline and reloads once it is back online', async ({ page, context }) => {
        await page.clock.install();

        const log = await probeServer(page, () => false);

        await page.goto('/offline.html');
        await context.setOffline(true);

        await expect(page.locator('#offline-status-label')).toHaveText('Offline');

        const navigations = trackNavigations(page);
        const probesWhenOffline = (await log.probes()).length;

        await page.clock.runFor(5 * 60 * 1000);

        expect(navigations.count(), 'nothing reloads while the device is offline').toBe(0);
        expect((await log.probes()).length, 'and nothing is probed').toBe(probesWhenOffline);

        await context.setOffline(false);
        await expect(page.locator('#offline-status-label')).toHaveText('Online lagi');

        const reloaded = page.waitForEvent('load');

        await page.clock.runFor(1000);
        await reloaded;

        expect(navigations.count()).toBe(1);
    });
});
