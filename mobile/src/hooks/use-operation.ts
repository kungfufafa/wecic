import { useCallback, useEffect, useRef, useState } from 'react';

import { ApiError } from '@/api/client';
import type { ApiEnvelope } from '@/api/types';
import { useSession } from '@/auth/session-provider';
import { isFinished, OPERATION_POLL_MS, OPERATION_TIMEOUT_MS, operationOutcome, type Operation } from '@/lib/operations';

type OperationResponse = ApiEnvelope & { data: Operation };

export type OperationState =
  | { phase: 'idle' }
  | { phase: 'running'; operation: Operation | null }
  | { phase: 'done'; ok: boolean; message: string };

/**
 * Follows a background operation to its end, every 2 s for at most 4
 * minutes, as the web does. `track` starts following an id; `finish` ends
 * with a message of the caller's own (an answer that needed no polling).
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

      while (!cancelled.current) {
        try {
          const response = await api.get<OperationResponse>(`operations/${operationId}`, { background: true });

          if (isFinished(response.data.status)) {
            const outcome = operationOutcome(response.data);
            finish(outcome.ok, outcome.message);

            return;
          }

          setState({ phase: 'running', operation: response.data });
        } catch (error) {
          if (error instanceof ApiError && error.status !== 0) {
            finish(false, error.message);

            return;
          }
        }

        if (Date.now() - startedAt > OPERATION_TIMEOUT_MS) {
          finish(false, 'Portal belum memberi kabar. Muat ulang halaman untuk melihat apakah kiriman sudah masuk.');

          return;
        }

        await new Promise((resolve) => setTimeout(resolve, OPERATION_POLL_MS));
      }
    },
    [api, finish],
  );

  return { state, track, finish, start: () => setState({ phase: 'running', operation: null }), reset: () => setState({ phase: 'idle' }) };
}
