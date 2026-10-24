import { ActivityIndicator, StyleSheet, View } from 'react-native';

import type { DashboardResponse, TodayClass } from '@/api/types';
import { useSession } from '@/auth/session-provider';
import { Card } from '@/components/card';
import { Notice } from '@/components/notice';
import { Screen } from '@/components/screen';
import { StatusPill } from '@/components/status-pill';
import { ThemedText } from '@/components/themed-text';
import { Spacing } from '@/constants/theme';
import { useApiResource } from '@/hooks/use-api-resource';
import { attendanceSummary, firstName, formatDate, studentNumber, timeRange } from '@/lib/format';

export default function HomeScreen() {
  const { state } = useSession();
  const dashboard = useApiResource<DashboardResponse>('dashboard');
  const sessionUser = state.status === 'signedIn' ? state.user : null;
  const user = dashboard.data?.data.user_info ?? sessionUser;
  const summary = dashboard.data?.data.classes_summary;
  const todayClasses = summary?.today_classes ?? [];
  const name = firstName(user);

  return (
    <Screen
      title={name ? `Halo, ${name}` : 'Beranda'}
      subtitle={studentNumber(user)}
      refreshing={dashboard.isRefreshing}
      onRefresh={dashboard.refresh}>
      {state.status === 'signedIn' && state.offline ? (
        <Notice
          tone="warning"
          message="Mode offline: portal kampus sedang tidak bisa diakses, jadi data diambil dari arsip MyCIC. Absensi dan unggahan masuk antrean."
        />
      ) : null}
      {dashboard.error ? <Notice tone="error" message={dashboard.error} /> : null}
      {(dashboard.data?.data.pending_sources?.length ?? 0) > 0 ? (
        <Notice message="Sebagian data sedang diperbarui dari portal. Tarik ke bawah untuk memuat ulang." />
      ) : null}

      <View style={styles.section}>
        <ThemedText type="subtitle">Jadwal hari ini</ThemedText>
        {summary?.day ? (
          <ThemedText type="small" themeColor="textSecondary">
            {summary.day}
            {summary.date ? `, ${formatDate(summary.date)}` : ''}
          </ThemedText>
        ) : null}
      </View>

      {dashboard.isLoading ? <ActivityIndicator /> : null}

      {!dashboard.isLoading && todayClasses.length === 0 && !dashboard.error ? (
        <Card>
          <ThemedText type="small" themeColor="textSecondary">
            Tidak ada kelas hari ini.
          </ThemedText>
        </Card>
      ) : null}

      {todayClasses.map((todayClass, index) => (
        <TodayClassCard key={`${todayClass.course_code ?? todayClass.course_name ?? 'class'}-${index}`} todayClass={todayClass} />
      ))}
    </Screen>
  );
}

function TodayClassCard({ todayClass }: { todayClass: TodayClass }) {
  const attendance = attendanceSummary(todayClass);
  const details = [timeRange(todayClass.schedule), todayClass.room].filter(Boolean).join(' · ');

  return (
    <Card>
      <View style={styles.cardHeader}>
        <ThemedText type="smallBold" style={styles.flex}>
          {todayClass.course_name ?? 'Mata kuliah'}
        </ThemedText>
        {todayClass.active_now ? <StatusPill label="Sedang berlangsung" tone="attention" /> : null}
      </View>
      {details ? (
        <ThemedText type="small" themeColor="textSecondary">
          {details}
        </ThemedText>
      ) : null}
      {todayClass.lecturer_name ? (
        <ThemedText type="caption" themeColor="textTertiary">
          {todayClass.lecturer_name}
        </ThemedText>
      ) : null}
      <StatusPill label={attendance.label} tone={attendance.tone} />
    </Card>
  );
}

const styles = StyleSheet.create({
  section: {
    gap: Spacing.half,
  },
  cardHeader: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: Spacing.two,
  },
  flex: {
    flex: 1,
  },
});
