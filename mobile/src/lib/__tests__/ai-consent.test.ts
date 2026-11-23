import { ApiError, createApiClient } from '@/api/client';
import {
  AI_PROVIDER_FALLBACK,
  aiConsentFreeTierNotice,
  aiConsentOf,
  aiProviderLabel,
  clearAiConsent,
  fetchAiConsent,
  getAiConsent,
  grantAiConsent,
  isAiConsentRequired,
  rememberAiConsentFrom,
  setAiConsent,
  subscribeAiConsent,
  withdrawAiConsent,
} from '@/lib/ai-consent';

const GRANTED = { granted: true, granted_at: '2026-11-20T08:00:00.000000Z', withdrawn_at: null, provider: 'Google Gemini', free_tier: true };
const WITHDRAWN = { granted: false, granted_at: null, withdrawn_at: '2026-11-20T09:00:00.000000Z', provider: 'Google Gemini', free_tier: true };
const CLAUDE = { granted: false, granted_at: null, withdrawn_at: null, provider: 'Anthropic Claude', free_tier: false };

function fakeApi(answers: { status: number; body: unknown }[]) {
  const requests: { url: string; method: string; body: unknown }[] = [];
  const fetch = jest.fn(async (url: string, init: RequestInit) => {
    requests.push({ url, method: init.method ?? 'GET', body: init.body ? JSON.parse(String(init.body)) : undefined });
    const answer = answers.shift();

    if (answer === undefined) {
      throw new Error(`No answer left for ${url}`);
    }

    return { status: answer.status, ok: answer.status < 300, json: async () => answer.body } as Response;
  });
  const api = createApiClient({ baseUrl: 'https://wecic.example/api/v1', getToken: () => 'token-1', fetch: fetch as unknown as typeof globalThis.fetch });

  return { api, requests };
}

const consentAnswer = (consent: unknown) => ({ status: 200, body: { status: 'success', message: 'OK', data: { consent } } });

afterEach(() => {
  clearAiConsent();
});

describe('AI consent API', () => {
  it('reads, grants and withdraws the consent, sharing each answer', async () => {
    const { api, requests } = fakeApi([consentAnswer({ ...WITHDRAWN, withdrawn_at: null }), consentAnswer(GRANTED), consentAnswer(WITHDRAWN)]);
    const seen: (boolean | undefined)[] = [];
    const unsubscribe = subscribeAiConsent(() => seen.push(getAiConsent()?.granted));

    await expect(fetchAiConsent(api)).resolves.toMatchObject({ granted: false });
    await expect(grantAiConsent(api)).resolves.toEqual(GRANTED);
    expect(getAiConsent()).toEqual(GRANTED);
    await expect(withdrawAiConsent(api)).resolves.toEqual(WITHDRAWN);
    unsubscribe();

    expect(requests.map((request) => `${request.method} ${request.url}`)).toEqual([
      'GET https://wecic.example/api/v1/ai-consent',
      'POST https://wecic.example/api/v1/ai-consent',
      'DELETE https://wecic.example/api/v1/ai-consent',
    ]);
    expect(requests[1].body).toEqual({ accept: true });
    expect(seen).toEqual([false, true, false]);
    expect(getAiConsent()).toEqual(WITHDRAWN);
  });

  it('sends back what the consent shown was for, and shows the current providers when they changed meanwhile', async () => {
    const shown = { ...WITHDRAWN, consent_key: 'gemini:free', processors: [{ label: 'Google Gemini', free_tier: true }] };
    const current = { ...CLAUDE, consent_key: 'anthropic', processors: [{ label: 'Anthropic Claude', free_tier: false }], past_processors: ['Google Gemini'] };
    setAiConsent(shown);
    const { api, requests } = fakeApi([
      { status: 409, body: { status: 'error', message: 'Penyedia AI Notebook baru saja berubah.', data: { consent: current } } },
      consentAnswer({ ...current, granted: true, granted_at: '2026-11-23T08:00:00.000000Z' }),
    ]);

    await expect(grantAiConsent(api)).rejects.toMatchObject({ status: 409 });
    expect(getAiConsent()).toEqual(current);
    await expect(grantAiConsent(api)).resolves.toMatchObject({ granted: true, provider: 'Anthropic Claude' });

    expect(requests.map((request) => request.body)).toEqual([
      { accept: true, consent_key: 'gemini:free' },
      { accept: true, consent_key: 'anthropic' },
    ]);
  });

  it('keeps what it knew when the server refuses the consent', async () => {
    setAiConsent(WITHDRAWN);
    const { api } = fakeApi([{ status: 422, body: { status: 'error', message: 'Centang persetujuan dulu sebelum mengaktifkan AI Notebook.' } }]);

    await expect(grantAiConsent(api)).rejects.toMatchObject({ status: 422 });

    expect(getAiConsent()).toEqual(WITHDRAWN);
  });

  it('refuses an answer without a readable consent', async () => {
    const { api } = fakeApi([{ status: 200, body: { status: 'success', message: 'OK', data: {} } }]);

    await expect(fetchAiConsent(api)).rejects.toBeInstanceOf(ApiError);
    expect(getAiConsent()).toBeNull();
  });
});

describe('AI consent state', () => {
  it('reads the consent from the consent answer and from the notebook answers', () => {
    expect(aiConsentOf({ data: { consent: GRANTED } })).toEqual(GRANTED);
    expect(aiConsentOf({ data: { notebooks: [], ai_consent: WITHDRAWN } })).toEqual(WITHDRAWN);
    // An answer that does not name the provider gets a generic name, not an assumed one; the free-tier warning stays.
    expect(aiConsentOf({ data: { ai_consent: { granted: true } } })).toEqual({ granted: true, granted_at: null, withdrawn_at: null, provider: AI_PROVIDER_FALLBACK, free_tier: true });
    expect(aiConsentOf({ data: { ai_consent: CLAUDE } })).toEqual(CLAUDE);
    expect(aiConsentOf({ data: { ai_consent: { granted: 'yes' } } })).toBeNull();
    expect(aiConsentOf({ data: {} })).toBeNull();
    expect(aiConsentOf(null)).toBeNull();
  });

  it('knows the refusal for a missing consent and nothing else', () => {
    const refusal = new ApiError('Setujui dulu.', 403, { status: 'error', message: 'Setujui dulu.', reason: 'ai_consent_required' } as never);

    expect(isAiConsentRequired(refusal)).toBe(true);
    expect(isAiConsentRequired(new ApiError('Kamu tidak punya akses ke data ini.', 403, { status: 'error', message: 'x' }))).toBe(false);
    expect(isAiConsentRequired(new ApiError('Setujui dulu.', 422, { status: 'error', message: 'x', reason: 'ai_consent_required' } as never))).toBe(false);
    expect(isAiConsentRequired(new Error('ai_consent_required'))).toBe(false);
  });

  it('remembers the consent of a notebook answer or a refusal, and ignores answers without one', () => {
    rememberAiConsentFrom({ data: { notebook: {} } });
    expect(getAiConsent()).toBeNull();

    rememberAiConsentFrom({ data: { notebook: {}, ai_consent: GRANTED } });
    expect(getAiConsent()).toEqual(GRANTED);

    rememberAiConsentFrom(new ApiError('Setujui dulu.', 403, { status: 'error', message: 'Setujui dulu.', reason: 'ai_consent_required' } as never));
    expect(getAiConsent()).toMatchObject({ granted: false, provider: 'Google Gemini', free_tier: true });

    setAiConsent({ ...CLAUDE, granted: true });
    rememberAiConsentFrom(new ApiError('Setujui dulu.', 403, { status: 'error', message: 'Setujui dulu.', reason: 'ai_consent_required' } as never));
    expect(getAiConsent()).toEqual(CLAUDE);

    rememberAiConsentFrom(
      new ApiError('Setujui dulu.', 403, { status: 'error', message: 'Setujui dulu.', reason: 'ai_consent_required', data: { ai_consent: WITHDRAWN } } as never),
    );
    expect(getAiConsent()).toEqual(WITHDRAWN);
  });

  it('names the provider the server uses and warns about a free tier only when the server says so', () => {
    expect(aiProviderLabel(GRANTED)).toBe('Google Gemini');
    expect(aiProviderLabel(CLAUDE)).toBe('Anthropic Claude');
    expect(aiProviderLabel({ provider: ' ' })).toBe(AI_PROVIDER_FALLBACK);
    expect(aiProviderLabel(null)).toBe(AI_PROVIDER_FALLBACK);

    expect(aiConsentFreeTierNotice(GRANTED)).toContain('WeCIC memakai Google Gemini tingkat gratis');
    expect(aiConsentFreeTierNotice(CLAUDE)).toBeNull();
    expect(aiConsentFreeTierNotice({ provider: 'Anthropic Claude dan Google Gemini', free_tier: true })).toContain('Anthropic Claude dan Google Gemini tingkat gratis');
    // Before the server said, the warning stays.
    expect(aiConsentFreeTierNotice(null)).toContain(`${AI_PROVIDER_FALLBACK} tingkat gratis`);

    // With each provider listed, only those on a free tier are named.
    const split = {
      provider: 'Anthropic Claude dan Google Gemini',
      free_tier: true,
      processors: [
        { label: 'Anthropic Claude', free_tier: false },
        { label: 'Google Gemini', free_tier: true },
      ],
    };
    expect(aiConsentFreeTierNotice(split)).toContain('WeCIC memakai Google Gemini tingkat gratis');
    expect(aiConsentFreeTierNotice(split)).not.toContain('Anthropic');
    expect(aiConsentFreeTierNotice({ ...split, processors: [{ label: 'Anthropic Claude', free_tier: false }] })).toBeNull();
  });

  it('forgets the consent at sign out', () => {
    setAiConsent(GRANTED);
    clearAiConsent();

    expect(getAiConsent()).toBeNull();
  });
});
