import { Fragment } from 'react';
import { StyleSheet, View } from 'react-native';

import { ToneBadge } from '@/components/academic/badges';
import { NumberedList } from '@/components/academic/bullet-list';
import { toneColors } from '@/components/academic/tone';
import type { ChecklistItem, ThesisReadinessResponse, ThesisResponse } from '@/api/types/academic';
import { Chip } from '@/components/chip';
import { Icon, type IconName } from '@/components/icon';
import { InfoRows } from '@/components/info-rows';
import { ListRow, ListRowTextInset } from '@/components/list-row';
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
import { usePortalFile } from '@/hooks/use-portal-file';
import { useTheme } from '@/hooks/use-theme';
import { checkProgress, checklistTone, formatDecimal } from '@/lib/academic';

export default function ThesisScreen() {
  const theme = useTheme();
  const thesis = useApiResource<ThesisResponse>('thesis');
  const readiness = useApiResource<ThesisReadinessResponse>('thesis/readiness');
  const file = usePortalFile();
  const data = thesis.data?.data;
  const ready = readiness.data?.data;
  const summary = ready?.summary;
  const checklist = ready?.checklist_items ?? [];
  const automatic = checklist.filter((item) => item.kind !== 'manual');
  const manual = checklist.filter((item) => item.kind === 'manual');
  const jenjang = ready?.jenjang ?? data?.student?.jenjang ?? null;
  const thresholds = ready?.thresholds;
  const minSks = thresholds?.min_sks ?? (jenjang ? data?.thresholds?.min_sks?.[jenjang] : null) ?? null;
  const enrolled = data?.status?.state !== 'not_enrolled' && data?.krs_hint?.has_thesis_course !== false;

  function refresh() {
    void thesis.refresh();
    void readiness.refresh();
  }

  return (
    <Screen
      back
      title="Skripsi"
      subtitle={data?.title ?? null}
      refreshing={thesis.isRefreshing || readiness.isRefreshing}
      onRefresh={refresh}
      dataSavedAt={thesis.savedAt}>
      {thesis.error ? <Notice tone="error" message={thesis.error} /> : null}
      {readiness.error && !thesis.error ? <Notice tone="error" message={readiness.error} /> : null}

      {thesis.isLoading || readiness.isLoading ? (
        <Section label="Memuat data skripsi">
          <Panel padded={false}>
            <SkeletonRows count={3} />
          </Panel>
        </Section>
      ) : null}

      {data?.status?.message ? (
        <Section
          label="Status skripsi"
          accessory={<StatusPill label={enrolled ? 'Mengambil skripsi' : 'Belum mengambil'} tone={enrolled ? 'positive' : 'muted'} />}>
          <Panel style={styles.gap}>
            <ThemedText type="bodyMedium">{data.status.message}</ThemedText>
            {data.krs_hint?.course?.name ? (
              <ThemedText type="small" themeColor="textTertiary">
                Di KRS: {data.krs_hint.course.name}
              </ThemedText>
            ) : null}
            {data.krs_hint?.awaiting_status ? (
              <ThemedText type="small" themeColor="textTertiary">
                Status dari portal sedang ditunggu.
              </ThemedText>
            ) : null}
          </Panel>
        </Section>
      ) : null}

      {summary ? (
        <Section
          label="Kesiapan akademik"
          accessory={
            <StatusPill label={summary.academic_ready ? 'Siap secara akademik' : 'Belum siap'} tone={summary.academic_ready ? 'positive' : 'attention'} />
          }>
          <Panel padded={false}>
            <View style={styles.progress}>
              <View style={styles.progressHead}>
                <ThemedText type="bodyMedium">Syarat otomatis terpenuhi</ThemedText>
                <ThemedText type="subtitle">{formatDecimal(summary.progress_percent, 1)}%</ThemedText>
              </View>
              <ProgressBar percent={summary.progress_percent} color={summary.academic_ready ? theme.success : theme.brand} height={10} />
            </View>
            <Divider />
            <StatRow
              stats={[
                { label: 'Terpenuhi', value: String(summary.passed ?? 0), color: theme.success },
                { label: 'Belum', value: String(summary.failed ?? 0), color: theme.danger },
                { label: 'Perlu dicek', value: String((summary.unknown ?? 0) + (summary.manual ?? 0)) },
              ]}
            />
          </Panel>
        </Section>
      ) : null}

      {(ready?.insights?.warnings?.length ?? 0) > 0
        ? ready?.insights?.warnings?.map((warning) => <Notice key={warning} tone="warning" message={warning} />)
        : null}

      {automatic.length > 0 ? (
        <Section label="Syarat dari data akademik">
          <Panel padded={false}>
            {automatic.map((item, index) => (
              <Fragment key={item.key ?? index}>
                {index > 0 ? <Divider inset={CHECK_INSET} /> : null}
                <CheckRow item={item} />
              </Fragment>
            ))}
          </Panel>
        </Section>
      ) : null}

      {manual.length > 0 ? (
        <Section label="Disiapkan sendiri">
          <Panel padded={false}>
            {manual.map((item, index) => (
              <Fragment key={item.key ?? index}>
                {index > 0 ? <Divider inset={CHECK_INSET} /> : null}
                <CheckRow item={item} />
              </Fragment>
            ))}
          </Panel>
        </Section>
      ) : null}

      {thresholds || data?.thresholds ? (
        <Section label="Batas minimum" accessory={jenjang ? <Chip label={jenjang} /> : null}>
          <Panel padded={false}>
            <InfoRows
              rows={[
                { label: 'SKS lulus', value: minSks !== null && minSks !== undefined ? `${minSks} SKS` : null },
                { label: 'IPK', value: formatDecimal(thresholds?.min_ipk ?? data?.thresholds?.min_ipk) },
                { label: 'Nilai D', value: `Maksimal ${thresholds?.max_d ?? data?.thresholds?.max_d ?? '–'}` },
                { label: 'Nilai E', value: (thresholds?.allow_e ?? data?.thresholds?.allow_e) ? 'Boleh ada' : 'Tidak boleh ada' },
              ]}
            />
          </Panel>
        </Section>
      ) : null}

      {(data?.requirements?.thesis?.length ?? 0) > 0 ? (
        <Section label="Syarat mengambil skripsi">
          <Panel>
            <NumberedList items={data?.requirements?.thesis ?? []} />
          </Panel>
        </Section>
      ) : null}

      {(data?.requirements?.exam?.length ?? 0) > 0 ? (
        <Section label="Syarat ujian skripsi">
          <Panel>
            <NumberedList items={data?.requirements?.exam ?? []} />
          </Panel>
        </Section>
      ) : null}

      {(data?.documents?.length ?? 0) > 0 ? (
        <Section label="Dokumen">
          <Panel padded={false}>
            {data?.documents?.map((document, index) => (
              <Fragment key={document.path ?? index}>
                {index > 0 ? <Divider inset={ListRowTextInset} /> : null}
                <ListRow
                  icon="document"
                  label={document.label ?? document.filename ?? 'Dokumen'}
                  value={file.busyHref === document.path ? 'Membuka…' : document.filename}
                  trailingIcon="download"
                  onPress={() => void file.open(document.path, document.filename ?? document.label)}
                />
              </Fragment>
            ))}
          </Panel>
        </Section>
      ) : null}
    </Screen>
  );
}

const CHECK_ICON = 36;
const CHECK_INSET = Spacing.three + CHECK_ICON + Spacing.three - Spacing.one;

function CheckRow({ item }: { item: ChecklistItem }) {
  const theme = useTheme();
  const tone = checklistTone(item.status);
  const colors = toneColors(theme, tone);
  const icon: IconName = item.status === 'passed' ? 'check' : item.status === 'failed' ? 'alert' : item.status === 'manual' ? 'checklist' : 'info';
  const progress = checkProgress(item.current, item.target);
  const statusLabel =
    item.status === 'passed' ? 'Terpenuhi' : item.status === 'failed' ? 'Belum' : item.status === 'manual' ? 'Siapkan' : 'Cek manual';

  return (
    <View style={styles.check}>
      <View style={[styles.checkIcon, { backgroundColor: colors.tint }]}>
        <Icon name={icon} size={18} color={colors.ink} />
      </View>
      <View style={styles.flex}>
        <View style={styles.checkHead}>
          <ThemedText type="bodyMedium" style={styles.flex}>
            {item.label ?? 'Syarat'}
          </ThemedText>
          <ToneBadge label={statusLabel} tone={tone} />
        </View>
        {progress ? (
          <ThemedText type="small" themeColor="textSecondary">
            {progress}
          </ThemedText>
        ) : null}
        {item.hint ? (
          <ThemedText type="small" themeColor="textTertiary">
            {item.hint}
          </ThemedText>
        ) : null}
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  gap: {
    gap: Spacing.two,
  },
  flex: {
    flex: 1,
    gap: Spacing.half,
  },
  progress: {
    gap: Spacing.two + Spacing.one,
    padding: Spacing.three,
  },
  progressHead: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
  },
  check: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: Spacing.three - Spacing.one,
    padding: Spacing.three,
  },
  checkIcon: {
    width: CHECK_ICON,
    height: CHECK_ICON,
    borderRadius: CHECK_ICON / 2,
    alignItems: 'center',
    justifyContent: 'center',
  },
  checkHead: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: Spacing.two,
  },
});
