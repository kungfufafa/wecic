import * as Notifications from 'expo-notifications';
import { useRouter } from 'expo-router';
import { useEffect } from 'react';
import { Platform } from 'react-native';

import type { MarkReadResponse } from '@/api/types';
import { useSession } from '@/auth/session-provider';
import { setUnreadCount } from '@/hooks/use-unread-count';
import { routeForNotificationUrl } from '@/push/notification-links';

/**
 * A tapped push opens the screen for its notification (`data.url`) and
 * marks it read. A tap that launched the app is handled once the tabs are
 * mounted. Native only: the web preview has no pushes.
 */
export function useNotificationTaps(): void {
  const router = useRouter();
  const { api } = useSession();

  useEffect(() => {
    if (Platform.OS === 'web') {
      return;
    }

    function open(response: Notifications.NotificationResponse) {
      if (response.actionIdentifier !== Notifications.DEFAULT_ACTION_IDENTIFIER) {
        return;
      }

      const data = response.notification.request.content.data ?? {};
      router.navigate(routeForNotificationUrl(data.url));

      if (typeof data.notification_id === 'number') {
        void api
          .post<MarkReadResponse>('notifications/read', { ids: [data.notification_id] })
          .then((result) => setUnreadCount(result.data.unread_count))
          .catch(() => {});
      }
    }

    const launchedBy = Notifications.getLastNotificationResponse();

    if (launchedBy !== null) {
      open(launchedBy);
      Notifications.clearLastNotificationResponse();
    }

    const subscription = Notifications.addNotificationResponseReceivedListener((response) => {
      open(response);
      Notifications.clearLastNotificationResponse();
    });

    return () => subscription.remove();
  }, [api, router]);
}
