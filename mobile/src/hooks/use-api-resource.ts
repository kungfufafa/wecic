import { useFocusEffect } from 'expo-router';
import { useCallback, useEffect, useRef, useState } from 'react';

import { ApiError, TIMEOUT_ERROR_MESSAGE } from '@/api/client';
import type { ApiEnvelope } from '@/api/types';
import { useSession } from '@/auth/session-provider';
import { useReachable } from '@/hooks/use-connectivity';
import { isFresh, policyFor } from '@/lib/cache-policies';
import { readCached, writeCached } from '@/lib/device-cache';

export const NO_COPY_OFFLINE_MESSAGE = 'Belum ada data tersimpan untuk halaman ini. Sambungkan internet untuk memuatnya.';

type ResourceState<T> = {
  data: T | null;
  error: string | null;
  isLoading: boolean;
  isRefreshing: boolean;
  /** When the shown data came from the server; set for a copy kept on the device. */
  savedAt: number | null;
  /** The shown data is a copy kept on the device, not this visit's answer. */
  fromDevice: boolean;
  /** The path the shown data answers; another path's data never stands in. */
  dataPath: string | null;
};

/**
 * GETs `path` of the API whenever the screen comes into focus, and on
 * pull-to-refresh; a null `path` waits (a detail screen without its id).
 *
 * Offline-first, as the web app: a copy kept on the device (rules in
 * lib/cache-policies.ts) shows at once; a copy still fresh is used without
 * asking the server; otherwise the server is asked and its answer replaces
 * the copy. Without a connection the copy stays on screen and `error` is
 * left empty (the screen's offline strip says why); with neither, `error`
 * says there is nothing kept yet. `refresh` asks the portal again
 * (`fresh=1`); `reload` asks the server again after a change.
 */
export function useApiResource<T extends ApiEnvelope>(path: string | null, options: { timeoutMs?: number } = {}) {
  const { api, state: session } = useSession();
  const owner = session.status === 'signedIn' ? session.owner : null;
  const [state, setState] = useState<ResourceState<T>>({
    data: null,
    error: null,
    isLoading: path !== null,
    isRefreshing: false,
    savedAt: null,
    fromDevice: false,
    dataPath: null,
  });
  const latest = useRef(state);
  latest.current = state;
  const { timeoutMs } = options;

  const load = useCallback(
    async (mode: 'focus' | 'reload' | 'refresh') => {
      if (path === null) {
        return;
      }

      const policy = policyFor(path);
      let current = latest.current;

      if (current.dataPath !== path) {
        current = { ...current, data: null, savedAt: null, fromDevice: false, dataPath: null };
      }

      if (current.data === null && mode !== 'refresh') {
        const kept = await readCached<T>(owner, path);

        if (kept !== null) {
          current = { data: kept.payload, error: null, isLoading: false, isRefreshing: false, savedAt: kept.savedAt, fromDevice: true, dataPath: path };
          setState(current);
        }
      }

      // A copy (or this visit's answer) still fresh stands in for the server, as on the web.
      if (mode === 'focus' && current.data !== null && current.savedAt !== null && policy !== null && isFresh(policy, current.savedAt)) {
        return;
      }

      setState((previous) => ({ ...previous, isLoading: current.data === null, isRefreshing: mode === 'refresh' }));

      try {
        const data = await api.get<T>(mode === 'refresh' ? withFresh(path) : path, { timeoutMs });
        const savedAt = Date.now();
        setState({ data, error: null, isLoading: false, isRefreshing: false, savedAt, fromDevice: false, dataPath: path });
        void writeCached(owner, path, data, savedAt);
      } catch (error) {
        const offline = error instanceof ApiError && error.status === 0 && error.message !== TIMEOUT_ERROR_MESSAGE;
        const shown = current.data !== null;

        setState((previous) => ({
          ...previous,
          ...(previous.dataPath !== path ? { data: null, savedAt: null, fromDevice: false, dataPath: null } : {}),
          error: offline ? (shown ? null : NO_COPY_OFFLINE_MESSAGE) : error instanceof Error ? error.message : String(error),
          isLoading: false,
          isRefreshing: false,
        }));
      }
    },
    [api, path, timeoutMs, owner],
  );

  useFocusEffect(
    useCallback(() => {
      void load('focus');
    }, [load]),
  );

  // Back online: a screen showing a kept copy, or nothing, asks the server again.
  const reachable = useReachable();
  const wasReachable = useRef(reachable);

  useEffect(() => {
    if (reachable && !wasReachable.current && (latest.current.fromDevice || latest.current.error !== null)) {
      void load('reload');
    }

    wasReachable.current = reachable;
  }, [reachable, load]);

  return { ...state, refresh: () => load('refresh'), reload: () => load('reload') };
}

/** `path` asking for a fresh read: `fresh=1` added to its query. */
export function withFresh(path: string): string {
  return `${path}${path.includes('?') ? '&' : '?'}fresh=1`;
}
