import { useRouter } from 'expo-router';
import { StyleSheet, View } from 'react-native';

import type { TodayAttendanceClass, TodayAttendanceResponse } from '@/api/types/actions';
import { AttendanceForm } from '@/components/actions/attendance-form';
import { Chip } from '@/components/chip';
import { CourseTile } from '@/components/course-tile';
import { EmptyState } from '@/components/empty-state';
import { MetaRow } from '@/components/meta-row';
import { Notice } from '@/components/notice';
import { Screen } from '@/components/screen';
import { Panel, Section } from '@/components/section';
import { SkeletonRows } from '@/components/skeleton';
import { StatusPill } from '@/components/status-pill';
import { ThemedText } from '@/components/themed-text';
import { Spacing } from '@/constants/theme';
import { useApiResource } from '@/hooks/use-api-resource';
import type { Tone } from '@/lib/format';
import { timeRange } from '@/lib/format';

/** Today's classes, each with its attendance and the code box while it can still be sent. */
export default function AttendanceScreen() {
  const today = useApiResource<TodayAttendanceResponse>('attendance/today');
  const classes = today.data?.data.classes ?? [];
  const dayLabel = today.data?.data.day ? `${today.data.data.day}${today.data.data.current_time ? ` · ${today.data.data.current_time}` : ''}` : null;

  return (
    <Screen
      back
      title="Absensi hari ini"
      subtitle="Ketik atau tempel kode absensi dari dosen. Teks dari QR juga bisa ditempel."
      accessory={dayLabel ? <Chip icon="clock" label={dayLabel} /> : null}
      refreshing={today.isRefreshing}
      onRefresh={today.refresh}>
      {today.error ? <Notice tone="error" message={today.error} /> : null}

      {today.isLoading ? (
        <Section label="Memuat kelas hari ini">
          <Panel padded={false}>
            <SkeletonRows />
          </Panel>
        </Section>
      ) : null}

      {!today.isLoading && classes.length === 0 && !today.error ? (
        <Section>
          <Panel>
            <EmptyState icon="calendarEmpty" title="Tidak ada kelas hari ini" description="Absensi muncul di sini pada hari ada jadwal kuliah." />
          </Panel>
        </Section>
      ) : null}

      {classes.map((todayClass, index) => (
        <AttendanceClass key={`${todayClass.meeting_id ?? todayClass.course_code ?? 'class'}-${index}`} todayClass={todayClass} onMarked={today.reload} />
      ))}
    </Screen>
  );
}

function AttendanceClass({ todayClass, onMarked }: { todayClass: TodayAttendanceClass; onMarked: () => void }) {
  const router = useRouter();
  const status = todayClass.attendance_status;
  const marked = status?.already_marked === true;
  const closed = status?.window === 'closed';
  const tone: Tone = marked ? 'positive' : closed ? 'muted' : todayClass.active_now ? 'attention' : 'neutral';
  const label = marked
    ? [status?.label ?? 'Sudah absen', status?.time].filter(Boolean).join(' · ')
    : (status?.label ?? (todayClass.active_now ? 'Sedang berlangsung' : 'Belum absen'));
  const payload = todayClass.attendance_payload;
  const canSend = !marked && !closed && payload !== null && payload !== undefined && Boolean(payload.modulesid);
  const courseId = payload?.courseid ?? todayClass.assignment_payload?.courses_id ?? null;

  return (
    <Section
      label={todayClass.meeting_number ? `Pertemuan ${todayClass.meeting_number}` : 'Kelas'}
      accessory={
        todayClass.meeting_id && courseId ? (
          <Chip
            label="Buka pertemuan"
            onPress={() => router.push({ pathname: '/meeting/[id]', params: { id: String(todayClass.meeting_id), courses_id: String(courseId) } })}
          />
        ) : null
      }>
      <Panel style={styles.panel}>
        <View style={styles.header}>
          <CourseTile name={todayClass.course_name} code={todayClass.course_code} />
          <View style={styles.headerText}>
            <ThemedText type="bodyMedium" numberOfLines={2}>
              {todayClass.course_name ?? 'Mata kuliah'}
            </ThemedText>
            <MetaRow icon="clock" text={timeRange(todayClass.schedule)} />
            <MetaRow icon="person" text={todayClass.lecturer_name} />
          </View>
        </View>
        {todayClass.today_material?.title ? <MetaRow icon="document" text={todayClass.today_material.title} /> : null}
        <StatusPill label={label} tone={tone} />
        {canSend && todayClass.attendance_target_reliable === false ? (
          <Notice tone="warning" message="Materi pertemuan hari ini mungkin belum diunggah dosen. Pastikan kode untuk kelas ini." />
        ) : null}
        {canSend && payload ? <AttendanceForm target={payload} onMarked={onMarked} /> : null}
      </Panel>
    </Section>
  );
}

const styles = StyleSheet.create({
  panel: {
    gap: Spacing.three - Spacing.one,
  },
  header: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: Spacing.three - Spacing.one,
  },
  headerText: {
    flex: 1,
    gap: Spacing.one + Spacing.half,
  },
});
