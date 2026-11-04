import { useFocusEffect, useRouter } from 'expo-router';
import { useCallback, useRef, useState } from 'react';
import { Pressable, StyleSheet, View } from 'react-native';

import { ApiError } from '@/api/client';
import type { MarkReadResponse, NotificationsResponse, PortalNotification } from '@/api/types';
import { useSession } from '@/auth/session-provider';
import { Button } from '@/components/button';
import { EmptyState } from '@/components/empty-state';
import { Icon, type IconName } from '@/components/icon';
import { Notice } from '@/components/notice';
import { Screen } from '@/components/screen';
import { Divider, Panel, Section } from '@/components/section';
import { SkeletonRows } from '@/components/skeleton';
import { ThemedText } from '@/components/themed-text';
import { Spacing } from '@/constants/theme';
import { NO_COPY_OFFLINE_MESSAGE } from '@/hooks/use-api-resource';
import { setUnreadCount } from '@/hooks/use-unread-count';
import { useTheme } from '@/hooks/use-theme';
import { readCached, writeCached } from '@/lib/device-cache';
import { formatDateTime } from '@/lib/format';
import { routeForPortalUrl } from '@/push/notification-links';

const PAGE_SIZE = 30;

type ListState = {
  items: PortalNotification[];
  unreadCount: number;
  nextBefore: number | null;
  error: string | null;
  isLoading: boolean;
  isRefreshing: boolean;
  isLoadingMore: boolean;
  /** When the first page shown came from the server; set for a copy kept on the device. */
  savedAt: number | null;
};

export default function NotificationsScreen() {
  const { api, state: session } = useSession();
  const router = useRouter();
  const [list, setList] = useState<ListState>({
    items: [],
    unreadCount: 0,
    nextBefore: null,
    error: null,
    isLoading: true,
    isRefreshing: false,
    isLoadingMore: false,
    savedAt: null,
  });
  const owner = session.status === 'signedIn' ? session.owner : null;
  const firstPage = `notifications?limit=${PAGE_SIZE}`;
  const hasItems = useRef(false);
  hasItems.current = list.items.length > 0;

  const loadFirstPage = useCallback(
    async (refreshing: boolean) => {
      // The list kept on the device shows at once, as on the web.
      if (!hasItems.current && !refreshing) {
        const kept = await readCached<NotificationsResponse>(owner, firstPage);

        if (kept !== null) {
          hasItems.current = kept.payload.data.items.length > 0;
          setList((current) => ({
            ...current,
            items: kept.payload.data.items,
            unreadCount: kept.payload.data.unread_count,
            nextBefore: kept.payload.data.has_more ? kept.payload.data.next_before : null,
            isLoading: false,
            savedAt: kept.savedAt,
          }));
        }
      }

      setList((current) => ({ ...current, isRefreshing: refreshing }));

      try {
        const response = await api.get<NotificationsResponse>(firstPage);
        const savedAt = Date.now();
        setUnreadCount(response.data.unread_count);
        setList((current) => ({
          ...current,
          items: response.data.items,
          unreadCount: response.data.unread_count,
          nextBefore: response.data.has_more ? response.data.next_before : null,
          error: null,
          isLoading: false,
          isRefreshing: false,
          savedAt,
        }));
        void writeCached(owner, firstPage, response, savedAt);
      } catch (error) {
        const offline = error instanceof ApiError && error.status === 0;

        setList((current) => ({
          ...current,
          error: offline && current.items.length > 0 ? null : offline ? NO_COPY_OFFLINE_MESSAGE : messageOf(error),
          isLoading: false,
          isRefreshing: false,
        }));
      }
    },
    [api, owner, firstPage],
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

    const route = routeForPortalUrl(notification.url);

    if (route !== null) {
      router.push(route);
    }
  }

  const newestId = list.items.reduce((max, item) => Math.max(max, item.id), 0);
  const canMarkAll = list.unreadCount > 0 && newestId > 0;
  const label = list.isLoading
    ? 'Memuat'
    : list.unreadCount > 0
      ? `${list.unreadCount} belum dibaca`
      : list.items.length > 0
        ? 'Semua sudah dibaca'
        : 'Terbaru';

  return (
    <Screen
      back
      title="Notifikasi"
      accessory={
        canMarkAll ? (
          <Button
            variant="secondary"
            size="small"
            icon="checkAll"
            label="Tandai dibaca"
            onPress={() => markRead({ all: true, up_to: newestId })}
          />
        ) : null
      }
      refreshing={list.isRefreshing}
      dataSavedAt={list.savedAt}
      onRefresh={() => loadFirstPage(true)}>
      {list.error ? <Notice tone="error" message={list.error} /> : null}

      <Section label={label}>
        <Panel padded={false}>
          {list.isLoading ? <SkeletonRows count={3} /> : null}

          {!list.isLoading && list.items.length === 0 ? (
            <EmptyState icon="bellOff" title="Belum ada notifikasi" />
          ) : null}

          {list.items.map((notification, index) => (
            <View key={notification.id}>
              {index > 0 ? <Divider /> : null}
              <NotificationRow notification={notification} onPress={() => open(notification)} />
            </View>
          ))}
        </Panel>
      </Section>

      {list.nextBefore !== null ? (
        <Button variant="secondary" label="Muat lebih banyak" loading={list.isLoadingMore} onPress={loadMore} />
      ) : null}
    </Screen>
  );
}

function NotificationRow({ notification, onPress }: { notification: PortalNotification; onPress: () => void }) {
  const theme = useTheme();
  const look: Record<PortalNotification['urgency'], { icon: IconName; color: string }> = {
    info: { icon: 'bellOutline', color: theme.text },
    warning: { icon: 'warning', color: theme.warning },
    critical: { icon: 'error', color: theme.danger },
  };
  const urgency = look[notification.urgency] ?? look.info;
  const unread = !notification.is_read;

  return (
    <Pressable
      accessibilityRole="button"
      accessibilityHint={unread ? 'Belum dibaca' : undefined}
      onPress={onPress}
      style={({ pressed }) => [styles.row, pressed && { backgroundColor: theme.section }]}>
      <Icon name={urgency.icon} size={22} color={urgency.color} />
      <View style={styles.text}>
        <View style={styles.titleRow}>
          <ThemedText
            type={unread ? 'smallBold' : 'small'}
            themeColor={unread ? 'text' : 'textSecondary'}
            style={styles.title}
            numberOfLines={2}>
            {notification.title}
          </ThemedText>
          {unread ? <View style={[styles.dot, { backgroundColor: theme.badge }]} /> : null}
        </View>
        {notification.body ? (
          <ThemedText type="small" themeColor="textTertiary" numberOfLines={2}>
            {notification.body}
          </ThemedText>
        ) : null}
        <ThemedText type="caption" themeColor="textQuaternary">
          {formatDateTime(notification.created_at)}
        </ThemedText>
      </View>
    </Pressable>
  );
}

function messageOf(error: unknown): string {
  return error instanceof Error ? error.message : String(error);
}

const styles = StyleSheet.create({
  row: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: Spacing.three - Spacing.half,
    paddingHorizontal: Spacing.three + Spacing.half,
    paddingVertical: Spacing.two + Spacing.one,
  },
  text: {
    flex: 1,
    gap: Spacing.half,
  },
  titleRow: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: Spacing.two,
  },
  title: {
    flex: 1,
  },
  dot: {
    width: 8,
    height: 8,
    borderRadius: 4,
    marginTop: 6,
  },
});
