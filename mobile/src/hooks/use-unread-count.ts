import * as Notifications from 'expo-notifications';
import { useCallback, useEffect, useSyncExternalStore } from 'react';
import { AppState, Platform } from 'react-native';

import type { UnreadCountResponse } from '@/api/types';
import { useSession } from '@/auth/session-provider';
import { onRealtime } from '@/realtime/portal-realtime';

let unreadCount = 0;
const listeners = new Set<() => void>();

/** Any answer that carries `unread_count` (the list, marking read) keeps the badge current. */
export function setUnreadCount(count: number): void {
  unreadCount = count;
  listeners.forEach((listener) => listener());
}

function subscribe(listener: () => void): () => void {
  listeners.add(listener);

  return () => {
    listeners.delete(listener);
  };
}

/** The last known unread count, for a screen that only shows it. */
export function useUnreadCountValue(): number {
  return useSyncExternalStore(subscribe, () => unreadCount);
}

/**
 * The number of unread notifications for the tab badge: read when the
 * tabs open, when the app comes back to the foreground, when a push
 * arrives while it is open, and when the server broadcasts a new
 * notification (or the socket is back after a drop).
 */
export function useUnreadCount(): number {
  const { api } = useSession();

  const refresh = useCallback(async () => {
    try {
      const response = await api.get<UnreadCountResponse>('notifications/unread-count', { background: true });
      setUnreadCount(response.data.unread_count);
    } catch {
      // The badge keeps its last count.
    }
  }, [api]);

  useEffect(() => {
    void refresh();

    const appState = AppState.addEventListener('change', (status) => {
      if (status === 'active') {
        void refresh();
      }
    });
    const received =
      Platform.OS === 'web'
        ? null
        : Notifications.addNotificationReceivedListener(() => {
            void refresh();
          });

    // A scan sends its notifications in a burst: one read of the count for all of them.
    let timer: ReturnType<typeof setTimeout> | undefined;
    const soon = () => {
      clearTimeout(timer);
      timer = setTimeout(() => void refresh(), 250);
    };
    const broadcast = onRealtime('notification', soon);
    const afterGap = onRealtime('gap', soon);

    return () => {
      clearTimeout(timer);
      appState.remove();
      received?.remove();
      broadcast();
      afterGap();
    };
  }, [refresh]);

  return useUnreadCountValue();
}
