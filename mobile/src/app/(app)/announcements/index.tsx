import { useRouter } from 'expo-router';
import { Fragment, useState } from 'react';
import { Pressable, ScrollView, StyleSheet, View } from 'react-native';

import type { Announcement, AnnouncementsResponse } from '@/api/types/admin';
import { useSession } from '@/auth/session-provider';
import { Button } from '@/components/button';
import { Chip } from '@/components/chip';
import { EmptyState } from '@/components/empty-state';
import { Icon } from '@/components/icon';
import { Notice } from '@/components/notice';
import { Screen } from '@/components/screen';
import { Divider, Panel, Section } from '@/components/section';
import { SkeletonRows } from '@/components/skeleton';
import { ThemedText } from '@/components/themed-text';
import { Spacing } from '@/constants/theme';
import { useApiResource } from '@/hooks/use-api-resource';
import { useTheme } from '@/hooks/use-theme';
import { announcementCategories, appendUnique } from '@/lib/admin';
import { formatDate } from '@/lib/format';

type MorePages = { base: AnnouncementsResponse | null; items: Announcement[]; nextPage: number | null };

export default function AnnouncementsScreen() {
  const theme = useTheme();
  const router = useRouter();
  const { api } = useSession();
  const feed = useApiResource<AnnouncementsResponse>('announcement');
  const [more, setMore] = useState<MorePages>({ base: null, items: [], nextPage: null });
  const [loadingMore, setLoadingMore] = useState(false);
  const [moreError, setMoreError] = useState<string | null>(null);
  const [category, setCategory] = useState<string | null>(null);
  const data = feed.data?.data;
  // Pages loaded after a first page belong to it; a fresh first page starts over.
  const pages: MorePages =
    more.base === feed.data ? more : { base: feed.data, items: [], nextPage: data?.paging?.has_more ? (data.paging.next_page ?? null) : null };
  const nextPage = pages.nextPage;
  const items = appendUnique(data?.items ?? [], pages.items);
  const categories = announcementCategories(items);
  const visible = category === null ? items : items.filter((item) => item.category === category);
  const notice = data?.notice?.text ? data.notice : null;
  const upcoming = data?.upcoming_deadlines ?? [];

  async function loadMore() {
    if (nextPage === null) {
      return;
    }

    setLoadingMore(true);
    setMoreError(null);

    try {
      const response = await api.get<AnnouncementsResponse>(`announcement?page=${nextPage}`);
      setMore({
        base: pages.base,
        items: appendUnique(pages.items, response.data.items),
        nextPage: response.data.paging?.has_more ? (response.data.paging.next_page ?? null) : null,
      });
    } catch (error) {
      setMoreError(error instanceof Error ? error.message : String(error));
    } finally {
      setLoadingMore(false);
    }
  }

  return (
    <Screen
      back
      title="Pengumuman"
      subtitle={data?.paging?.total ? `${data.paging.total} pengumuman dari kampus` : 'Info resmi dari kampus'}
      refreshing={feed.isRefreshing}
      onRefresh={feed.refresh}>
      {feed.error ? <Notice tone="error" message={feed.error} /> : null}
      {notice ? <Notice tone={notice.is_future ? 'warning' : 'info'} message={notice.text ?? ''} /> : null}

      {upcoming.length > 0 ? (
        <Section label="Tenggat mendatang">
          <Panel padded={false}>
            {upcoming.map((deadline, index) => (
              <Fragment key={`${deadline.date}-${index}`}>
                {index > 0 ? <Divider inset={Spacing.three} /> : null}
                <View style={styles.deadline}>
                  <Icon name="alarm" size={18} color={theme.accent} />
                  <View style={styles.flex}>
                    <ThemedText type="smallBold">{formatDate(deadline.date)}</ThemedText>
                    <ThemedText type="small" themeColor="textTertiary" numberOfLines={3}>
                      {deadline.title ?? deadline.context ?? ''}
                    </ThemedText>
                  </View>
                </View>
              </Fragment>
            ))}
          </Panel>
        </Section>
      ) : null}

      <Section label="Terbaru">
        {categories.length > 1 ? (
          <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.chips}>
            <Chip label="Semua" selected={category === null} onPress={() => setCategory(null)} />
            {categories.map((item) => (
              <Chip key={item.key} label={item.label} selected={category === item.key} onPress={() => setCategory(item.key)} />
            ))}
          </ScrollView>
        ) : null}
        <Panel padded={false}>
          {feed.isLoading ? <SkeletonRows count={3} /> : null}
          {!feed.isLoading && visible.length === 0 && !feed.error ? (
            <EmptyState icon="megaphone" title="Belum ada pengumuman" description="Pengumuman dari kampus akan tampil di sini." />
          ) : null}
          {visible.map((announcement, index) => (
            <Fragment key={announcement.id}>
              {index > 0 ? <Divider inset={Spacing.three} /> : null}
              <Pressable
                accessibilityRole="button"
                onPress={() => router.push({ pathname: '/announcements/[id]', params: { id: announcement.id } })}
                style={({ pressed }) => [styles.row, pressed && { backgroundColor: theme.section }]}>
                <ThemedText type="caption" themeColor="textTertiary">
                  {[announcement.publisher_unit_short ?? announcement.publisher, announcement.published_label ?? formatDate(announcement.published_date)]
                    .filter(Boolean)
                    .join(' · ')}
                </ThemedText>
                <ThemedText type="bodyMedium" numberOfLines={3}>
                  {announcement.title}
                </ThemedText>
                {announcement.excerpt ? (
                  <ThemedText type="small" themeColor="textTertiary" numberOfLines={2}>
                    {announcement.excerpt}
                  </ThemedText>
                ) : null}
                {announcement.category_label ? (
                  <View style={[styles.tag, { backgroundColor: theme.elevated }]}>
                    <ThemedText type="caption" themeColor="textSecondary">
                      {announcement.category_label}
                    </ThemedText>
                  </View>
                ) : null}
              </Pressable>
            </Fragment>
          ))}
        </Panel>
      </Section>

      {moreError ? <Notice tone="error" message={moreError} /> : null}
      {nextPage !== null ? <Button variant="secondary" label="Muat lebih banyak" loading={loadingMore} onPress={loadMore} /> : null}
    </Screen>
  );
}

const styles = StyleSheet.create({
  flex: {
    flex: 1,
    gap: Spacing.half,
  },
  chips: {
    gap: Spacing.two,
    paddingHorizontal: Spacing.one,
  },
  deadline: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: Spacing.three - Spacing.one,
    padding: Spacing.three,
  },
  row: {
    gap: Spacing.one + Spacing.half,
    padding: Spacing.three,
  },
  tag: {
    alignSelf: 'flex-start',
    paddingHorizontal: Spacing.two + Spacing.half,
    paddingVertical: Spacing.half + 1,
    borderRadius: 999,
    marginTop: Spacing.one,
  },
});
