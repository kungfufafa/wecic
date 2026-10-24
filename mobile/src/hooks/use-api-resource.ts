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
 * pull-to-refresh. The last answer stays on screen while a later request
 * fails; `error` then says why.
 */
export function useApiResource<T extends ApiEnvelope>(path: string) {
  const { api } = useSession();
  const [state, setState] = useState<ResourceState<T>>({
    data: null,
    error: null,
    isLoading: true,
    isRefreshing: false,
  });

  const load = useCallback(
    async (refreshing: boolean) => {
      setState((current) => ({ ...current, isLoading: current.data === null, isRefreshing: refreshing }));

      try {
        const data = await api.get<T>(path);
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
    [api, path],
  );

  useFocusEffect(
    useCallback(() => {
      void load(false);
    }, [load]),
  );

  return { ...state, refresh: () => load(true) };
}
