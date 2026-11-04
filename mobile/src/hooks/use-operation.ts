import { useCallback, useEffect, useRef, useState } from 'react';

import { ApiError } from '@/api/client';
import type { ApiEnvelope } from '@/api/types';
import { useSession } from '@/auth/session-provider';
import { isFinished, OPERATION_POLL_MS, OPERATION_POLL_REALTIME_MS, OPERATION_TIMEOUT_MS, operationOutcome, type Operation } from '@/lib/operations';
import { operationFromProgress } from '@/lib/realtime';
import { getRealtimeStatus, onRealtime } from '@/realtime/portal-realtime';

type OperationResponse = ApiEnvelope & { data: Operation };

export type OperationState =
  | { phase: 'idle' }
  | { phase: 'running'; operation: Operation | null }
  | { phase: 'done'; ok: boolean; message: string };

/**
 * Follows a background operation to its end, for at most 4 minutes, as
 * the web does: every step the server broadcasts shows at once, and the
 * server is asked every 2 s (every 15 s while the realtime channel is
 * connected, as a safety net). `track` starts following an id; `finish`
 * ends with a message of the caller's own (an answer that needed no polling).
 */
export function useOperation(onFinished?: (ok: boolean) => void) {
  const { api } = useSession();
  const [state, setState] = useState<OperationState>({ phase: 'idle' });
  const cancelled = useRef(false);

  useEffect(() => {
    cancelled.current = false;

    return () => {
      cancelled.current = true;
    };
  }, []);

  const finish = useCallback(
    (ok: boolean, message: string) => {
      if (!cancelled.current) {
        setState({ phase: 'done', ok, message });
        onFinished?.(ok);
      }
    },
    [onFinished],
  );

  const track = useCallback(
    async (operationId: string) => {
      setState({ phase: 'running', operation: null });
      const startedAt = Date.now();
      let ended = false;
      let wake: () => void = () => {};

      const end = (operation: Operation) => {
        if (!ended) {
          ended = true;
          const outcome = operationOutcome(operation);
          finish(outcome.ok, outcome.message);
        }
      };

      const unsubscribe = onRealtime('uploadProgress', (payload) => {
        const operation = operationFromProgress(payload, operationId);

        if (operation === null || ended) {
          return;
        }

        if (isFinished(operation.status)) {
          end(operation);
          wake();
        } else {
          setState({ phase: 'running', operation });
        }
      });

      try {
        while (!cancelled.current && !ended) {
          try {
            const response = await api.get<OperationResponse>(`operations/${operationId}`, { background: true });

            if (ended) {
              return;
            }

            if (isFinished(response.data.status)) {
              end(response.data);

              return;
            }

            setState({ phase: 'running', operation: response.data });
          } catch (error) {
            if (error instanceof ApiError && error.status !== 0) {
              ended = true;
              finish(false, error.message);

              return;
            }
          }

          if (ended) {
            return;
          }

          if (Date.now() - startedAt > OPERATION_TIMEOUT_MS) {
            ended = true;
            finish(false, 'Portal belum memberi kabar. Muat ulang halaman untuk melihat apakah kiriman sudah masuk.');

            return;
          }

          await new Promise<void>((resolve) => {
            wake = resolve;
            setTimeout(resolve, getRealtimeStatus() === 'connected' ? OPERATION_POLL_REALTIME_MS : OPERATION_POLL_MS);
          });
        }
      } finally {
        unsubscribe();
      }
    },
    [api, finish],
  );

  return { state, track, finish, start: () => setState({ phase: 'running', operation: null }), reset: () => setState({ phase: 'idle' }) };
}
