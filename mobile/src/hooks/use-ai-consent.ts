import { useCallback, useEffect, useState, useSyncExternalStore } from 'react';

import type { AiConsent } from '@/api/types/classes';
import { useSession } from '@/auth/session-provider';
import { fetchAiConsent, getAiConsent, grantAiConsent, subscribeAiConsent, withdrawAiConsent } from '@/lib/ai-consent';

/**
 * The student's Notebook AI consent, shared by every screen (lib/ai-consent.ts).
 * With `load`, it is read from the server when nothing is known yet;
 * `loading` holds until it is known or the read failed.
 */
export function useAiConsent(options: { load?: boolean } = {}) {
  const { api } = useSession();
  const consent = useSyncExternalStore(subscribeAiConsent, getAiConsent);
  const [busy, setBusy] = useState<'grant' | 'withdraw' | null>(null);
  const [error, setError] = useState<string | null>(null);
  const shouldLoad = options.load === true && consent === null;

  useEffect(() => {
    if (!shouldLoad) {
      return;
    }

    let current = true;

    fetchAiConsent(api).catch((caught: unknown) => {
      if (current) {
        setError(caught instanceof Error ? caught.message : String(caught));
      }
    });

    return () => {
      current = false;
    };
  }, [shouldLoad, api]);

  const change = useCallback(
    async (kind: 'grant' | 'withdraw'): Promise<AiConsent | null> => {
      setBusy(kind);
      setError(null);

      try {
        return await (kind === 'grant' ? grantAiConsent(api) : withdrawAiConsent(api));
      } catch (caught) {
        setError(caught instanceof Error ? caught.message : String(caught));

        return null;
      } finally {
        setBusy(null);
      }
    },
    [api],
  );

  return {
    consent,
    granted: consent?.granted === true,
    loading: shouldLoad && error === null,
    busy,
    error,
    grant: () => change('grant'),
    withdraw: () => change('withdraw'),
  };
}
