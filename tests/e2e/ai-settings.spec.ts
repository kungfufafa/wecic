import type { Request } from '@playwright/test';
import { expect, test, type ApiMock } from './support/fixtures';
import { answerSignedInPages } from './support/portal-data';

/**
 * Pengaturan AI (resources/views/notebook/ai-settings.blade.php) with
 * /api/ai-settings mocked: one decision (who answers the Notebook), only
 * that option's credentials, and a Save button that says what it does.
 */

type Provider = 'gemini' | 'anthropic' | 'openai';

type ProviderState = { has_key: boolean; key_preview: string | null; server_configured: boolean; usable: boolean };

type Settings = {
    provider: Provider | null;
    server_provider: string;
    server_ready: boolean;
    effective: { provider: string; label: string; key_source: 'own' | 'server' | 'none'; endpoint: 'own' | 'server' | null; endpoint_host: string | null; ready: boolean };
    ingest: null | { provider: string; label: string; key_source: 'own' | 'server' | 'none' };
    providers: { gemini: ProviderState; anthropic: ProviderState; openai: ProviderState & { server_endpoint: boolean; base_url: string | null; model: string | null } };
};

const LABELS: Record<string, string> = { gemini: 'Google Gemini', anthropic: 'Anthropic Claude', openai: 'OpenAI-compatible' };

const provider = (overrides: Partial<ProviderState> = {}): ProviderState => ({ has_key: false, key_preview: null, server_configured: false, usable: false, ...overrides });

/** A student following a server that answers with Google Gemini and its own key. */
function serverGemini(overrides: Partial<Settings> = {}): Settings {
    return {
        provider: null,
        server_provider: 'gemini',
        server_ready: true,
        effective: { provider: 'gemini', label: 'Google Gemini', key_source: 'server', endpoint: null, endpoint_host: null, ready: true },
        ingest: null,
        providers: {
            gemini: provider({ server_configured: true, usable: true }),
            anthropic: provider(),
            openai: { ...provider(), server_endpoint: false, base_url: null, model: null },
        },
        ...overrides,
    };
}

function payload(settings: Settings, consentGranted = true): { status: string; message: string; data: unknown } {
    const label = settings.effective.label;

    return {
        status: 'success',
        message: consentGranted ? 'Pengaturan AI disimpan.' : `Pengaturan AI disimpan. Notebook kini memakai ${label}; setujui dulu pengirimannya sebelum memakai AI.`,
        data: {
            settings: {
                ...settings,
                active_provider: settings.effective.provider,
                server_provider_label: LABELS[settings.server_provider] ?? null,
            },
            consent: {
                granted: consentGranted,
                granted_at: consentGranted ? '2026-11-20T08:00:00.000000Z' : null,
                withdrawn_at: null,
                provider: label,
                free_tier: false,
                consent_key: settings.effective.provider,
                processors: [{ label, free_tier: false }],
                past_processors: [],
            },
        },
    };
}

function answerSettings(api: ApiMock, settings: Settings, consentGranted = true): void {
    answerSignedInPages(api).answer('GET', '/api/ai-settings', { json: payload(settings, consentGranted) });
}

const body = (request: Request): Record<string, unknown> => JSON.parse(request.postData() ?? '{}');

test.describe('Pengaturan AI', () => {
    test('says what is in effect and shows only the chosen option\'s credentials', async ({ page, api, campusSession }) => {
        await campusSession.start();
        answerSettings(api, serverGemini());

        await page.goto('/notebook/pengaturan-ai');

        await expect(page.locator('#ai-now-summary')).toHaveText('Notebook-mu mengikuti server WeCIC: Google Gemini dengan kunci server WeCIC.');
        await expect(page.getByRole('radio', { name: /Ikuti server WeCIC/ })).toBeChecked();
        await expect(page.locator('[data-provider-option=""]')).toContainText('Server sekarang memakai Google Gemini.');

        // Following the server shows the server provider's (optional) key, right under the chosen option.
        const gemini = page.locator('#ai-panel-gemini');
        await expect(gemini).toBeVisible();
        await expect(gemini.locator('[data-panel-heading]')).toHaveText('API key Google Gemini (opsional)');
        await expect(page.locator('#ai-panel-anthropic')).toBeHidden();
        await expect(page.locator('#ai-panel-openai')).toBeHidden();
        expect(await page.locator('[data-provider-option=""] + [data-provider-panel]').getAttribute('id')).toBe('ai-panel-gemini');

        await expect(page.locator('#btn-save-ai-settings')).toBeDisabled();
        await expect(page.locator('#ai-save-consequence')).toHaveText('Belum ada perubahan.');

        await page.getByRole('radio', { name: /Anthropic Claude/ }).check({ force: true });
        await expect(page.locator('#ai-panel-anthropic')).toBeVisible();
        await expect(gemini).toBeHidden();
        await expect(page.locator('#ai-save-consequence')).toHaveText('Tempel API key Anthropic Claude dulu untuk memakai pilihan ini.');
        await expect(page.locator('#btn-save-ai-settings')).toBeDisabled();

        // Nothing of the student's is saved: the reset card says so and offers nothing to delete.
        await expect(page.locator('#ai-reset-empty')).toBeVisible();
        await expect(page.locator('#btn-clear-ai-settings')).toBeHidden();
        await expect(page.locator('#ai-other')).toBeHidden();

        await page.locator('#ai-provider-title').scrollIntoViewIfNeeded();
        await page.screenshot({ path: 'storage/framework/testing/e2e/ai-settings.png' });
    });

    test('fits a phone screen without sideways scrolling', async ({ page, api, campusSession }) => {
        await campusSession.start();
        const settings = serverGemini({
            provider: 'openai',
            effective: { provider: 'openai', label: 'OpenAI-compatible', key_source: 'own', endpoint: 'own', endpoint_host: 'my-litellm-gateway.eastus2.inference.example.org', ready: true },
        });
        settings.providers.openai = { ...provider({ has_key: true, key_preview: '••••••••9012', usable: true }), server_endpoint: false, base_url: 'https://my-litellm-gateway.eastus2.inference.example.org/v1', model: 'a-very-long-model-name/with-a-path-2026-11-01' };
        settings.providers.anthropic = provider({ has_key: true, key_preview: '••••••••WXYZ', usable: true });
        answerSettings(api, settings);

        await page.setViewportSize({ width: 375, height: 812 });
        await page.goto('/notebook/pengaturan-ai');
        await expect(page.locator('#ai-now-summary')).toContainText('my-litellm-gateway.eastus2.inference.example.org');
        await expect(page.locator('#btn-clear-ai-settings')).toBeVisible();
        await expect(page.locator('#ai-reset-empty')).toBeHidden();

        const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
        expect(overflow).toBeLessThanOrEqual(0);

        await page.locator('#ai-provider-title').scrollIntoViewIfNeeded();
        await page.screenshot({ path: 'storage/framework/testing/e2e/ai-settings-phone.png' });
    });

    test('saving a new provider sends only its key and leaves the button reflecting the saved state', async ({ page, api, campusSession }) => {
        await campusSession.start();
        answerSettings(api, serverGemini());

        const saved = serverGemini({
            provider: 'anthropic',
            effective: { provider: 'anthropic', label: 'Anthropic Claude', key_source: 'own', endpoint: null, endpoint_host: null, ready: true },
        });
        saved.providers.anthropic = provider({ has_key: true, key_preview: '••••••••WXYZ', usable: true });

        let release: () => void = () => {};
        const held = new Promise<void>((resolve) => { release = resolve; });

        api.answer('PUT', '/api/ai-settings', async () => {
            await held;

            return { json: payload(saved, false) };
        });

        await page.goto('/notebook/pengaturan-ai');
        await page.getByRole('radio', { name: /Anthropic Claude/ }).check({ force: true });
        await page.locator('#anthropic_api_key').fill('sk-ant-student-WXYZ');

        const save = page.locator('#btn-save-ai-settings');
        await expect(save).toHaveText('Pakai Anthropic Claude');
        await expect(page.locator('#ai-save-consequence')).toContainText('Kamu perlu menyetujui lagi');
        await save.click();

        // Typing while the save is in flight never re-enables the button.
        await page.locator('#anthropic_api_key').press('A');
        await expect(save).toBeDisabled();
        release();

        await expect(page.locator('#ai-now-summary')).toHaveText('Notebook-mu memakai Anthropic Claude dengan kunci milikmu (••••••••WXYZ).');
        await expect(save).toBeDisabled();
        await expect(save).toHaveText('Simpan');
        await expect(page.locator('#ai-panel-anthropic [data-saved-key-row]')).toBeVisible();
        await expect(page.locator('#anthropic_api_key')).toBeHidden();
        await expect(page.locator('#anthropic_api_key')).toHaveValue('');
        await expect(page.locator('#ai-now-alert')).toContainText('Satu langkah lagi');

        const puts = api.callsTo('PUT', '/api/ai-settings');
        expect(puts).toHaveLength(1);
        expect(JSON.parse(puts[0].body ?? '{}')).toEqual({ provider: 'anthropic', anthropic_api_key: 'sk-ant-student-WXYZ' });
    });

    test('a key the chosen provider still needs cannot be removed, and the dialog says why', async ({ page, api, campusSession }) => {
        await campusSession.start();
        const settings = serverGemini({
            provider: 'anthropic',
            effective: { provider: 'anthropic', label: 'Anthropic Claude', key_source: 'own', endpoint: null, endpoint_host: null, ready: true },
        });
        settings.providers.anthropic = provider({ has_key: true, key_preview: '••••••••WXYZ', usable: true });
        answerSettings(api, settings);

        await page.goto('/notebook/pengaturan-ai');
        await page.getByRole('button', { name: 'Hapus kunci Anthropic Claude' }).click();

        const sheet = page.locator('[data-app-sheet-panel]');
        await expect(sheet).toContainText('Kunci Anthropic Claude masih dipakai');
        await expect(sheet).toContainText('Pilih penyedia lain dulu');
        await expect(sheet.getByRole('button', { name: 'Hapus kunci' })).toHaveCount(0);
        await sheet.getByRole('button', { name: 'Mengerti' }).click();
        expect(api.callsTo('PUT', '/api/ai-settings')).toHaveLength(0);
    });

    test('a key that is not in use is removed on its own after a confirm', async ({ page, api, campusSession }) => {
        await campusSession.start();
        const settings = serverGemini();
        settings.providers.anthropic = provider({ has_key: true, key_preview: '••••••••WXYZ', usable: true });
        answerSettings(api, settings);

        const after = serverGemini();
        api.answer('PUT', '/api/ai-settings', (request) => {
            expect(body(request)).toEqual({ clear_anthropic_api_key: true });

            return { json: payload(after) };
        });

        await page.goto('/notebook/pengaturan-ai');
        await expect(page.locator('#ai-other')).toContainText('Anthropic Claude');
        await page.locator('#ai-other').getByRole('button', { name: 'Hapus kunci Anthropic Claude' }).click();

        const sheet = page.locator('[data-app-sheet-panel]');
        await expect(sheet).toContainText('Kunci ini sedang tidak dipakai.');
        await sheet.getByRole('button', { name: 'Hapus kunci' }).click();

        await expect(page.locator('#ai-other')).toBeHidden();
        expect(api.callsTo('PUT', '/api/ai-settings')).toHaveLength(1);
    });

    test('an own OpenAI-compatible service needs its address, model and key before it can be saved, even when following the server', async ({ page, api, campusSession }) => {
        await campusSession.start();
        answerSettings(api, serverGemini({
            server_provider: 'openai',
            server_ready: true,
            effective: { provider: 'openai', label: 'OpenAI-compatible', key_source: 'server', endpoint: 'server', endpoint_host: null, ready: true },
            providers: {
                gemini: provider(),
                anthropic: provider(),
                openai: { ...provider({ server_configured: true, usable: true }), server_endpoint: true, base_url: null, model: null },
            },
        }));

        await page.goto('/notebook/pengaturan-ai');
        await expect(page.locator('#ai-panel-openai')).toBeVisible();
        await expect(page.locator('#btn-save-ai-settings')).toBeDisabled();

        await page.getByRole('radio', { name: /Layanan milikmu sendiri/ }).check({ force: true });
        await page.locator('#openai_base_url').fill('https://llm.example.com/v1');
        await expect(page.locator('#ai-save-consequence')).toHaveText('Isi nama model layananmu.');
        await expect(page.locator('#btn-save-ai-settings')).toBeDisabled();

        await page.locator('#openai_model').fill('student-model');
        await expect(page.locator('#ai-save-consequence')).toHaveText('Tempel API key untuk layananmu.');

        await page.locator('#openai_api_key').fill('sk-student');
        await expect(page.locator('#btn-save-ai-settings')).toBeEnabled();
        await expect(page.locator('#btn-save-ai-settings')).toHaveText('Pakai layananmu');
    });

    test('going back to the server\'s OpenAI-compatible service drops the own service\'s model', async ({ page, api, campusSession }) => {
        await campusSession.start();
        answerSettings(api, serverGemini({
            provider: 'openai',
            effective: { provider: 'openai', label: 'OpenAI-compatible', key_source: 'own', endpoint: 'own', endpoint_host: 'openrouter.ai', ready: true },
            providers: {
                gemini: provider({ server_configured: true, usable: true }),
                anthropic: provider(),
                openai: { ...provider({ has_key: true, key_preview: '••••••••9012', server_configured: true, usable: true }), server_endpoint: true, base_url: 'https://openrouter.ai/api/v1', model: 'meta-llama/xyz' },
            },
        }));

        await page.goto('/notebook/pengaturan-ai');
        await expect(page.locator('#ai-now-summary')).toHaveText('Notebook-mu memakai layananmu di openrouter.ai (model meta-llama/xyz) dengan kunci milikmu (••••••••9012).');
        await expect(page.locator('#openai_model')).toHaveValue('meta-llama/xyz');

        await page.getByRole('radio', { name: /Layanan server WeCIC/ }).check({ force: true });
        await expect(page.locator('#openai_model')).toHaveValue('');
        await expect(page.locator('#openai_base_url')).toBeHidden();
    });

    test('a server OpenAI-compatible provider without an endpoint does not look like an unsaved change', async ({ page, api, campusSession }) => {
        await campusSession.start();
        answerSettings(api, serverGemini({
            server_provider: 'openai',
            server_ready: false,
            effective: { provider: 'openai', label: 'OpenAI-compatible', key_source: 'none', endpoint: 'server', endpoint_host: null, ready: false },
            providers: {
                gemini: provider(),
                anthropic: provider(),
                openai: { ...provider(), server_endpoint: false, base_url: null, model: null },
            },
        }));

        await page.goto('/notebook/pengaturan-ai');
        await expect(page.locator('#ai-now-alert')).toContainText('Server WeCIC belum menyediakan layanan OpenAI-compatible');
        await expect(page.locator('#ai-save-consequence')).toHaveText('Belum ada perubahan.');
        await expect(page.locator('#btn-save-ai-settings')).toBeDisabled();
        await expect(page.getByRole('radio', { name: /Layanan server WeCIC/ })).toBeDisabled();
    });

    test('a settings page that cannot load claims nothing about saved settings', async ({ page, api, campusSession }) => {
        await campusSession.start();
        answerSignedInPages(api).answer('GET', '/api/ai-settings', { status: 500, json: { status: 'error', message: 'Gagal memuat pengaturan AI' } });

        await page.goto('/notebook/pengaturan-ai');

        await expect(page.locator('#ai-now-error')).toContainText('Pengaturan AI belum bisa dimuat');
        await expect(page.locator('#ai-reset-empty')).toHaveText('Pengaturan belum bisa dimuat.');
        await expect(page.locator('#ai-provider-options')).toHaveAttribute('inert', '');
        await expect(page.locator('#btn-save-ai-settings')).toBeDisabled();
    });
});
