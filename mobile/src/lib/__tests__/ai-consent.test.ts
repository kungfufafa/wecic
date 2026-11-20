import { ApiError, createApiClient } from '@/api/client';
import {
  aiConsentOf,
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

const GRANTED = { granted: true, granted_at: '2026-11-20T08:00:00.000000Z', withdrawn_at: null, provider: 'Google Gemini' };
const WITHDRAWN = { granted: false, granted_at: null, withdrawn_at: '2026-11-20T09:00:00.000000Z', provider: 'Google Gemini' };

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
    expect(aiConsentOf({ data: { ai_consent: { granted: true } } })).toEqual({ granted: true, granted_at: null, withdrawn_at: null, provider: 'Google Gemini' });
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
    expect(getAiConsent()).toMatchObject({ granted: false, provider: 'Google Gemini' });

    rememberAiConsentFrom(
      new ApiError('Setujui dulu.', 403, { status: 'error', message: 'Setujui dulu.', reason: 'ai_consent_required', data: { ai_consent: WITHDRAWN } } as never),
    );
    expect(getAiConsent()).toEqual(WITHDRAWN);
  });

  it('forgets the consent at sign out', () => {
    setAiConsent(GRANTED);
    clearAiConsent();

    expect(getAiConsent()).toBeNull();
  });
});
