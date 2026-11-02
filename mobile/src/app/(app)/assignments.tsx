import { useRouter } from 'expo-router';
import { Fragment } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, View } from 'react-native';

import type { PendingAssignment, PendingAssignmentsResponse } from '@/api/types/classes';
import { Chip } from '@/components/chip';
import { CourseTile } from '@/components/course-tile';
import { EmptyState } from '@/components/empty-state';
import { Icon } from '@/components/icon';
import { MetaRow } from '@/components/meta-row';
import { Notice } from '@/components/notice';
import { Screen } from '@/components/screen';
import { Divider, Panel, Section } from '@/components/section';
import { SkeletonRows } from '@/components/skeleton';
import { StatusPill } from '@/components/status-pill';
import { ThemedText } from '@/components/themed-text';
import { Spacing } from '@/constants/theme';
import { useApiResource } from '@/hooks/use-api-resource';
import { usePortalFile } from '@/hooks/use-portal-file';
import { useTheme } from '@/hooks/use-theme';
import { daysUntil, deadlineLabel, queryParam, splitByDeadline } from '@/lib/classes';

const TILE_SIZE = 44;
const ROW_INSET = Spacing.three + TILE_SIZE + Spacing.three - Spacing.one;

export default function AssignmentsScreen() {
  const assignments = useApiResource<PendingAssignmentsResponse>('assignment/pending', { timeoutMs: 90_000 });
  const items = assignments.data?.data.assignments ?? [];
  const { open, passed } = splitByDeadline(items);

  return (
    <Screen
      back
      title="Tugas"
      subtitle="Tugas pertemuan yang belum kamu kumpulkan"
      accessory={open.length > 0 ? <Chip label={`${open.length} terbuka`} /> : null}
      refreshing={assignments.isRefreshing}
      onRefresh={assignments.refresh}>
      {assignments.error ? <Notice tone="error" message={assignments.error} /> : null}

      {assignments.isLoading ? (
        <Section label="Memuat tugas">
          <Panel padded={false}>
            <SkeletonRows count={3} />
          </Panel>
        </Section>
      ) : null}

      {!assignments.isLoading && items.length === 0 && !assignments.error ? (
        <Section>
          <Panel>
            <EmptyState icon="check" title="Tidak ada tugas tertunda" description="Semua tugas pertemuan sudah kamu kumpulkan." />
          </Panel>
        </Section>
      ) : null}

      {open.length > 0 ? <AssignmentSection label="Masih bisa dikumpulkan" items={open} /> : null}
      {passed.length > 0 ? <AssignmentSection label="Tenggat sudah lewat" items={passed} /> : null}
    </Screen>
  );
}

function AssignmentSection({ label, items }: { label: string; items: PendingAssignment[] }) {
  return (
    <Section label={label} accessory={<ThemedText type="small" themeColor="textTertiary" style={styles.count}>{`${items.length} tugas`}</ThemedText>}>
      <Panel padded={false}>
        {items.map((assignment, index) => (
          <Fragment key={`${assignment.meeting_id ?? 'tugas'}-${index}`}>
            {index > 0 ? <Divider inset={ROW_INSET} /> : null}
            <AssignmentRow assignment={assignment} />
          </Fragment>
        ))}
      </Panel>
    </Section>
  );
}

function AssignmentRow({ assignment }: { assignment: PendingAssignment }) {
  const theme = useTheme();
  const router = useRouter();
  const file = usePortalFile();
  const deadline = deadlineLabel(daysUntil(assignment.deadline_date));
  const meetingId = assignment.meeting_id ?? assignment.assignment_payload?.id ?? null;
  const coursesId = assignment.assignment_payload?.courses_id ?? queryParam(assignment.meeting_url, 'courses_id');
  const exercise = assignment.assignment_file_url?.trim() || null;

  function openMeeting() {
    if (!meetingId) {
      return;
    }

    router.push({ pathname: '/meeting/[id]', params: coursesId ? { id: meetingId, courses_id: coursesId } : { id: meetingId } });
  }

  return (
    <Pressable
      accessibilityRole="button"
      accessibilityHint="Buka pertemuan untuk mengunggah tugas"
      disabled={!meetingId}
      onPress={openMeeting}
      style={({ pressed }) => [styles.row, pressed && { backgroundColor: theme.section }]}>
      <CourseTile name={assignment.course_name} code={assignment.course_code} size={TILE_SIZE} />
      <View style={styles.text}>
        <View style={styles.title}>
          <ThemedText type="bodyMedium" numberOfLines={2}>
            {assignment.course_name ?? 'Mata kuliah'}
          </ThemedText>
          <ThemedText type="small" themeColor="textTertiary" numberOfLines={1}>
            {[assignment.material_title, assignment.meeting_number ? `Pertemuan ${assignment.meeting_number}` : null].filter(Boolean).join(' · ')}
          </ThemedText>
        </View>
        <MetaRow icon="alarm" text={assignment.deadline} />
        <MetaRow icon="person" text={assignment.lecturer_name} />
        <View style={styles.pills}>
          <StatusPill label={deadline.label} tone={deadline.tone} />
          {exercise ? (
            <Pressable
              accessibilityRole="button"
              accessibilityLabel="Buka soal latihan"
              hitSlop={6}
              onPress={() => void file.open(exercise, `Soal ${assignment.material_title ?? 'tugas'}`)}
              style={[styles.fileChip, { backgroundColor: theme.elevated }]}>
              {file.busyHref === exercise ? (
                <ActivityIndicator size="small" color={theme.textSecondary} />
              ) : (
                <Icon name="download" size={13} color={theme.textSecondary} />
              )}
              <ThemedText type="caption" themeColor="textSecondary">
                Soal
              </ThemedText>
            </Pressable>
          ) : null}
        </View>
      </View>
      {meetingId ? <Icon name="chevronRight" size={14} color={theme.textQuaternary} style={styles.chevron} /> : null}
    </Pressable>
  );
}

const styles = StyleSheet.create({
  count: {
    paddingRight: Spacing.two,
  },
  row: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: Spacing.three - Spacing.one,
    padding: Spacing.three,
  },
  text: {
    flex: 1,
    gap: Spacing.one + Spacing.half,
  },
  title: {
    gap: Spacing.half,
    marginBottom: Spacing.one,
  },
  pills: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    alignItems: 'center',
    gap: Spacing.two - Spacing.half,
    marginTop: Spacing.one,
  },
  fileChip: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.one,
    paddingHorizontal: Spacing.two + Spacing.half,
    paddingVertical: Spacing.one + Spacing.half,
    borderRadius: 999,
  },
  chevron: {
    alignSelf: 'center',
  },
});
