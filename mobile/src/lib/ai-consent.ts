import { ApiError, type ApiClient } from '@/api/client';
import type { AiConsent, AiConsentResponse } from '@/api/types/classes';

/**
 * Notebook AI sends the student's questions and sources to Google Gemini,
 * which the server allows only after the student consented
 * (`GET/POST/DELETE ai-consent`). The answer is shared by every screen
 * here, so a consent given on one screen unlocks the others at once.
 */

export const AI_CONSENT_REQUIRED_REASON = 'ai_consent_required';

export const AI_PROVIDER = 'Google Gemini';

/** What Notebook AI sends to the provider, as the consent screen lists it. */
export const AI_CONSENT_SENT_ITEMS = [
  'Pertanyaan yang kamu tulis di Tanya jawab',
  'Teks sumber notebook yang dipakai untuk menjawab dan membuat hasil Studio',
  'Berkas PDF dan gambar yang kamu tambahkan sebagai sumber',
] as const;

export const AI_CONSENT_FREE_TIER =
  'WeCIC memakai Gemini API tingkat gratis, sehingga data yang dikirim dibagikan ke Google dan dapat disimpan serta dipakai Google untuk meningkatkan produknya, termasuk ditinjau manusia. Jangan kirim data pribadi atau rahasia.';

let consent: AiConsent | null = null;
const listeners = new Set<() => void>();

export function getAiConsent(): AiConsent | null {
  return consent;
}

export function setAiConsent(next: AiConsent | null | undefined): void {
  if (next === undefined) {
    return;
  }

  consent = next;
  listeners.forEach((listener) => listener());
}

/** Forgets the consent the app knew: at sign out, it belongs to the student who left. */
export function clearAiConsent(): void {
  setAiConsent(null);
}

export function subscribeAiConsent(listener: () => void): () => void {
  listeners.add(listener);

  return () => {
    listeners.delete(listener);
  };
}

/** The consent object an answer carries (`data.consent` or `data.ai_consent`), if any. */
export function aiConsentOf(body: unknown): AiConsent | null {
  const data = (body as { data?: { consent?: unknown; ai_consent?: unknown } } | null | undefined)?.data;
  const candidate = data?.consent ?? data?.ai_consent;

  if (candidate === null || typeof candidate !== 'object' || typeof (candidate as { granted?: unknown }).granted !== 'boolean') {
    return null;
  }

  const value = candidate as Partial<AiConsent> & { granted: boolean };

  return {
    granted: value.granted,
    granted_at: value.granted_at ?? null,
    withdrawn_at: value.withdrawn_at ?? null,
    provider: value.provider ?? AI_PROVIDER,
  };
}

/** Whether the server refused an AI action because the student has not consented (403 `reason: ai_consent_required`). */
export function isAiConsentRequired(error: unknown): boolean {
  if (!(error instanceof ApiError) || error.status !== 403) {
    return false;
  }

  return (error.body as { reason?: unknown } | null)?.reason === AI_CONSENT_REQUIRED_REASON;
}

/** Remembers the consent an answer or a refusal carries; a refusal without one still means "not granted". */
export function rememberAiConsentFrom(source: unknown): void {
  const fromBody = aiConsentOf(source instanceof ApiError ? source.body : source);

  if (fromBody !== null) {
    setAiConsent(fromBody);

    return;
  }

  if (isAiConsentRequired(source)) {
    setAiConsent({ granted: false, granted_at: null, withdrawn_at: consent?.withdrawn_at ?? null, provider: consent?.provider ?? AI_PROVIDER });
  }
}

async function consentRequest(request: Promise<AiConsentResponse>): Promise<AiConsent> {
  const response = await request;
  const next = aiConsentOf(response);

  if (next === null) {
    throw new ApiError('Server mengirim data yang tidak bisa dibaca. Coba lagi sebentar lagi.', 200, response);
  }

  setAiConsent(next);

  return next;
}

export function fetchAiConsent(api: ApiClient): Promise<AiConsent> {
  return consentRequest(api.get<AiConsentResponse>('ai-consent'));
}

/** Agrees to send Notebook content to Google Gemini. */
export function grantAiConsent(api: ApiClient): Promise<AiConsent> {
  return consentRequest(api.post<AiConsentResponse>('ai-consent', { accept: true }));
}

/** Withdraws the consent: from now on nothing is sent to Google Gemini. */
export function withdrawAiConsent(api: ApiClient): Promise<AiConsent> {
  return consentRequest(api.delete<AiConsentResponse>('ai-consent'));
}
