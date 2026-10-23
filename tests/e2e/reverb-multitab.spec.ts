import type { BrowserContext } from '@playwright/test';
import { expect, test, type ApiMock, type E2eSession, type FakeReverb } from './support/fixtures';
import { answerSignedInPages } from './support/portal-data';

/**
 * A logout broadcasts a `logged_out` session notice on the session's private
 * channel (PortalAuthController::logout → PortalSessionNoticeSent); every tab
 * of that session listens there and leaves for the login page.
 */
test.describe('session notices across tabs', () => {
    const signedInTabs = ['/dashboard', '/class', '/exam'];

    async function openSignedInTabs({ context, api, reverb, campusSession }: { context: BrowserContext; api: ApiMock; reverb: FakeReverb; campusSession: E2eSession }) {
        const sessionId = await campusSession.start();
        const channel = `private-portal.session.${sessionId}`;

        answerSignedInPages(api);

        const pages = [];

        for (const path of signedInTabs) {
            const page = await context.newPage();

            await page.goto(path);
            await expect(page, `${path} opens signed in`).toHaveURL(path);
            await expect(page.locator('body')).toHaveAttribute('data-auth', 'required');
            await expect(page.locator('html')).toHaveAttribute('data-session-id', sessionId);
            pages.push(page);
        }

        // Every tab joined the session's channel through the real Echo client.
        await expect.poll(() => reverb.subscribers(channel), { message: 'all three tabs subscribe to the session channel' }).toBe(signedInTabs.length);

        return { pages, channel };
    }

    test('a logged_out notice sends every signed-in tab to the login page', async ({ context, api, reverb, campusSession }) => {
        const { pages, channel } = await openSignedInTabs({ context, api, reverb, campusSession });

        // As in PortalAuthController::logout: the session is gone before the notice goes out.
        await campusSession.end();

        const delivered = reverb.broadcast(channel, 'portal.session.notice', {
            occurred_at: new Date().toISOString(),
            session_fingerprint: 'e2e',
            status: 'logged_out',
            message: 'Akun keluar dari perangkat ini.',
            urgency: 'warning',
        });

        expect(delivered).toBe(signedInTabs.length);

        for (const page of pages) {
            await expect(page).toHaveURL(/\/login$/);
            await expect(page.locator('#login-form')).toBeVisible();
        }
    });

    test('a notice that is not a logout leaves every tab where it is', async ({ context, api, reverb, campusSession }) => {
        const { pages, channel } = await openSignedInTabs({ context, api, reverb, campusSession });

        const delivered = reverb.broadcast(channel, 'portal.session.notice', {
            occurred_at: new Date().toISOString(),
            session_fingerprint: 'e2e',
            status: 'relogin_succeeded',
            message: 'Sesi portal diperbarui otomatis.',
            urgency: 'info',
        });

        expect(delivered).toBe(signedInTabs.length);

        for (const [index, page] of pages.entries()) {
            await expect(page.getByText('Sesi portal diperbarui otomatis.').first()).toBeVisible();
            await expect(page).toHaveURL(signedInTabs[index]);
        }
    });
});
