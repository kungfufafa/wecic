import { useRouter } from 'expo-router';
import { useEffect } from 'react';
import { Pressable, StyleSheet, View } from 'react-native';

import type { ActionItemsResponse, DashboardResponse, TodayClass } from '@/api/types';
import { ActionItemRows } from '@/components/action-items';
import { useSession } from '@/auth/session-provider';
import { Avatar } from '@/components/avatar';
import { Button } from '@/components/button';
import { Chip } from '@/components/chip';
import { EmptyState } from '@/components/empty-state';
import { FeatureGrid } from '@/components/feature-grid';
import { IconButton } from '@/components/icon-button';
import { joinMeta } from '@/components/meta-row';
import { Notice } from '@/components/notice';
import { Screen } from '@/components/screen';
import { Divider, Panel, Section } from '@/components/section';
import { SkeletonRows } from '@/components/skeleton';
import { ThemedText } from '@/components/themed-text';
import { type Palette, Spacing } from '@/constants/theme';
import { useApiResource } from '@/hooks/use-api-resource';
import { useReachable } from '@/hooks/use-connectivity';
import { setKnownUser, useKnownUser } from '@/hooks/use-known-user';
import { useCourseColors, useTheme } from '@/hooks/use-theme';
import { useUnreadCountValue } from '@/hooks/use-unread-count';
import { ALL_SERVICES_FEATURE, featuresByKey, HOME_FEATURE_KEYS } from '@/lib/features';
import { todayClassRoute } from '@/lib/today';
import {
  attendanceSummary,
  attendedCount,
  courseColorIndex,
  firstName,
  formatShortDate,
  greeting,
  initials,
  shortTime,
  type Tone,
} from '@/lib/format';

const HomeFeatures = [...featuresByKey(HOME_FEATURE_KEYS), ALL_SERVICES_FEATURE];

export default function HomeScreen() {
  const router = useRouter();
  const { state } = useSession();
  const unreadCount = useUnreadCountValue();
  const reachable = useReachable();
  const dashboard = useApiResource<DashboardResponse>('dashboard');
  const knownUser = useKnownUser();
  const sessionUser = state.status === 'signedIn' ? state.user : null;
  const user = dashboard.data?.data.user_info ?? knownUser ?? sessionUser;
  const summary = dashboard.data?.data.classes_summary;
  const todayClasses = summary?.today_classes ?? [];
  const offline = state.status === 'signedIn' && state.offline;
  const actionItems = useApiResource<ActionItemsResponse>('action-items');
  const items = actionItems.data?.data.items ?? [];
  const dayLabel = summary?.day ? `${summary.day}${summary.date ? `, ${formatShortDate(summary.date)}` : ''}` : 'Hari ini';

  function refreshAll() {
    void dashboard.refresh();
    void actionItems.refresh();
  }

  useEffect(() => {
    setKnownUser(dashboard.data?.data.user_info);
  }, [dashboard.data]);

  return (
    <Screen
      refreshing={dashboard.isRefreshing}
      dataSavedAt={dashboard.savedAt}
      onRefresh={refreshAll}
      header={
        <View style={styles.header}>
          <Avatar initials={initials(user)} size={44} />
          <View style={styles.headerText}>
            <ThemedText type="caption" themeColor="textTertiary">
              {greeting()}
            </ThemedText>
            <ThemedText type="subtitle" numberOfLines={1}>
              {firstName(user) ?? 'Mahasiswa'}
            </ThemedText>
          </View>
          <IconButton
            icon="bellOutline"
            size={44}
            label={unreadCount > 0 ? `Notifikasi, ${unreadCount} belum dibaca` : 'Notifikasi'}
            badge={unreadCount > 0}
            onPress={() => router.navigate('/notifications')}
          />
        </View>
      }>
      <FeatureGrid features={HomeFeatures} />

      {offline ? <Notice tone="warning" message="Portal offline. Data dari arsip; absen dan unggahan diantrekan." /> : null}
      {dashboard.error ? <Notice tone="error" message={dashboard.error} /> : null}
      {reachable && (dashboard.data?.data.pending_sources?.length ?? 0) > 0 ? <Notice message="Sebagian data masih diperbarui." /> : null}

      <Section
        label={dayLabel}
        accessory={todayClasses.length > 0 ? <Chip label={`${attendedCount(todayClasses)}/${todayClasses.length} absen`} icon="check" /> : null}>
        <Panel padded={false}>
          {dashboard.isLoading ? <SkeletonRows /> : null}

          {!dashboard.isLoading && todayClasses.length === 0 ? (
            <EmptyState
              icon="calendarEmpty"
              title="Tidak ada kelas hari ini"
              action={{ label: 'Jadwal minggu ini', icon: 'bookOutline', onPress: () => router.navigate('/classes') }}
            />
          ) : null}

          {todayClasses.map((todayClass, index) => (
            <View key={`${todayClass.course_code ?? todayClass.course_name ?? 'class'}-${index}`}>
              {index > 0 ? <Divider /> : null}
              <TodayClassRow todayClass={todayClass} />
            </View>
          ))}
        </Panel>
      </Section>

      {items.length > 0 ? (
        <Section label="Perlu tindakan" accessory={<Chip label={`${items.length}`} />}>
          <Panel padded={false}>
            <ActionItemRows items={items} />
          </Panel>
        </Section>
      ) : null}
    </Screen>
  );
}

function toneColor(theme: Palette, tone: Tone): string {
  switch (tone) {
    case 'positive':
      return theme.success;
    case 'attention':
      return theme.warning;
    case 'muted':
      return theme.textTertiary;
    default:
      return theme.textSecondary;
  }
}

/**
 * One of today's classes on two lines: its name, then its attendance in
 * color with the room and lecturer. A class whose attendance is open ends
 * with an Absen button; the one in progress has its time in gold.
 */
function TodayClassRow({ todayClass }: { todayClass: TodayClass }) {
  const theme = useTheme();
  const router = useRouter();
  const route = todayClassRoute(todayClass);
  const attendance = attendanceSummary(todayClass);
  const actionable = todayClass.attendance?.actionable === true;
  const active = todayClass.active_now === true;
  const start = shortTime(todayClass.schedule?.start_time);
  const end = shortTime(todayClass.schedule?.end_time);
  const courseColors = useCourseColors();
  const courseColor = courseColors[courseColorIndex(todayClass.course_code ?? todayClass.course_name, courseColors.length)];
  const meta = joinMeta(todayClass.room, todayClass.lecturer_name);

  return (
    <Pressable
      accessibilityRole={route ? 'button' : undefined}
      accessibilityHint={active ? 'Sedang berlangsung' : undefined}
      disabled={route === null}
      onPress={() => (route ? router.push(route) : undefined)}
      style={({ pressed }) => [styles.classRow, pressed && { backgroundColor: theme.section }]}>
      <View style={styles.timeColumn}>
        <ThemedText type="smallBold" style={{ color: active ? theme.accent : start ? theme.text : theme.textQuaternary }} numberOfLines={1}>
          {start ?? '–'}
        </ThemedText>
        {end ? (
          <ThemedText type="caption" themeColor="textTertiary" numberOfLines={1}>
            {end}
          </ThemedText>
        ) : null}
      </View>
      <View style={[styles.timeBar, { backgroundColor: active ? theme.accent : courseColor.ink }]} />
      <View style={styles.classText}>
        <ThemedText type="bodyMedium" numberOfLines={1}>
          {todayClass.course_name ?? 'Mata kuliah'}
        </ThemedText>
        <ThemedText type="small" themeColor="textTertiary" numberOfLines={1}>
          {actionable ? null : <ThemedText type="small" style={{ color: toneColor(theme, attendance.tone) }}>{attendance.label}</ThemedText>}
          {!actionable && meta ? ' · ' : null}
          {meta}
        </ThemedText>
      </View>
      {actionable ? <Button size="small" icon="qr" label="Absen" onPress={() => router.push('/attendance')} /> : null}
    </Pressable>
  );
}

const styles = StyleSheet.create({
  header: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.three - Spacing.one,
    paddingVertical: Spacing.one,
  },
  headerText: {
    flex: 1,
  },
  classRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.three - Spacing.one,
    paddingHorizontal: Spacing.three,
    paddingVertical: Spacing.two + Spacing.one,
  },
  timeColumn: {
    width: 44,
    gap: Spacing.half,
  },
  timeBar: {
    width: 3,
    alignSelf: 'stretch',
    borderRadius: 2,
  },
  classText: {
    flex: 1,
    gap: Spacing.half,
  },
});
