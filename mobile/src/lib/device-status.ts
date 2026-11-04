import type { RealtimeStatus } from '@/lib/realtime';
import type { PushRegistration } from '@/push/push-notifications';

/** A status short enough for a tag at the end of a settings row. */
export type StatusTag = { label: string; tone: 'positive' | 'attention' | 'danger' | 'muted' };

/** Whether the WeCIC server reached MyCIC when the student signed in. */
export function portalStatusTag(offline: boolean): StatusTag {
  return offline ? { label: 'Offline', tone: 'attention' } : { label: 'Online', tone: 'positive' };
}

export function pushStatusTag(push: PushRegistration | null): StatusTag {
  if (push === null) {
    return { label: 'Mendaftar…', tone: 'attention' };
  }

  if (push.status === 'registered') {
    return { label: 'Aktif', tone: 'positive' };
  }

  if (push.status === 'denied') {
    return { label: 'Ditolak', tone: 'danger' };
  }

  return { label: 'Tidak tersedia', tone: 'muted' };
}

export function realtimeStatusTag(status: RealtimeStatus): StatusTag {
  switch (status) {
    case 'connected':
      return { label: 'Aktif', tone: 'positive' };
    case 'connecting':
      return { label: 'Menyambung…', tone: 'attention' };
    case 'unavailable':
      return { label: 'Tidak tersedia', tone: 'muted' };
    default:
      return { label: 'Belum aktif', tone: 'muted' };
  }
}
