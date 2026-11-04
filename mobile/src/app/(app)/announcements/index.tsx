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
      subtitle={data?.paging?.total ? `${data.paging.total} pengumuman` : null}
      refreshing={feed.isRefreshing}
      dataSavedAt={feed.savedAt}
      onRefresh={feed.refresh}>
      {feed.error ? <Notice tone="error" message={feed.error} /> : null}
      {notice ? <Notice tone={notice.is_future ? 'warning' : 'info'} message={notice.text ?? ''} /> : null}

      {upcoming.length > 0 ? (
        <Section label="Tenggat mendatang">
          <Panel padded={false}>
            {upcoming.map((deadline, index) => (
              <Fragment key={`${deadline.date}-${index}`}>
                {index > 0 ? <Divider /> : null}
                <View style={styles.deadline}>
                  <Icon name="alarm" size={20} color={theme.accent} />
                  <ThemedText type="small" themeColor="textSecondary" style={styles.flex} numberOfLines={2}>
                    <ThemedText type="smallBold">{formatDate(deadline.date)}</ThemedText>
                    {deadline.title ?? deadline.context ? ` · ${deadline.title ?? deadline.context}` : ''}
                  </ThemedText>
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
            <EmptyState icon="megaphone" title="Belum ada pengumuman" />
          ) : null}
          {visible.map((announcement, index) => (
            <Fragment key={announcement.id}>
              {index > 0 ? <Divider /> : null}
              <Pressable
                accessibilityRole="button"
                onPress={() => router.push({ pathname: '/announcements/[id]', params: { id: announcement.id } })}
                style={({ pressed }) => [styles.row, pressed && { backgroundColor: theme.section }]}>
                <View style={styles.flex}>
                  <ThemedText type="bodyMedium" numberOfLines={2}>
                    {announcement.title}
                  </ThemedText>
                  {announcement.excerpt ? (
                    <ThemedText type="small" themeColor="textTertiary" numberOfLines={1}>
                      {announcement.excerpt}
                    </ThemedText>
                  ) : null}
                  <ThemedText type="caption" themeColor="textTertiary" numberOfLines={1}>
                    {[
                      announcement.publisher_unit_short ?? announcement.publisher,
                      announcement.published_label ?? formatDate(announcement.published_date),
                      announcement.category_label,
                    ]
                      .filter(Boolean)
                      .join(' · ')}
                  </ThemedText>
                </View>
                <Icon name="chevronRight" size={15} color={theme.textQuaternary} />
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
    alignItems: 'center',
    gap: Spacing.three - Spacing.half,
    paddingHorizontal: Spacing.three + Spacing.half,
    paddingVertical: Spacing.two + Spacing.one,
  },
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.three - Spacing.half,
    paddingHorizontal: Spacing.three + Spacing.half,
    paddingVertical: Spacing.two + Spacing.one,
  },
});
