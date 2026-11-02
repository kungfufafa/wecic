import { useFocusEffect } from 'expo-router';
import { useCallback, useState } from 'react';

import type { ApiEnvelope } from '@/api/types';
import { useSession } from '@/auth/session-provider';

type ResourceState<T> = {
  data: T | null;
  error: string | null;
  isLoading: boolean;
  isRefreshing: boolean;
};

/**
 * GETs `path` of the API whenever the screen comes into focus, and on
 * pull-to-refresh; a null `path` waits (a detail screen without its id).
 * The last answer stays on screen while a later request fails; `error`
 * then says why. `refresh` asks the portal again (`fresh=1`) instead of a
 * cached copy.
 */
export function useApiResource<T extends ApiEnvelope>(path: string | null, options: { timeoutMs?: number } = {}) {
  const { api } = useSession();
  const [state, setState] = useState<ResourceState<T>>({
    data: null,
    error: null,
    isLoading: path !== null,
    isRefreshing: false,
  });

  const { timeoutMs } = options;
  const load = useCallback(
    async (refreshing: boolean) => {
      if (path === null) {
        return;
      }

      setState((current) => ({ ...current, isLoading: current.data === null, isRefreshing: refreshing }));

      try {
        const data = await api.get<T>(refreshing ? withFresh(path) : path, { timeoutMs });
        setState({ data, error: null, isLoading: false, isRefreshing: false });
      } catch (error) {
        setState((current) => ({
          ...current,
          error: error instanceof Error ? error.message : String(error),
          isLoading: false,
          isRefreshing: false,
        }));
      }
    },
    [api, path, timeoutMs],
  );

  useFocusEffect(
    useCallback(() => {
      void load(false);
    }, [load]),
  );

  return { ...state, refresh: () => load(true), reload: () => load(false) };
}

/** `path` asking for a fresh read: `fresh=1` added to its query. */
export function withFresh(path: string): string {
  return `${path}${path.includes('?') ? '&' : '?'}fresh=1`;
}
