import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { request, type FullConfig } from '@playwright/test';
import { assertFreshBuild, assertPageLoadsTheBuild } from './build-guard.mjs';

/**
 * Before any spec runs:
 *
 * - the pages' bundle (public/build) must be newer than its sources, or the
 *   specs would test yesterday's JavaScript (`npm run test:e2e` builds first);
 * - the server on the port (reused by the webServer option) must be the e2e
 *   one, never a development server with real data: the specs sign in
 *   through routes/e2e.php, which only the e2e environment has;
 * - its pages must load that bundle from the server itself, the only origin
 *   the specs let through.
 */
export default async function globalSetup(config: FullConfig): Promise<void> {
    const rootDir = config.configFile ? dirname(config.configFile) : process.cwd();
    const baseURL = config.projects[0]?.use.baseURL ?? 'http://127.0.0.1:8000';

    assertFreshBuild(rootDir);

    const context = await request.newContext({ baseURL });

    try {
        const response = await context.delete('/__e2e/session');

        if (response.status() !== 204) {
            throw new Error(
                `[e2e] The server at ${baseURL} is not running in the e2e environment (DELETE /__e2e/session answered ${response.status()}). `
                + 'Stop whatever listens on that port and run `npm run test:e2e` again: Playwright starts `php artisan serve` with APP_ENV=e2e itself. '
                + 'A cached route list (`php artisan route:cache`) also hides the e2e routes: clear it with `php artisan route:clear`.',
            );
        }

        const loginPage = await context.get('/login');

        if (!loginPage.ok()) {
            throw new Error(`[e2e] GET ${baseURL}/login answered ${loginPage.status()}: the e2e server cannot render a page.`);
        }

        assertPageLoadsTheBuild(
            await loginPage.text(),
            new URL(baseURL).origin,
            JSON.parse(readFileSync(join(rootDir, 'public/build/manifest.json'), 'utf8')),
        );
    } finally {
        await context.dispose();
    }
}
