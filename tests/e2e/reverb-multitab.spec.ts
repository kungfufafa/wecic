import { test, expect } from '@playwright/test';

test('session logout synchronizes across three tabs', async ({ browser }) => {
    const context = await browser.newContext();
    const pageA = await context.newPage();
    const pageB = await context.newPage();
    const pageC = await context.newPage();

    await pageA.goto('/dashboard');
    await pageB.goto('/class');
    await pageC.goto('/exam');

    await pageA.evaluate(() => window.dispatchEvent(new CustomEvent('portal:test-session-notice', {
        detail: { status: 'logged_out', message: 'Akun keluar dari perangkat ini.', urgency: 'warning' },
    })));

    await expect(pageA).toHaveURL(/\/login/);
    await expect(pageB).toHaveURL(/\/login/);
    await expect(pageC).toHaveURL(/\/login/);
});
