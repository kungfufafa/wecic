import { useRouter } from 'expo-router';
import { useEffect } from 'react';
import { Pressable, StyleSheet, View } from 'react-native';

import type { ActionItemsResponse, DashboardResponse, TodayClass } from '@/api/types';
import { ActionItemRows } from '@/components/action-items';
import { useSession } from '@/auth/session-provider';
import { Avatar } from '@/components/avatar';
import { Chip } from '@/components/chip';
import { EmptyState } from '@/components/empty-state';
import { FeatureGrid } from '@/components/feature-grid';
import { Icon, type IconName } from '@/components/icon';
import { IconButton } from '@/components/icon-button';
import { MetaRow } from '@/components/meta-row';
import { Notice } from '@/components/notice';
import { Screen } from '@/components/screen';
import { Divider, Panel, Section } from '@/components/section';
import { SkeletonRows } from '@/components/skeleton';
import { StatusPill } from '@/components/status-pill';
import { ThemedText } from '@/components/themed-text';
import { BrandGradient, Colors, Radius, Spacing } from '@/constants/theme';
import { useApiResource } from '@/hooks/use-api-resource';
import { useReachable } from '@/hooks/use-connectivity';
import { setKnownUser, useKnownUser } from '@/hooks/use-known-user';
import { useCourseColors, useTheme } from '@/hooks/use-theme';
import { useUnreadCountValue } from '@/hooks/use-unread-count';
import { featuresByKey, HOME_FEATURE_KEYS } from '@/lib/features';
import { todayClassRoute } from '@/lib/today';
import {
  attendanceSummary,
  attendedCount,
  courseColorIndex,
  courseInitials,
  firstName,
  formatShortDate,
  greeting,
  initials,
  nextClass,
  shortTime,
} from '@/lib/format';

export default function HomeScreen() {
  const theme = useTheme();
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
  const upNext = nextClass(todayClasses);
  const actionItems = useApiResource<ActionItemsResponse>('action-items');
  const items = actionItems.data?.data.items ?? [];
  const attendanceOpen = todayClasses.filter((todayClass) => todayClass.attendance?.actionable === true).length;

  function refreshAll() {
    void dashboard.refresh();
    void actionItems.refresh();
  }
  const dayLabel = summary?.day ? `${summary.day}${summary.date ? `, ${formatShortDate(summary.date)}` : ''}` : null;

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
          <Avatar initials={initials(user)} size={48} />
          <View style={styles.headerText}>
            <ThemedText type="small" themeColor="textTertiary">
              {greeting()}
            </ThemedText>
            <ThemedText type="subtitle" numberOfLines={1}>
              {firstName(user) ?? 'Mahasiswa'}
            </ThemedText>
          </View>
          <IconButton
            icon="bellOutline"
            label={unreadCount > 0 ? `Notifikasi, ${unreadCount} belum dibaca` : 'Notifikasi'}
            badge={unreadCount > 0}
            onPress={() => router.navigate('/notifications')}
          />
        </View>
      }>
      <Section label="Hari ini" accessory={dayLabel ? <Chip label={dayLabel} icon="calendar" /> : null}>
        <Panel style={styles.split}>
          <Stat icon="calendar" color={theme.brand} label="Kelas" value={dashboard.isLoading ? '–' : String(todayClasses.length)} />
          <View style={[styles.splitDivider, { backgroundColor: theme.hairline }]} />
          <Stat
            icon="check"
            color={theme.success}
            label="Sudah absen"
            value={dashboard.isLoading ? '–' : `${attendedCount(todayClasses)}/${todayClasses.length}`}
          />
        </Panel>
      </Section>

      <View style={styles.servicesHeader}>
        <ThemedText type="label">Layanan</ThemedText>
        <Chip label="Semua layanan" icon="grid" onPress={() => router.push('/services')} />
      </View>
      <FeatureGrid
        features={featuresByKey(HOME_FEATURE_KEYS)}
        badges={{ attendance: attendanceOpen > 0 ? String(attendanceOpen) : null }}
      />

      {offline ? (
        <Notice
          tone="warning"
          message="Portal kampus sedang tidak bisa diakses, jadi data diambil dari arsip WeCIC. Absensi dan unggahan masuk antrean."
        />
      ) : null}
      {dashboard.error ? <Notice tone="error" message={dashboard.error} /> : null}
      {reachable && (dashboard.data?.data.pending_sources?.length ?? 0) > 0 ? (
        <Notice message="Sebagian data sedang diperbarui dari portal. Tarik ke bawah untuk memuat ulang." />
      ) : null}

      {items.length > 0 ? (
        <Section label="Perlu tindakan" accessory={<Chip label={`${items.length} hal`} />}>
          <Panel padded={false}>
            <ActionItemRows items={items} />
          </Panel>
        </Section>
      ) : null}

      {upNext ? <UpNextCard todayClass={upNext.todayClass} inProgress={upNext.inProgress} /> : null}

      <Section
        label="Jadwal hari ini"
        accessory={<Chip label="Semua kelas" onPress={() => router.navigate('/classes')} />}>
        <Panel padded={false}>
          {dashboard.isLoading ? <SkeletonRows /> : null}

          {!dashboard.isLoading && todayClasses.length === 0 ? (
            <EmptyState
              icon="calendarEmpty"
              title="Tidak ada kelas hari ini"
              description="Nikmati harimu. Jadwal minggu ini ada di tab Kelas."
              action={{ label: 'Lihat jadwal minggu ini', icon: 'bookOutline', onPress: () => router.navigate('/classes') }}
            />
          ) : null}

          {todayClasses.map((todayClass, index) => (
            <View key={`${todayClass.course_code ?? todayClass.course_name ?? 'class'}-${index}`}>
              {index > 0 ? <Divider inset={Spacing.three} /> : null}
              <TodayClassRow todayClass={todayClass} />
            </View>
          ))}
        </Panel>
      </Section>
    </Screen>
  );
}

function Stat({ icon, color, label, value }: { icon: IconName; color: string; label: string; value: string }) {
  return (
    <View style={styles.stat} accessible accessibilityLabel={`${label}: ${value}`}>
      <Icon name={icon} size={22} color={color} />
      <View style={styles.statText}>
        <ThemedText type="small" themeColor="textTertiary">
          {label}
        </ThemedText>
        <ThemedText type="heading">{value}</ThemedText>
      </View>
    </View>
  );
}

/** The class in progress, or the next one, on the royal-blue card. */
function UpNextCard({ todayClass, inProgress }: { todayClass: TodayClass; inProgress: boolean }) {
  const theme = useTheme();
  const router = useRouter();
  const route = todayClassRoute(todayClass);
  const actionable = todayClass.attendance?.actionable === true;
  const attendance = attendanceSummary(todayClass);
  const start = shortTime(todayClass.schedule?.start_time);
  const end = shortTime(todayClass.schedule?.end_time);
  const onCard = 'rgba(255, 255, 255, 0.78)';

  return (
    <Pressable
      accessibilityRole={route ? 'button' : undefined}
      disabled={route === null}
      onPress={() => (route ? router.push(route) : undefined)}
      style={({ pressed }) => [styles.upNext, { backgroundColor: theme.brandStrong, backgroundImage: BrandGradient }, pressed && styles.pressed]}>
      <View style={styles.upNextTop}>
        <View style={styles.upNextTile}>
          <ThemedText type="smallBold" style={{ color: theme.onColor }}>
            {courseInitials(todayClass.course_name, todayClass.course_code)}
          </ThemedText>
        </View>
        <ThemedText type="small" style={[styles.flex, { color: onCard }]}>
          {inProgress ? 'Sedang berlangsung' : 'Kelas berikutnya'}
        </ThemedText>
        {start ? (
          <ThemedText type="subtitle" style={{ color: theme.onColor }}>
            {end ? `${start}–${end}` : start}
          </ThemedText>
        ) : null}
      </View>
      <View style={styles.upNextBody}>
        <ThemedText type="heading" style={{ color: theme.onColor }} numberOfLines={2}>
          {todayClass.course_name ?? 'Mata kuliah'}
        </ThemedText>
        <View style={styles.upNextMeta}>
          <MetaRow icon="location" text={todayClass.room} color={onCard} />
          <MetaRow icon="person" text={todayClass.lecturer_name} color={onCard} />
        </View>
        <View style={styles.upNextFooter}>
          <StatusPill label={attendance.label} tone={inProgress ? 'attention' : attendance.tone} onBrand />
          {actionable ? (
            <Pressable
              accessibilityRole="button"
              onPress={() => router.push('/attendance')}
              style={({ pressed }) => [styles.absenButton, { backgroundColor: pressed ? Colors.dark.primaryPressed : Colors.dark.primary }]}>
              <Icon name="qr" size={16} color={Colors.dark.onPrimary} />
              <ThemedText type="smallBold" style={{ color: Colors.dark.onPrimary }}>
                Absen
              </ThemedText>
            </Pressable>
          ) : null}
        </View>
      </View>
    </Pressable>
  );
}

function TodayClassRow({ todayClass }: { todayClass: TodayClass }) {
  const theme = useTheme();
  const router = useRouter();
  const route = todayClassRoute(todayClass);
  const attendance = attendanceSummary(todayClass);
  const active = todayClass.active_now === true;
  const start = shortTime(todayClass.schedule?.start_time);
  const end = shortTime(todayClass.schedule?.end_time);
  const courseColors = useCourseColors();
  const courseColor = courseColors[courseColorIndex(todayClass.course_code ?? todayClass.course_name, courseColors.length)];

  return (
    <Pressable
      accessibilityRole={route ? 'button' : undefined}
      disabled={route === null}
      onPress={() => (route ? router.push(route) : undefined)}
      style={({ pressed }) => [styles.classRow, pressed && { backgroundColor: theme.section }]}>
      <View style={styles.timeColumn}>
        <ThemedText type="smallBold" themeColor={start ? 'text' : 'textQuaternary'} numberOfLines={1}>
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
        <ThemedText type="bodyMedium" numberOfLines={2}>
          {todayClass.course_name ?? 'Mata kuliah'}
        </ThemedText>
        <MetaRow icon="location" text={todayClass.room} />
        <MetaRow icon="person" text={todayClass.lecturer_name} />
        <View style={styles.pills}>
          {active ? <StatusPill label="Sedang berlangsung" tone="attention" /> : null}
          <StatusPill label={attendance.label} tone={attendance.tone} />
        </View>
      </View>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  flex: {
    flex: 1,
  },
  header: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.three - Spacing.one,
    paddingVertical: Spacing.two,
  },
  headerText: {
    flex: 1,
    gap: Spacing.half,
  },
  split: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingVertical: Spacing.four - Spacing.one,
  },
  splitDivider: {
    width: StyleSheet.hairlineWidth,
    alignSelf: 'stretch',
  },
  stat: {
    flex: 1,
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.three - Spacing.one,
    paddingHorizontal: Spacing.three,
  },
  statText: {
    gap: Spacing.half,
  },
  servicesHeader: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingHorizontal: Spacing.one,
    paddingTop: Spacing.one,
  },
  pressed: {
    opacity: 0.9,
  },
  upNextFooter: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    gap: Spacing.two,
  },
  absenButton: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.one + Spacing.half,
    paddingHorizontal: Spacing.three,
    paddingVertical: Spacing.two,
    borderRadius: Radius.pill,
  },
  upNext: {
    minHeight: 200,
    justifyContent: 'space-between',
    gap: Spacing.four,
    padding: Spacing.four - Spacing.one,
    borderRadius: Radius.section - 2,
    borderWidth: StyleSheet.hairlineWidth,
    borderColor: 'rgba(255, 255, 255, 0.14)',
    overflow: 'hidden',
  },
  upNextTop: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.three - Spacing.one,
  },
  upNextTile: {
    width: 44,
    height: 44,
    borderRadius: Radius.medium,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: 'rgba(255, 255, 255, 0.18)',
  },
  upNextBody: {
    gap: Spacing.two + Spacing.one,
  },
  upNextMeta: {
    gap: Spacing.one + Spacing.half,
  },
  classRow: {
    flexDirection: 'row',
    gap: Spacing.three - Spacing.one,
    padding: Spacing.three,
  },
  timeColumn: {
    width: 46,
    gap: Spacing.half,
  },
  timeBar: {
    width: 3,
    borderRadius: 2,
  },
  classText: {
    flex: 1,
    gap: Spacing.one + Spacing.half,
  },
  pills: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: Spacing.two - Spacing.half,
    marginTop: Spacing.one,
  },
});
