import { useFocusEffect, useRouter } from 'expo-router';
import { useCallback, useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, View } from 'react-native';

import type { MarkReadResponse, NotificationsResponse, PortalNotification } from '@/api/types';
import { useSession } from '@/auth/session-provider';
import { Button } from '@/components/button';
import { Card } from '@/components/card';
import { Notice } from '@/components/notice';
import { Screen } from '@/components/screen';
import { ThemedText } from '@/components/themed-text';
import { Spacing } from '@/constants/theme';
import { setUnreadCount } from '@/hooks/use-unread-count';
import { useTheme } from '@/hooks/use-theme';
import { formatDateTime } from '@/lib/format';
import { routeForNotificationUrl } from '@/push/notification-links';

const PAGE_SIZE = 30;

type ListState = {
  items: PortalNotification[];
  unreadCount: number;
  nextBefore: number | null;
  error: string | null;
  isLoading: boolean;
  isRefreshing: boolean;
  isLoadingMore: boolean;
};

export default function NotificationsScreen() {
  const { api } = useSession();
  const router = useRouter();
  const [list, setList] = useState<ListState>({
    items: [],
    unreadCount: 0,
    nextBefore: null,
    error: null,
    isLoading: true,
    isRefreshing: false,
    isLoadingMore: false,
  });

  const loadFirstPage = useCallback(
    async (refreshing: boolean) => {
      setList((current) => ({ ...current, isRefreshing: refreshing }));

      try {
        const response = await api.get<NotificationsResponse>(`notifications?limit=${PAGE_SIZE}`);
        setUnreadCount(response.data.unread_count);
        setList((current) => ({
          ...current,
          items: response.data.items,
          unreadCount: response.data.unread_count,
          nextBefore: response.data.has_more ? response.data.next_before : null,
          error: null,
          isLoading: false,
          isRefreshing: false,
        }));
      } catch (error) {
        setList((current) => ({ ...current, error: messageOf(error), isLoading: false, isRefreshing: false }));
      }
    },
    [api],
  );

  useFocusEffect(
    useCallback(() => {
      void loadFirstPage(false);
    }, [loadFirstPage]),
  );

  async function loadMore() {
    if (list.nextBefore === null) {
      return;
    }

    setList((current) => ({ ...current, isLoadingMore: true }));

    try {
      const response = await api.get<NotificationsResponse>(`notifications?limit=${PAGE_SIZE}&before=${list.nextBefore}`);
      setList((current) => ({
        ...current,
        items: [...current.items, ...response.data.items],
        nextBefore: response.data.has_more ? response.data.next_before : null,
        isLoadingMore: false,
      }));
    } catch (error) {
      setList((current) => ({ ...current, error: messageOf(error), isLoadingMore: false }));
    }
  }

  async function markRead(body: { ids: number[] } | { all: true; up_to: number }) {
    const ids = 'ids' in body ? body.ids : null;

    setList((current) => ({
      ...current,
      items: current.items.map((item) => (ids === null || ids.includes(item.id) ? { ...item, is_read: true } : item)),
    }));

    try {
      const response = await api.post<MarkReadResponse>('notifications/read', body);
      setUnreadCount(response.data.unread_count);
      setList((current) => ({ ...current, unreadCount: response.data.unread_count }));
    } catch (error) {
      setList((current) => ({ ...current, error: messageOf(error) }));
    }
  }

  function open(notification: PortalNotification) {
    if (!notification.is_read) {
      void markRead({ ids: [notification.id] });
    }

    const route = routeForNotificationUrl(notification.url);

    if (route !== '/notifications') {
      router.navigate(route);
    }
  }

  const newestId = list.items.reduce((max, item) => Math.max(max, item.id), 0);

  return (
    <Screen
      title="Notifikasi"
      subtitle={list.unreadCount > 0 ? `${list.unreadCount} belum dibaca` : null}
      refreshing={list.isRefreshing}
      onRefresh={() => loadFirstPage(true)}>
      {list.error ? <Notice tone="error" message={list.error} /> : null}
      {list.isLoading ? <ActivityIndicator /> : null}

      {list.unreadCount > 0 && newestId > 0 ? (
        <Button variant="secondary" label="Tandai semua sudah dibaca" onPress={() => markRead({ all: true, up_to: newestId })} />
      ) : null}

      {!list.isLoading && list.items.length === 0 && !list.error ? (
        <Card>
          <ThemedText type="small" themeColor="textSecondary">
            Belum ada notifikasi.
          </ThemedText>
        </Card>
      ) : null}

      {list.items.map((notification) => (
        <NotificationRow key={notification.id} notification={notification} onPress={() => open(notification)} />
      ))}

      {list.nextBefore !== null ? (
        <Button variant="secondary" label="Muat lebih banyak" loading={list.isLoadingMore} onPress={loadMore} />
      ) : null}
    </Screen>
  );
}

function NotificationRow({ notification, onPress }: { notification: PortalNotification; onPress: () => void }) {
  const theme = useTheme();
  const accent = notification.urgency === 'critical' ? theme.danger : notification.urgency === 'warning' ? theme.warning : theme.primary;

  return (
    <Pressable
      accessibilityRole="button"
      accessibilityHint={notification.is_read ? undefined : 'Belum dibaca'}
      onPress={onPress}>
      {({ pressed }) => (
        <Card style={pressed ? { backgroundColor: theme.backgroundSelected } : undefined}>
          <View style={styles.row}>
            <View style={[styles.dot, { backgroundColor: notification.is_read ? 'transparent' : accent }]} />
            <View style={styles.text}>
              <ThemedText type="smallBold" themeColor={notification.is_read ? 'textSecondary' : 'text'}>
                {notification.title}
              </ThemedText>
              {notification.body ? (
                <ThemedText type="small" themeColor="textSecondary">
                  {notification.body}
                </ThemedText>
              ) : null}
              <ThemedText type="caption" themeColor="textTertiary">
                {formatDateTime(notification.created_at)}
              </ThemedText>
            </View>
          </View>
        </Card>
      )}
    </Pressable>
  );
}

function messageOf(error: unknown): string {
  return error instanceof Error ? error.message : String(error);
}

const styles = StyleSheet.create({
  row: {
    flexDirection: 'row',
    gap: Spacing.two,
  },
  dot: {
    width: 8,
    height: 8,
    borderRadius: 4,
    marginTop: 6,
  },
  text: {
    flex: 1,
    gap: Spacing.half,
  },
});
