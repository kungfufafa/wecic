import { expect, test, trackNavigations } from './support/fixtures';

/**
 * The login page (resources/views/auth/login.blade.php) with POST
 * /api/auth/login mocked: nothing is sent to the portal.
 */
test.describe('login page', () => {
    const nim = '2021010001';

    type MarkedWindow = Window & { e2eBeforeReload?: boolean };

    test('wrong credentials show the error on the page without leaving it', async ({ page, api }) => {
        api.answer('POST', '/api/auth/login', {
            status: 401,
            json: {
                status: 'error',
                message: 'Login gagal. Periksa kembali username dan password Anda.',
                meta: { timestamp: new Date().toISOString(), campus_session_valid: false },
            },
        });

        await page.goto('/login');
        await expect(page.locator('body')).toHaveAttribute('data-auth', 'guest');

        const navigations = trackNavigations(page);
        const username = page.getByLabel('NIM atau username');
        const password = page.locator('#password');

        await username.fill(nim);
        await password.fill('bukan-password');
        await page.getByRole('button', { name: 'Masuk' }).click();

        await expect(page.locator('#login-error')).toContainText('Login gagal. Periksa kembali username dan password Anda.');
        await expect(username).toHaveAttribute('aria-invalid', 'true');
        await expect(password).toHaveAttribute('aria-invalid', 'true');
        await expect(password).toBeFocused();
        await expect(username).toHaveValue(nim);
        await expect(page).toHaveURL(/\/login$/);
        expect(navigations.count(), 'the page neither reloads nor navigates').toBe(0);

        const logins = api.callsTo('POST', '/api/auth/login');

        expect(logins).toHaveLength(1);
        expect(JSON.parse(logins[0].body ?? '{}')).toEqual({ username: nim, password: 'bukan-password' });
    });

    test('an expired page (419) reloads once and keeps the typed NIM', async ({ page, api }) => {
        api.answer('POST', '/api/auth/login', {
            status: 419,
            json: { message: 'CSRF token mismatch.' },
        });

        await page.goto('/login');
        await page.evaluate(() => {
            (window as MarkedWindow).e2eBeforeReload = true;
        });

        const navigations = trackNavigations(page);

        await page.getByLabel('NIM atau username').fill(nim);
        await page.locator('#password').fill('password-lama');

        const reloaded = page.waitForEvent('load');

        await page.getByRole('button', { name: 'Masuk' }).click();
        await reloaded;

        await expect(page).toHaveURL(/\/login$/);
        expect(await page.evaluate(() => (window as MarkedWindow).e2eBeforeReload), 'the page was reloaded').toBeUndefined();
        await expect(page.getByLabel('NIM atau username')).toHaveValue(nim);
        await expect(page.locator('#password')).toHaveValue('');
        await expect(page.locator('#password')).toBeFocused();
        await expect(page.locator('#login-error')).toContainText('Halaman dimuat ulang karena sudah kedaluwarsa. Masukkan password lagi.');
        expect(navigations.count(), 'one reload, no loop').toBe(1);
        expect(api.callsTo('POST', '/api/auth/login'), 'the password is not sent again on its own').toHaveLength(1);
    });
});
