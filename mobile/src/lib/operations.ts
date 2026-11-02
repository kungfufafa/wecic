/**
 * Uploads run in the background on the server: `POST` answers 202 with an
 * operation id, and `GET operations/{id}` tells how far it got.
 */

export type OperationStatus =
  | 'queued'
  | 'connecting'
  | 'relogin'
  | 'fetching_schedule'
  | 'file_received'
  | 'portal_uploading'
  | 'validation_succeeded'
  | 'submission_saved'
  | 'completed'
  | 'failed'
  | 'unverified'
  | (string & {});

export type Operation = {
  operation_id: string;
  type?: string;
  status: OperationStatus;
  percent?: number | null;
  message?: string | null;
  detail?: string | null;
  next_step?: string | null;
  updated_at?: string | null;
};

export const OPERATION_POLL_MS = 2000;
export const OPERATION_TIMEOUT_MS = 4 * 60 * 1000;

export function isFinished(status: OperationStatus): boolean {
  return status === 'completed' || status === 'failed' || status === 'unverified';
}

/** What to tell the student about an operation that ended, as the web words it. */
export function operationOutcome(operation: Operation): { ok: boolean; message: string } {
  const message = operation.message?.trim() || (operation.status === 'completed' ? 'Berhasil dikirim ke portal.' : 'Pengiriman ke portal gagal.');

  if (operation.status === 'completed') {
    return { ok: true, message };
  }

  return { ok: false, message: [message, operation.next_step?.trim()].filter(Boolean).join(' ') };
}

/** The operation id an answer carries (202 started, 409 already running), if any. */
export function operationIdOf(body: unknown): string | null {
  const data = (body as { data?: { operation_id?: unknown } } | null | undefined)?.data;

  return typeof data?.operation_id === 'string' && data.operation_id !== '' ? data.operation_id : null;
}

/** Whether an offline session put the request in the outbox instead of sending it. */
export function isQueuedOffline(body: unknown): boolean {
  return (body as { data?: { status?: unknown } } | null | undefined)?.data?.status === 'queued_offline';
}
