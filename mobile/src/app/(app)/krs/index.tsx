import { useRouter } from 'expo-router';
import { Fragment } from 'react';
import { StyleSheet, View } from 'react-native';

import { CourseRow } from '@/components/academic/course-row';
import { StepList } from '@/components/academic/step-list';
import type { AdvisingResponse, KrsPeriod, KrsResponse } from '@/api/types/academic';
import { Chip } from '@/components/chip';
import { ContactButtons } from '@/components/contact-buttons';
import { EmptyState } from '@/components/empty-state';
import { ListRow } from '@/components/list-row';
import { Notice } from '@/components/notice';
import { Screen } from '@/components/screen';
import { Divider, Panel, Section } from '@/components/section';
import { SkeletonRows } from '@/components/skeleton';
import { StatusPill } from '@/components/status-pill';
import { ThemedText } from '@/components/themed-text';
import { Spacing } from '@/constants/theme';
import { useApiResource } from '@/hooks/use-api-resource';
import { krsStatusTone, periodTiming, semesterLabel } from '@/lib/academic';
import { formatDate } from '@/lib/format';

export default function KrsScreen() {
  const router = useRouter();
  const krs = useApiResource<KrsResponse>('academic/krs');
  const advising = useApiResource<AdvisingResponse>('academic/advising');
  const data = krs.data?.data;
  const insights = data?.insights;
  const status = insights?.status ?? data?.status;
  const dpa = insights?.dpa ?? data?.dpa ?? advising.data?.data?.dpa ?? null;
  const tracker = insights?.tracker ?? [];
  const periods = insights?.periods?.length ? insights.periods : data?.period?.start ? [{ label: 'Periode KRS', ...data.period }] : [];
  const load = insights?.study_load ?? data?.totals;
  const courses = data?.courses ?? [];
  const history = advising.data?.data?.krs_history ?? [];

  function refresh() {
    void krs.refresh();
    void advising.refresh();
  }

  return (
    <Screen
      back
      title="KRS & Perwalian"
      subtitle={data?.academic_period?.label ?? null}
      refreshing={krs.isRefreshing || advising.isRefreshing}
      onRefresh={refresh}
      dataSavedAt={krs.savedAt}>
      {krs.error ? <Notice tone="error" message={krs.error} /> : null}

      {krs.isLoading ? (
        <Section label="Memuat KRS">
          <Panel padded={false}>
            <SkeletonRows count={3} />
          </Panel>
        </Section>
      ) : null}

      {!krs.isLoading && data && data.found === false ? (
        <Section>
          <Panel>
            <EmptyState icon="list" title="KRS belum tersedia" />
          </Panel>
        </Section>
      ) : null}

      {status?.label || tracker.length > 0 ? (
        <Section
          label="Status KRS"
          accessory={status?.label ? <StatusPill label={status.label} tone={krsStatusTone(status.code)} /> : null}>
          <Panel padded={false} style={styles.block}>
            {tracker.length > 0 ? <StepList steps={tracker} /> : null}
            {data?.notice_text ? (
              <ThemedText type="small" themeColor="textTertiary">
                {data.notice_text}
              </ThemedText>
            ) : null}
          </Panel>
        </Section>
      ) : null}

      {periods.length > 0 ? (
        <Section label="Periode KRS">
          <Panel padded={false}>
            {periods.map((period: KrsPeriod, index: number) => (
              <Fragment key={`${period.label ?? 'periode'}-${index}`}>
                {index > 0 ? <Divider /> : null}
                <PeriodRow period={period} />
              </Fragment>
            ))}
          </Panel>
        </Section>
      ) : null}

      {data?.notice?.text ? <Notice message={data.notice.text} /> : null}

      {dpa?.name ? (
        <Section label="Dosen pembimbing akademik">
          <Panel padded={false} style={styles.block}>
            <View style={styles.dpa}>
              <ThemedText type="bodyMedium" numberOfLines={2}>
                {dpa.name}
              </ThemedText>
              {dpa.nidn ? (
                <ThemedText type="small" themeColor="textTertiary" numberOfLines={1}>
                  NIDN {dpa.nidn}
                </ThemedText>
              ) : null}
            </View>
            <ContactButtons contact={dpa} />
          </Panel>
        </Section>
      ) : null}

      {courses.length > 0 ? (
        <Section
          label="Mata kuliah semester ini"
          accessory={
            <Chip
              label={`${load?.courses ?? courses.length} MK · ${load?.sks ?? courses.reduce((sum, course) => sum + (course.sks ?? 0), 0)} SKS`}
            />
          }>
          <Panel padded={false}>
            {courses.map((course, index) => (
              <Fragment key={`${course.code ?? course.name ?? 'mk'}-${index}`}>
                {index > 0 ? <Divider /> : null}
                <CourseRow
                  showTile={false}
                  code={course.code}
                  name={course.name}
                  detail={[course.code, course.sks ? `${course.sks} SKS` : null, course.semester ? `Smt ${course.semester}` : null]
                    .filter(Boolean)
                    .join(' · ')}
                />
              </Fragment>
            ))}
          </Panel>
        </Section>
      ) : null}

      {history.length > 0 ? (
        <Section label="Riwayat KRS">
          <Panel padded={false}>
            {history.map((entry, index) => (
              <Fragment key={entry.krs_id ?? index}>
                {index > 0 ? <Divider /> : null}
                <ListRow
                  icon="list"
                  label={entry.semester_label ?? semesterLabel(entry.semester)}
                  value={[entry.academic_year, entry.date ? formatDate(entry.date) : null].filter(Boolean).join(' · ')}
                  onPress={
                    entry.krs_id ? () => router.push({ pathname: '/krs/[id]', params: { id: String(entry.krs_id) } }) : undefined
                  }
                />
              </Fragment>
            ))}
          </Panel>
        </Section>
      ) : null}

      {advising.error && !krs.error ? <Notice tone="warning" message={`Riwayat KRS belum bisa dimuat: ${advising.error}`} /> : null}
    </Screen>
  );
}

function PeriodRow({ period }: { period: KrsPeriod }) {
  const timing = periodTiming(period);
  const dates = [period.start ? formatDate(period.start) : null, period.end ? formatDate(period.end) : null].filter(Boolean).join(' – ');

  return (
    <View style={styles.period}>
      <View style={styles.periodText}>
        <ThemedText type="bodyMedium" numberOfLines={1}>
          {period.label ?? 'Periode KRS'}
        </ThemedText>
        {dates ? (
          <ThemedText type="small" themeColor="textTertiary" numberOfLines={1}>
            {dates}
          </ThemedText>
        ) : null}
      </View>
      {timing ? <StatusPill label={timing.label} tone={timing.tone} /> : null}
    </View>
  );
}

const styles = StyleSheet.create({
  block: {
    gap: Spacing.three - Spacing.one,
    paddingVertical: Spacing.three - Spacing.one,
    paddingHorizontal: Spacing.three,
  },
  dpa: {
    gap: Spacing.half,
  },
  period: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.three - Spacing.one,
    paddingVertical: Spacing.three - Spacing.one,
    paddingHorizontal: Spacing.three,
  },
  periodText: {
    flex: 1,
    gap: Spacing.half,
  },
});
