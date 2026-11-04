import { useRouter } from 'expo-router';
import { useState } from 'react';
import { StyleSheet, View } from 'react-native';

import type { TodayAttendanceClass, TodayAttendanceResponse } from '@/api/types/actions';
import { AttendanceForm } from '@/components/actions/attendance-form';
import { Chip } from '@/components/chip';
import { CourseTile } from '@/components/course-tile';
import { EmptyState } from '@/components/empty-state';
import { joinMeta, MetaRow } from '@/components/meta-row';
import { Notice } from '@/components/notice';
import { Screen } from '@/components/screen';
import { Panel, Section } from '@/components/section';
import { SkeletonRows } from '@/components/skeleton';
import { StatusPill } from '@/components/status-pill';
import { ThemedText } from '@/components/themed-text';
import { Spacing } from '@/constants/theme';
import { useApiResource } from '@/hooks/use-api-resource';
import { todayClassTakesAttendanceCode } from '@/lib/attendance';
import type { Tone } from '@/lib/format';
import { timeRange } from '@/lib/format';

/** Today's classes, each with its attendance and the code box until the portal records the student. */
export default function AttendanceScreen() {
  const today = useApiResource<TodayAttendanceResponse>('attendance/today');
  const classes = today.data?.data.classes ?? [];
  const dayLabel = today.data?.data.day ? `${today.data.data.day}${today.data.data.current_time ? ` · ${today.data.data.current_time}` : ''}` : null;

  return (
    <Screen
      title="Absen"
      accessory={dayLabel ? <Chip icon="clock" label={dayLabel} /> : null}
      refreshing={today.isRefreshing}
      dataSavedAt={today.savedAt}
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
            <EmptyState icon="calendarEmpty" title="Tidak ada kelas hari ini" />
          </Panel>
        </Section>
      ) : null}

      {classes.map((todayClass, index) => (
        <AttendanceClass key={`${todayClass.meeting_id ?? todayClass.course_code ?? 'class'}-${index}`} todayClass={todayClass} />
      ))}
    </Screen>
  );
}

/**
 * One class of today. Once the code is accepted, the card shows the status
 * the server sent back instead of reloading every class from the portal,
 * which kept a second code waiting behind that crawl.
 */
function AttendanceClass({ todayClass }: { todayClass: TodayAttendanceClass }) {
  const router = useRouter();
  const [recorded, setRecorded] = useState<TodayAttendanceClass['attendance_status'] | null>(null);
  const status = recorded ?? todayClass.attendance_status;
  const marked = status?.already_marked === true;
  const closed = status?.window === 'closed';
  const tone: Tone = marked ? 'positive' : closed ? 'muted' : todayClass.active_now ? 'attention' : 'neutral';
  const label = marked
    ? [status?.label ?? 'Sudah absen', status?.time].filter(Boolean).join(' · ')
    : (status?.label ?? (todayClass.active_now ? 'Sedang berlangsung' : 'Belum absen'));
  const payload = todayClass.attendance_payload;
  const canSend = todayClassTakesAttendanceCode(status, payload);
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
          <CourseTile name={todayClass.course_name} code={todayClass.course_code} size={36} />
          <View style={styles.headerText}>
            <ThemedText type="bodyMedium" numberOfLines={2}>
              {todayClass.course_name ?? 'Mata kuliah'}
            </ThemedText>
            <MetaRow icon="clock" text={joinMeta(timeRange(todayClass.schedule), todayClass.lecturer_name)} />
            <MetaRow icon="document" text={todayClass.today_material?.title} />
            <View style={styles.pill}>
              <StatusPill label={label} tone={tone} />
            </View>
          </View>
        </View>
        {canSend && todayClass.attendance_target_reliable === false ? (
          <Notice tone="warning" message="Materi hari ini mungkin belum diunggah. Pastikan kodenya untuk kelas ini." />
        ) : null}
        {canSend && payload ? <AttendanceForm target={payload} onMarked={setRecorded} /> : null}
      </Panel>
    </Section>
  );
}

const styles = StyleSheet.create({
  panel: {
    gap: Spacing.three - Spacing.one,
    padding: Spacing.three - Spacing.one,
  },
  header: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: Spacing.three - Spacing.one,
  },
  headerText: {
    flex: 1,
    gap: Spacing.one,
  },
  pill: {
    marginTop: Spacing.half,
  },
});
