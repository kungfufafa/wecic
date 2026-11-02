import { useFocusEffect, useRouter } from 'expo-router';
import { useCallback, useState } from 'react';
import { Pressable, StyleSheet, View } from 'react-native';

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
import { setUnreadCount } from '@/hooks/use-unread-count';
import { useTheme } from '@/hooks/use-theme';
import { formatDateTime } from '@/lib/format';
import { routeForPortalUrl } from '@/push/notification-links';

const PAGE_SIZE = 30;
const ICON_SIZE = 40;

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
      onRefresh={() => loadFirstPage(true)}>
      {list.error ? <Notice tone="error" message={list.error} /> : null}

      <Section label={label}>
        <Panel padded={false}>
          {list.isLoading ? <SkeletonRows count={3} /> : null}

          {!list.isLoading && list.items.length === 0 ? (
            <EmptyState
              icon="bellOff"
              title="Belum ada notifikasi"
              description="Pengingat kelas, tenggat tugas, dan pembaruan dari portal akan muncul di sini."
            />
          ) : null}

          {list.items.map((notification, index) => (
            <View key={notification.id}>
              {index > 0 ? <Divider inset={Spacing.three + ICON_SIZE + Spacing.three - Spacing.one} /> : null}
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
  const look: Record<PortalNotification['urgency'], { icon: IconName; color: string; tint: string }> = {
    info: { icon: 'bell', color: theme.brand, tint: theme.brandTint },
    warning: { icon: 'warning', color: theme.warning, tint: theme.warningTint },
    critical: { icon: 'error', color: theme.danger, tint: theme.dangerTint },
  };
  const urgency = look[notification.urgency] ?? look.info;
  const unread = !notification.is_read;

  return (
    <Pressable
      accessibilityRole="button"
      accessibilityHint={unread ? 'Belum dibaca' : undefined}
      onPress={onPress}
      style={({ pressed }) => [styles.row, pressed && { backgroundColor: theme.section }]}>
      <View style={[styles.iconCircle, { backgroundColor: urgency.tint }]}>
        <Icon name={urgency.icon} size={18} color={urgency.color} />
      </View>
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
          <ThemedText type="small" themeColor="textTertiary" numberOfLines={3}>
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
    gap: Spacing.three - Spacing.one,
    padding: Spacing.three,
  },
  iconCircle: {
    width: ICON_SIZE,
    height: ICON_SIZE,
    borderRadius: ICON_SIZE / 2,
    alignItems: 'center',
    justifyContent: 'center',
  },
  text: {
    flex: 1,
    gap: Spacing.one,
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
