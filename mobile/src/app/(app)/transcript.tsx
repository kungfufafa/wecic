import { Fragment } from 'react';
import { StyleSheet } from 'react-native';

import { LetterBadge } from '@/components/academic/badges';
import { CourseRow } from '@/components/academic/course-row';
import type { GradedCourse, TranscriptResponse } from '@/api/types/academic';
import { Chip } from '@/components/chip';
import { EmptyState } from '@/components/empty-state';
import { Notice } from '@/components/notice';
import { Screen } from '@/components/screen';
import { Divider, Panel, Section } from '@/components/section';
import { SkeletonRows } from '@/components/skeleton';
import { StatRow } from '@/components/stat-row';
import { StatusPill } from '@/components/status-pill';
import { ThemedText } from '@/components/themed-text';
import { Spacing } from '@/constants/theme';
import { useApiResource } from '@/hooks/use-api-resource';
import { formatDecimal, groupBySemester } from '@/lib/academic';

export default function TranscriptScreen() {
  const transcript = useApiResource<TranscriptResponse>('academic/transcript');
  const data = transcript.data?.data;
  const summary = data?.summary;
  const courses = data?.transcript ?? [];
  const groups = groupBySemester(courses);
  const low = data?.low_grades;
  const lowCourses = [...(low?.e_courses ?? []), ...(low?.d_courses ?? []), ...(low?.low_grade_courses ?? [])].filter(
    (course, index, all) => all.findIndex((other) => other.code === course.code) === index,
  );
  const load = data?.current_load;

  return (
    <Screen
      back
      title="Transkrip"
      subtitle={courses.length > 0 ? `${courses.length} mata kuliah sudah dinilai` : null}
      refreshing={transcript.isRefreshing}
      onRefresh={transcript.refresh}>
      {transcript.error ? <Notice tone="error" message={transcript.error} /> : null}
      {data?.is_partial ? <Notice tone="warning" message="Sebagian nilai belum terbaca dari portal, jadi transkrip ini mungkin belum lengkap." /> : null}

      {transcript.isLoading ? (
        <Section label="Memuat transkrip">
          <Panel padded={false}>
            <SkeletonRows count={4} />
          </Panel>
        </Section>
      ) : null}

      {summary ? (
        <Section
          label="Ringkasan"
          accessory={summary.predicate_estimate ? <StatusPill label={summary.predicate_estimate} tone="positive" /> : null}>
          <Panel padded={false}>
            <StatRow
              stats={[
                { label: 'IPK', value: formatDecimal(summary.ipk) },
                { label: 'SKS lulus', value: String(summary.sks_passed ?? summary.sks_total ?? '–') },
                { label: 'Total mutu', value: String(summary.quality_points_total ?? '–') },
              ]}
            />
            {load?.sks ? (
              <>
                <Divider />
                <ThemedText type="small" themeColor="textTertiary" style={styles.load}>
                  Sedang ditempuh: {load.courses ?? 0} mata kuliah · {load.sks} SKS
                </ThemedText>
              </>
            ) : null}
          </Panel>
        </Section>
      ) : null}

      {!transcript.isLoading && courses.length === 0 && !transcript.error ? (
        <Section>
          <Panel>
            <EmptyState icon="document" title="Transkrip masih kosong" description="Nilai mata kuliah akan muncul di sini setelah dinilai." />
          </Panel>
        </Section>
      ) : null}

      {lowCourses.length > 0 ? (
        <Section label="Nilai yang bisa diperbaiki" accessory={<Chip label={`${lowCourses.length} mata kuliah`} />}>
          <Panel padded={false}>
            {lowCourses.map((course, index) => (
              <Fragment key={`low-${course.code ?? index}`}>
                {index > 0 ? <Divider inset={Spacing.three} /> : null}
                <TranscriptRow course={course} />
              </Fragment>
            ))}
          </Panel>
        </Section>
      ) : null}

      {groups.map((group) => {
        const sks = group.items.reduce((sum, course) => sum + (course.sks ?? 0), 0);

        return (
          <Section key={group.label} label={group.label} accessory={<Chip label={`${sks} SKS`} />}>
            <Panel padded={false}>
              {group.items.map((course, index) => (
                <Fragment key={`${course.code ?? course.name ?? 'mk'}-${index}`}>
                  {index > 0 ? <Divider inset={Spacing.three} /> : null}
                  <TranscriptRow course={course} />
                </Fragment>
              ))}
            </Panel>
          </Section>
        );
      })}
    </Screen>
  );
}

function TranscriptRow({ course }: { course: GradedCourse }) {
  const attempts = course.attempts ?? [];
  const history =
    (course.attempt_count ?? attempts.length) > 1
      ? `Diambil ${course.attempt_count ?? attempts.length}x: ${attempts.map((attempt) => `${attempt.letter ?? '–'} (smt ${attempt.semester ?? '?'})`).join(', ')}`
      : null;

  return (
    <CourseRow
      showTile={false}
      code={course.code}
      name={course.name}
      detail={[course.code, course.sks ? `${course.sks} SKS` : null, course.quality_points !== null && course.quality_points !== undefined ? `mutu ${course.quality_points}` : null]
        .filter(Boolean)
        .join(' · ')}
      note={history}
      trailing={<LetterBadge letter={course.letter} />}
    />
  );
}

const styles = StyleSheet.create({
  load: {
    padding: Spacing.three,
  },
});
