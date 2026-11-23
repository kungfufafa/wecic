import { ApiError, type ApiClient } from '@/api/client';
import type { AiConsent, AiConsentResponse, AiProcessor } from '@/api/types/classes';

/**
 * Notebook AI sends the student's questions and sources to the AI
 * provider(s) the server uses (`provider` in the consent, e.g. Google
 * Gemini), which the server allows only after the student consented
 * (`GET/POST/DELETE ai-consent`). The answer is shared by every screen
 * here, so a consent given on one screen unlocks the others at once.
 */

export const AI_CONSENT_REQUIRED_REASON = 'ai_consent_required';

/** How the provider is named before the server said which one it uses. */
export const AI_PROVIDER_FALLBACK = 'penyedia AI';

/** What Notebook AI sends to the provider, as the consent screen lists it. */
export const AI_CONSENT_SENT_ITEMS = [
  'Pertanyaan yang kamu tulis di Tanya jawab',
  'Teks sumber notebook yang dipakai untuk menjawab dan membuat hasil Studio',
  'Berkas PDF dan gambar yang kamu tambahkan sebagai sumber',
] as const;

/** The provider(s) to name, as the server said (`provider`), or a generic name before it did. */
export function aiProviderLabel(value: Partial<Pick<AiConsent, 'provider'>> | null | undefined): string {
  const provider = value?.provider?.trim();

  return provider ? provider : AI_PROVIDER_FALLBACK;
}

/**
 * The free-tier warning, naming only the providers the server says run on
 * their free tier (`processors`), or all of them when it says only that one
 * does (`free_tier`, an older server); null when none does. A server that
 * does not say at all (an older one) used the Gemini free tier, so the
 * warning stays.
 */
export function aiConsentFreeTierNotice(
  value: Partial<Pick<AiConsent, 'provider' | 'free_tier' | 'processors'>> | null | undefined,
): string | null {
  if (value?.free_tier === false) {
    return null;
  }

  const freeTier = (value?.processors ?? []).filter((processor) => processor.free_tier).map((processor) => processor.label.trim()).filter(Boolean);

  if (value?.processors !== undefined && value.processors.length > 0 && freeTier.length === 0) {
    return null;
  }

  const providers = freeTier.length > 0 ? freeTier.join(' dan ') : aiProviderLabel(value);

  return `WeCIC memakai ${providers} tingkat gratis, sehingga data yang dikirim ke sana dapat disimpan dan dipakai penyedianya untuk meningkatkan produknya, termasuk ditinjau manusia. Jangan kirim data pribadi atau rahasia.`;
}

function processorsOf(value: unknown): AiProcessor[] | undefined {
  if (!Array.isArray(value)) {
    return undefined;
  }

  return value
    .filter((item): item is { label: string; free_tier?: unknown } => typeof (item as { label?: unknown } | null)?.label === 'string')
    .map((item) => ({ label: item.label, free_tier: item.free_tier === true }));
}

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
  const processors = processorsOf(value.processors);
  const pastProcessors = Array.isArray(value.past_processors) ? value.past_processors.filter((label): label is string => typeof label === 'string' && label.trim() !== '') : undefined;

  return {
    granted: value.granted,
    granted_at: value.granted_at ?? null,
    withdrawn_at: value.withdrawn_at ?? null,
    provider: aiProviderLabel(value),
    free_tier: typeof value.free_tier === 'boolean' ? value.free_tier : true,
    ...(typeof value.consent_key === 'string' && value.consent_key !== '' ? { consent_key: value.consent_key } : {}),
    ...(processors !== undefined ? { processors } : {}),
    ...(pastProcessors !== undefined ? { past_processors: pastProcessors } : {}),
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
    setAiConsent({
      ...consent,
      granted: false,
      granted_at: null,
      withdrawn_at: consent?.withdrawn_at ?? null,
      provider: aiProviderLabel(consent),
      free_tier: consent?.free_tier ?? true,
    });
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

/**
 * Agrees to send Notebook content to the AI provider(s) the student was
 * shown: the consent the app knew carries what it is for (`consent_key`).
 * When the server has switched providers since (409), the consent it sends
 * back replaces the one shown, so the screen names the current providers
 * before the student agrees again.
 */
export async function grantAiConsent(api: ApiClient): Promise<AiConsent> {
  const shownKey = consent?.consent_key;

  try {
    return await consentRequest(api.post<AiConsentResponse>('ai-consent', shownKey ? { accept: true, consent_key: shownKey } : { accept: true }));
  } catch (error) {
    if (error instanceof ApiError && error.status === 409) {
      rememberAiConsentFrom(error);
    }

    throw error;
  }
}

/** Withdraws the consent: from now on nothing is sent to the AI provider. */
export function withdrawAiConsent(api: ApiClient): Promise<AiConsent> {
  return consentRequest(api.delete<AiConsentResponse>('ai-consent'));
}
