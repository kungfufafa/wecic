import { Fragment, useState } from 'react';
import { ScrollView, StyleSheet, View } from 'react-native';

import { LetterBadge } from '@/components/academic/badges';
import { BarList } from '@/components/academic/bar-list';
import { CourseRow } from '@/components/academic/course-row';
import type { GradesResponse, GraduationTarget, KhsSemester } from '@/api/types/academic';
import { Chip } from '@/components/chip';
import { EmptyState } from '@/components/empty-state';
import { Notice } from '@/components/notice';
import { ProgressBar } from '@/components/progress-bar';
import { Screen } from '@/components/screen';
import { Divider, Panel, Section } from '@/components/section';
import { SkeletonRows } from '@/components/skeleton';
import { StatRow } from '@/components/stat-row';
import { StatusPill } from '@/components/status-pill';
import { ThemedText } from '@/components/themed-text';
import { Spacing } from '@/constants/theme';
import { useApiResource } from '@/hooks/use-api-resource';
import { useTheme } from '@/hooks/use-theme';
import { columnLabel, formatDecimal, formatDelta, semesterLabel } from '@/lib/academic';

const LETTERS = ['A', 'B', 'C', 'D', 'E'];

export default function GradesScreen() {
  const theme = useTheme();
  const grades = useApiResource<GradesResponse>('academic/grades');
  const data = grades.data?.data;
  const summary = data?.summary;
  const progress = summary?.progress;
  const computed = summary?.computed;
  const ipk = summary?.official?.ipk ?? summary?.ipk ?? computed?.ipk ?? null;
  const latest = computed?.latest_ips;
  const delta = formatDelta(latest?.delta);
  const series = computed?.ips_series ?? [];
  const letterCounts = summary?.official?.letter_counts ?? computed?.letter_counts ?? {};
  const semesters = data?.semesters ?? [];
  const firstGraded = semesters.find((semester) => (semester.courses?.length ?? 0) > 0) ?? semesters[0];
  const [picked, setPicked] = useState<string | null>(null);
  const selected = semesters.find((semester) => semesterKey(semester) === picked) ?? firstGraded;
  const progressFacts = progress
    ? [
        `${progress.earned_sks ?? 0}/${progress.target_sks} SKS`,
        progress.remaining_sks !== null && progress.remaining_sks !== undefined ? `sisa ${progress.remaining_sks}` : null,
        progress.projected_semesters_remaining ? `±${progress.projected_semesters_remaining} semester lagi` : null,
        progress.average_sks_per_semester ? `${progress.average_sks_per_semester} SKS/smt` : null,
      ]
        .filter(Boolean)
        .join(' · ')
    : '';

  return (
    <Screen back title="Nilai & IPK" refreshing={grades.isRefreshing}
      dataSavedAt={grades.savedAt} onRefresh={grades.refresh}>
      {grades.error ? <Notice tone="error" message={grades.error} /> : null}

      {grades.isLoading ? (
        <Section label="Memuat nilai">
          <Panel padded={false}>
            <SkeletonRows count={3} />
          </Panel>
        </Section>
      ) : null}

      {!grades.isLoading && data?.found === false ? (
        <Section>
          <Panel>
            <EmptyState icon="chart" title="Nilai belum tersedia" />
          </Panel>
        </Section>
      ) : null}

      {summary ? (
        <Section label="Ringkasan" accessory={summary.predicate_estimate ? <StatusPill label={summary.predicate_estimate} tone="positive" /> : null}>
          <Panel padded={false}>
            <StatRow
              stats={[
                { label: 'IPK', value: formatDecimal(ipk) },
                { label: 'SKS lulus', value: String(summary.sks_passed ?? summary.official?.sks_total ?? '–') },
                { label: 'Mutu', value: String(summary.quality_points_total ?? summary.official?.quality_points_total ?? '–') },
                {
                  label: delta ? `IPS akhir (${delta})` : 'IPS akhir',
                  value: formatDecimal(latest?.ips),
                  color: latest?.delta && latest.delta < 0 ? theme.danger : undefined,
                },
              ]}
            />
          </Panel>
        </Section>
      ) : null}

      {progress?.target_sks ? (
        <Section label="Progres studi" accessory={<Chip label={`${formatDecimal(progress.progress_percent, 1)}%`} />}>
          <Panel padded={false} style={styles.block}>
            <ProgressBar percent={progress.progress_percent} color={theme.success} />
            <ThemedText type="small" themeColor="textSecondary" numberOfLines={2}>
              {progressFacts}
            </ThemedText>
          </Panel>
        </Section>
      ) : null}

      {series.length > 0 ? (
        <Section label="IPS per semester">
          <Panel padded={false} style={styles.block}>
            <BarList
              max={4}
              bars={series.map((point, index) => ({
                key: `${point.semester ?? index}`,
                label: point.semester !== null && point.semester !== undefined ? `Smt ${point.semester}` : (point.label ?? '-'),
                value: point.ips,
                valueLabel: formatDecimal(point.ips),
                color: point.is_complete === false ? theme.textTertiary : theme.brand,
              }))}
            />
          </Panel>
        </Section>
      ) : null}

      {Object.keys(letterCounts).length > 0 ? (
        <Section label="Sebaran nilai">
          <Panel padded={false} style={styles.letters}>
            {LETTERS.map((letter) => (
              <View key={letter} style={styles.letter} accessible accessibilityLabel={`Nilai ${letter}: ${letterCounts[letter] ?? 0}`}>
                <LetterBadge letter={letter} size={28} />
                <ThemedText type="smallBold">{letterCounts[letter] ?? 0}</ThemedText>
              </View>
            ))}
          </Panel>
        </Section>
      ) : null}

      {(progress?.graduation_targets?.length ?? 0) > 0 ? (
        <Section label="Target predikat">
          <Panel padded={false}>
            {progress?.graduation_targets?.map((target, index) => (
              <Fragment key={target.predicate ?? index}>
                {index > 0 ? <Divider /> : null}
                <TargetRow target={target} />
              </Fragment>
            ))}
          </Panel>
        </Section>
      ) : null}

      {semesters.length > 0 ? (
        <Section label="KHS">
          <Panel padded={false}>
            <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.chips}>
              {semesters.map((semester) => (
                <Chip
                  key={semesterKey(semester)}
                  label={semester.semester !== null && semester.semester !== undefined ? semesterLabel(semester.semester).replace(' (konversi)', '') : (semester.label ?? '-')}
                  selected={selected !== undefined && semesterKey(semester) === semesterKey(selected)}
                  onPress={() => setPicked(semesterKey(semester))}
                />
              ))}
            </ScrollView>
            {selected ? <KhsPanel semester={selected} /> : null}
          </Panel>
        </Section>
      ) : null}
    </Screen>
  );
}

function semesterKey(semester: KhsSemester): string {
  return String(semester.semester ?? semester.label ?? '');
}

function TargetRow({ target }: { target: GraduationTarget }) {
  const status = target.already_reached
    ? { label: 'Tercapai', tone: 'positive' as const }
    : target.achievable
      ? { label: 'Bisa dicapai', tone: 'neutral' as const }
      : { label: 'Sulit dicapai', tone: 'attention' as const };

  return (
    <View style={styles.target}>
      <View style={styles.targetText}>
        <ThemedText type="bodyMedium" numberOfLines={1}>
          {target.predicate ?? 'Predikat'}
        </ThemedText>
        <ThemedText type="small" themeColor="textTertiary" numberOfLines={1}>
          IPK min. {formatDecimal(target.target_ipk)}
          {target.already_reached ? '' : ` · perlu IPS ${formatDecimal(target.required_ips)}`}
        </ThemedText>
      </View>
      <StatusPill label={status.label} tone={status.tone} />
    </View>
  );
}

function KhsPanel({ semester }: { semester: KhsSemester }) {
  const courses = semester.courses ?? [];
  const columns = semester.columns ?? [];

  if (courses.length === 0) {
    return (
      <ThemedText type="small" themeColor="textTertiary" style={styles.empty}>
        Belum ada nilai di {semester.semester !== undefined && semester.semester !== null ? semesterLabel(semester.semester).toLowerCase() : 'semester ini'}.
      </ThemedText>
    );
  }

  return (
    <View>
      <Divider />
      <StatRow
        stats={[
          { label: 'IPS', value: formatDecimal(semester.ips) },
          { label: 'SKS', value: String(semester.total_sks ?? '–') },
          { label: 'Mutu', value: String(semester.total_quality_points ?? '–') },
        ]}
      />
      {courses.map((course, index) => {
        const components = columns
          .map((column) => {
            const value = course.components?.[column];

            return value === null || value === undefined || value === '' ? null : `${columnLabel(column)} ${value}`;
          })
          .filter(Boolean)
          .join(' · ');

        return (
          <Fragment key={`${course.code ?? course.name ?? 'mk'}-${index}`}>
            <Divider />
            <CourseRow
              showTile={false}
              code={course.code}
              name={course.name}
              detail={[course.code, course.sks ? `${course.sks} SKS` : null, course.quality_points !== null && course.quality_points !== undefined ? `mutu ${course.quality_points}` : null]
                .filter(Boolean)
                .join(' · ')}
              note={components || (course.is_graded === false ? 'Belum dinilai' : null)}
              trailing={<LetterBadge letter={course.letter} />}
            />
          </Fragment>
        );
      })}
    </View>
  );
}

const styles = StyleSheet.create({
  block: {
    gap: Spacing.two,
    paddingVertical: Spacing.three - Spacing.one,
    paddingHorizontal: Spacing.three,
  },
  letters: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    paddingVertical: Spacing.three - Spacing.one,
    paddingHorizontal: Spacing.three + Spacing.half,
  },
  letter: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.one + Spacing.half,
  },
  target: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.three - Spacing.one,
    paddingVertical: Spacing.three - Spacing.one,
    paddingHorizontal: Spacing.three,
  },
  targetText: {
    flex: 1,
    gap: Spacing.half,
  },
  chips: {
    gap: Spacing.two,
    paddingHorizontal: Spacing.three,
    paddingVertical: Spacing.three - Spacing.one,
  },
  empty: {
    paddingHorizontal: Spacing.three,
    paddingBottom: Spacing.three,
  },
});
