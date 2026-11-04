/**
 * The session's realtime events (Reverb), read as the web does in
 * resources/js/app/realtime.js: which screens an event makes stale, what an
 * upload event says, and what a session notice asks of the app. The
 * connection itself is in realtime/portal-realtime.ts.
 */

import type { RealtimeConfig } from '@/api/types';
import type { Operation } from '@/lib/operations';

export type RealtimeTarget = { channel: string; config: RealtimeConfig };

/** The events the server broadcasts, by the name Echo listens to (the dot skips its namespace). */
export const REALTIME_EVENTS = {
  syncStatus: '.portal.sync.status',
  dataInvalidated: '.portal.data.invalidated',
  uploadProgress: '.portal.upload.progress',
  sessionNotice: '.portal.session.notice',
  notification: '.portal.notification',
} as const;

export type RealtimeEventName = keyof typeof REALTIME_EVENTS;

export type RealtimePayload = Record<string, unknown>;

export type RealtimeStatus = 'off' | 'connecting' | 'connected' | 'unavailable';

/**
 * The channel and server a login or session check names. Null while the
 * server broadcasts nothing (`realtime` null) or does not say (an older
 * server), so the app never tries to connect blindly.
 */
export function realtimeTargetFrom(data: { channel?: unknown; realtime?: unknown } | null | undefined): RealtimeTarget | null {
  const channel = typeof data?.channel === 'string' ? data.channel.trim() : '';
  const config = data?.realtime as Partial<RealtimeConfig> | null | undefined;

  if (channel === '' || !config || typeof config !== 'object') {
    return null;
  }

  const key = typeof config.key === 'string' ? config.key.trim() : '';
  const host = typeof config.host === 'string' ? config.host.trim() : '';
  const scheme = config.scheme === 'http' ? 'http' : 'https';
  const port = Number(config.port);

  if (key === '' || host === '') {
    return null;
  }

  return {
    channel,
    config: { key, host, port: Number.isInteger(port) && port > 0 ? port : scheme === 'https' ? 443 : 80, scheme },
  };
}

/**
 * The API paths a `sync_keys` entry stands for, for the datasets whose
 * `cache_keys` are empty (one snapshot per id: a class, a meeting). A
 * trailing slash means every path under it.
 */
const SYNC_KEY_PATHS: Record<string, string> = {
  'dashboard-overview': 'dashboard',
  'dashboard-action-items': 'action-items',
  'dashboard-pending-assignments': 'assignment/pending',
  'class-index': 'class',
  'class-show': 'class/',
  'meeting-show': 'meeting/',
  profile: 'profile',
  finance: 'finance/',
  'exam-index': 'exam',
  'announcement-index': 'announcement',
  'krs-index': 'academic/krs',
  'krs-show': 'academic/krs/',
  'advising-index': 'academic/advising',
  'grades-index': 'academic/grades',
  'transcript-index': 'academic/transcript',
  'skkm-index': 'skkm',
  'thesis-index': 'thesis',
  'library-index': 'library',
  'library-theses': 'library',
};

/**
 * The API paths a sync or invalidation event makes stale: its `cache_keys`
 * (`/api/dashboard`, as the web requests them) as this client's paths
 * (`dashboard`), plus the paths its `sync_keys` stand for.
 */
export function invalidatedPaths(payload: RealtimePayload | null | undefined): string[] {
  const paths = new Set<string>();

  for (const key of stringList(payload?.cache_keys)) {
    const path = key.replace(/^\/?api\/(?:v\d+\/)?/, '').replace(/^\/+/, '');

    if (path !== '') {
      paths.add(path);
    }
  }

  for (const key of stringList(payload?.sync_keys)) {
    const path = SYNC_KEY_PATHS[key];

    if (path !== undefined) {
      paths.add(path);
    }
  }

  return Array.from(paths);
}

/**
 * Whether a screen's path is among the stale ones: the same path (its
 * query aside), or one under a prefix (`class/` covers `class/9520`;
 * `exam` covers `exam` and `exam/12`).
 */
export function pathIsInvalidated(path: string, invalidated: string[]): boolean {
  const bare = path.replace(/^\/+/, '').split('?')[0];

  return invalidated.some((entry) => {
    if (entry.endsWith('/')) {
      return bare.startsWith(entry);
    }

    return bare === entry || bare.startsWith(`${entry}/`);
  });
}

/** An upload progress event as the operation it reports, or null when it reports another one. */
export function operationFromProgress(payload: RealtimePayload | null | undefined, operationId: string): Operation | null {
  if (payload?.operation_id !== operationId || typeof payload.status !== 'string' || payload.status === '') {
    return null;
  }

  return {
    operation_id: operationId,
    status: payload.status,
    percent: typeof payload.percent === 'number' ? payload.percent : null,
    message: typeof payload.message === 'string' ? payload.message : null,
    detail: typeof payload.detail === 'string' ? payload.detail : null,
    next_step: typeof payload.next_step === 'string' ? payload.next_step : null,
    updated_at: typeof payload.occurred_at === 'string' ? payload.occurred_at : null,
  };
}

export type SessionNoticeAction = 'end' | 'back_online' | 'stay_offline' | 'notice';

/**
 * What a session notice asks of the app: the session ended on the server
 * (a logout elsewhere, the portal session expired or idled out) means
 * logging in again or signing out; an offline session the server managed
 * to reconnect is online again; the rest is news only.
 */
export function sessionNoticeAction(status: unknown): SessionNoticeAction {
  switch (status) {
    case 'logged_out':
    case 'manual_relogin_required':
      return 'end';
    case 'offline_session_resumed':
      return 'back_online';
    case 'offline_resume_unavailable':
      return 'stay_offline';
    default:
      return 'notice';
  }
}

/** The connection's state in the student's words, for the Lainnya tab. */
export function realtimeStatusLabel(status: RealtimeStatus): string {
  switch (status) {
    case 'connected':
      return 'Aktif: perubahan dari portal langsung muncul';
    case 'connecting':
      return 'Menyambung ke server…';
    case 'unavailable':
      return 'Tidak tersedia; layar diperbarui saat dibuka';
    default:
      return 'Belum aktif di server ini';
  }
}

function stringList(value: unknown): string[] {
  return Array.isArray(value) ? value.filter((entry): entry is string => typeof entry === 'string') : [];
}
