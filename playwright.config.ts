import { defineConfig } from '@playwright/test';

/**
 * Browser tests (`npm run test:e2e`, which runs `npm run build` first). They
 * run in the installed Google Chrome (nothing is downloaded) against
 * `php artisan serve` in the e2e environment (.env.e2e, bootstrap/e2e.php):
 * every /api/* request is mocked in the browser, the server never reaches
 * the campus portal, and routes/e2e.php signs a fake student in. The pages
 * run the built bundle (public/build); tests/e2e/support/global-setup.ts
 * stops the run when it is older than its sources.
 */
export default defineConfig({
    testDir: 'tests/e2e',
    outputDir: 'storage/framework/testing/e2e',
    globalSetup: './tests/e2e/support/global-setup.ts',
    fullyParallel: false,
    workers: 1,
    retries: 0,
    timeout: 30_000,
    expect: { timeout: 10_000 },
    reporter: 'list',
    use: {
        baseURL: 'http://127.0.0.1:8000',
        channel: 'chrome',
        headless: true,
        locale: 'id-ID',
        timezoneId: 'Asia/Jakarta',
        // page.route() never sees what a service worker fetches; the specs
        // mock every request, so the app's worker stays off.
        serviceWorkers: 'block',
        trace: 'retain-on-failure',
    },
    webServer: {
        command: 'php artisan serve --host=127.0.0.1 --port=8000',
        url: 'http://127.0.0.1:8000/up',
        env: { APP_ENV: 'e2e' },
        reuseExistingServer: true,
        timeout: 60_000,
    },
});
