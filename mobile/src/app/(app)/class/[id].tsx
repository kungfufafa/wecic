import { useLocalSearchParams, useRouter } from 'expo-router';
import { Fragment } from 'react';
import { Pressable, StyleSheet, View } from 'react-native';

import type { ClassDetailResponse, CourseMaterial } from '@/api/types/classes';
import { FileRow } from '@/components/classes/file-row';
import { MeetingGrid } from '@/components/classes/meeting-grid';
import { ContactButtons } from '@/components/contact-buttons';
import { CourseTile } from '@/components/course-tile';
import { EmptyState } from '@/components/empty-state';
import { Icon } from '@/components/icon';
import { InfoRows } from '@/components/info-rows';
import { Notice } from '@/components/notice';
import { ProgressBar } from '@/components/progress-bar';
import { Screen } from '@/components/screen';
import { Divider, Panel, Section } from '@/components/section';
import { SkeletonRows } from '@/components/skeleton';
import { StatRow } from '@/components/stat-row';
import { ThemedText } from '@/components/themed-text';
import { Radius, Spacing } from '@/constants/theme';
import { useApiResource } from '@/hooks/use-api-resource';
import { usePortalFile } from '@/hooks/use-portal-file';
import { useTheme } from '@/hooks/use-theme';
import { meetingTargetFromHref, queryString, scoreValue } from '@/lib/classes';

type Params = { id: string; dosen_id?: string; course_id?: string };

export default function ClassDetailScreen() {
  const theme = useTheme();
  const params = useLocalSearchParams<Params>();
  const query = queryString({ dosen_id: params.dosen_id, course_id: params.course_id });
  const detail = useApiResource<ClassDetailResponse>(params.id ? `class/${encodeURIComponent(params.id)}${query}` : null);
  const file = usePortalFile();
  const info = detail.data?.class_detail;
  const course = info?.course_info;
  const lecturer = info?.lecturer_info;
  const summary = info?.attendance_info?.summary;
  const meetings = info?.attendance_info?.meetings ?? [];
  const materials = [...(info?.course_materials ?? [])].sort((a, b) => (b.meeting_number ?? 0) - (a.meeting_number ?? 0));
  const submissions = info?.assignment_history?.assignments ?? [];
  const rpsLink = course?.rps_link?.trim() || null;
  const rate = summary?.attendance_rate ?? null;
  const notFound = !detail.isLoading && info?.found === false;

  return (
    <Screen
      back
      title={course?.name ?? 'Kelas'}
      subtitle={[course?.code, course?.class_name, course?.credits ? `${course.credits} SKS` : null].filter(Boolean).join(' · ') || null}
      refreshing={detail.isRefreshing}
      dataSavedAt={detail.savedAt}
      onRefresh={detail.refresh}>
      {detail.error ? <Notice tone="error" message={detail.error} /> : null}

      {detail.isLoading ? (
        <Section label="Memuat kelas">
          <Panel padded={false}>
            <SkeletonRows count={3} />
          </Panel>
        </Section>
      ) : null}

      {notFound ? (
        <Section>
          <Panel>
            <EmptyState icon="book" title="Kelas tidak ditemukan" description="Buka lagi dari daftar kelas." />
          </Panel>
        </Section>
      ) : null}

      {info && !notFound ? (
        <>
          <Section label="Dosen pengampu">
            <Panel style={styles.lecturer}>
              <View style={styles.lecturerHead}>
                <CourseTile name={course?.name} code={course?.code} size={36} />
                <View style={styles.flex}>
                  <ThemedText type="bodyMedium" numberOfLines={2}>
                    {lecturer?.name ?? 'Dosen'}
                  </ThemedText>
                  {lecturer?.email || lecturer?.contact ? (
                    <ThemedText type="small" themeColor="textTertiary" selectable numberOfLines={1}>
                      {[lecturer?.contact, lecturer?.email].filter(Boolean).join(' · ')}
                    </ThemedText>
                  ) : null}
                </View>
              </View>
              <ContactButtons contact={{ phone: lecturer?.contact, whatsapp: lecturer?.contact, email: lecturer?.email }} />
            </Panel>
          </Section>

          {summary ? (
            <Section label="Kehadiran" accessory={rate !== null ? <ThemedText type="smallBold" style={styles.accessoryText}>{`${rate}%`}</ThemedText> : null}>
              <Panel padded={false}>
                <StatRow
                  stats={[
                    { label: 'Hadir', value: String((summary.present ?? 0) + (summary.late ?? 0)), color: theme.success },
                    { label: 'Tidak hadir', value: String(summary.absent ?? 0), color: (summary.absent ?? 0) > 0 ? theme.danger : undefined },
                    { label: 'Izin/sakit', value: String((summary.permit ?? 0) + (summary.sick ?? 0)) },
                    { label: 'Tersisa', value: String(summary.upcoming ?? 0) },
                  ]}
                />
                {rate !== null ? (
                  <View style={styles.progress}>
                    <ProgressBar percent={rate} color={rate >= 75 ? theme.success : theme.warning} />
                    <ThemedText type="caption" themeColor="textTertiary">
                      {`${summary.held_meetings ?? 0}/${summary.total_meetings ?? meetings.length} pertemuan berlangsung`}
                    </ThemedText>
                  </View>
                ) : null}
                {meetings.length > 0 ? (
                  <>
                    <Divider />
                    <View style={styles.grid}>
                      <MeetingGrid meetings={meetings} />
                    </View>
                  </>
                ) : null}
              </Panel>
            </Section>
          ) : null}

          <Section label="Materi pertemuan" accessory={materials.length > 0 ? <ThemedText type="small" themeColor="textTertiary" style={styles.accessoryText}>{`${materials.length} materi`}</ThemedText> : null}>
            <Panel padded={false}>
              {rpsLink ? (
                <>
                  <FileRow
                    icon="bookText"
                    label="Rencana Pembelajaran Semester"
                    busy={file.busyHref === rpsLink}
                    onPress={() => void file.open(rpsLink, `RPS ${course?.code ?? ''}`.trim())}
                  />
                  {materials.length > 0 ? <Divider /> : null}
                </>
              ) : null}
              {materials.length === 0 && !rpsLink ? (
                <EmptyState icon="bookOutline" title="Belum ada materi" />
              ) : null}
              {materials.map((material, index) => (
                <Fragment key={`${material.meeting_number ?? 'm'}-${index}`}>
                  {index > 0 ? <Divider /> : null}
                  <MaterialRow material={material} />
                </Fragment>
              ))}
            </Panel>
          </Section>

          <Section
            label="Riwayat tugas"
            accessory={
              info.assignment_history?.average_score && scoreValue(info.assignment_history.average_score) !== null ? (
                <ThemedText type="small" themeColor="textTertiary" style={styles.accessoryText}>{`Rata-rata ${info.assignment_history.average_score}`}</ThemedText>
              ) : null
            }>
            <Panel padded={false}>
              {submissions.length === 0 ? (
                <EmptyState icon="task" title="Belum ada tugas terkumpul" />
              ) : null}
              {submissions.map((submission, index) => {
                const href = submission.file_info?.href?.trim() || null;
                const detailText = [submission.date, scoreValue(submission.score) !== null ? `Nilai ${submission.score}` : null].filter(Boolean).join(' · ');

                return (
                  <Fragment key={`${submission.no ?? index}`}>
                    {index > 0 ? <Divider /> : null}
                    {href ? (
                      <FileRow
                        icon="upload"
                        label={`Tugas pertemuan ${submission.meeting ?? '-'}`}
                        detail={detailText}
                        busy={file.busyHref === href}
                        onPress={() => void file.open(href, `Tugas pertemuan ${submission.meeting ?? ''}`.trim())}
                      />
                    ) : (
                      <InfoRows rows={[{ label: `Pertemuan ${submission.meeting ?? '-'}`, value: detailText }]} />
                    )}
                  </Fragment>
                );
              })}
            </Panel>
          </Section>
        </>
      ) : null}
    </Screen>
  );
}

function MaterialRow({ material }: { material: CourseMaterial }) {
  const theme = useTheme();
  const router = useRouter();
  const target = meetingTargetFromHref(material.join_link?.href);
  const when = [material.date, material.time].filter(Boolean).join(' · ');

  const content = (
    <>
      <View style={[styles.number, { backgroundColor: theme.elevated }]}>
        <ThemedText type="smallBold">{material.meeting_number ?? '–'}</ThemedText>
      </View>
      <View style={styles.flex}>
        <ThemedText type="bodyMedium" numberOfLines={2}>
          {material.title ?? `Pertemuan ${material.meeting_number ?? ''}`}
        </ThemedText>
        {when ? (
          <ThemedText type="small" themeColor="textTertiary" numberOfLines={1}>
            {when}
          </ThemedText>
        ) : null}
        {material.description ? (
          <ThemedText type="small" themeColor="textSecondary" numberOfLines={2}>
            {material.description.trim()}
          </ThemedText>
        ) : null}
      </View>
      {target ? <Icon name="chevronRight" size={14} color={theme.textQuaternary} /> : null}
    </>
  );

  if (!target) {
    return <View style={styles.material}>{content}</View>;
  }

  return (
    <Pressable
      accessibilityRole="button"
      accessibilityHint="Buka pertemuan"
      onPress={() =>
        router.push({ pathname: '/meeting/[id]', params: target.courses_id ? { id: target.id, courses_id: target.courses_id } : { id: target.id } })
      }
      style={({ pressed }) => [styles.material, pressed && { backgroundColor: theme.section }]}>
      {content}
    </Pressable>
  );
}

const styles = StyleSheet.create({
  flex: {
    flex: 1,
    gap: Spacing.half,
  },
  lecturer: {
    gap: Spacing.three - Spacing.one,
    padding: Spacing.three - Spacing.one,
  },
  lecturerHead: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.three - Spacing.one,
  },
  accessoryText: {
    paddingRight: Spacing.two,
  },
  progress: {
    gap: Spacing.two,
    paddingHorizontal: Spacing.three,
    paddingBottom: Spacing.three - Spacing.one,
  },
  grid: {
    padding: Spacing.three - Spacing.one,
  },
  material: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: Spacing.three - Spacing.one,
    paddingHorizontal: Spacing.three,
    paddingVertical: Spacing.three - Spacing.one,
  },
  number: {
    width: 32,
    height: 32,
    borderRadius: Radius.small,
    alignItems: 'center',
    justifyContent: 'center',
  },
});
