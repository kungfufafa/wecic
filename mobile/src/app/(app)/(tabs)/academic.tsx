import { useRouter } from 'expo-router';
import { Fragment } from 'react';
import { StyleSheet, View } from 'react-native';

import type { GradesResponse, KrsResponse } from '@/api/types/academic';
import { ListRow, ListRowTextInset } from '@/components/list-row';
import { Notice } from '@/components/notice';
import { ProgressBar } from '@/components/progress-bar';
import { Screen } from '@/components/screen';
import { Divider, Panel, Section } from '@/components/section';
import { StatRow } from '@/components/stat-row';
import { StatusPill } from '@/components/status-pill';
import { ThemedText } from '@/components/themed-text';
import { Spacing } from '@/constants/theme';
import { useApiResource } from '@/hooks/use-api-resource';
import { useTheme } from '@/hooks/use-theme';
import { formatDecimal, krsStatusTone } from '@/lib/academic';
import { FEATURE_GROUPS } from '@/lib/features';

/**
 * The Akademik tab, as the web's: where the student stands (IPK, credits,
 * this semester's KRS) and the academic pages behind it.
 */
export default function AcademicScreen() {
  const theme = useTheme();
  const router = useRouter();
  const grades = useApiResource<GradesResponse>('academic/grades');
  const krs = useApiResource<KrsResponse>('academic/krs');
  const summary = grades.data?.data?.summary;
  const progress = summary?.progress;
  const ipk = summary?.ipk ?? summary?.official?.ipk ?? null;
  const latestIps = summary?.computed?.latest_ips?.ips ?? null;
  const sks = progress?.earned_sks ?? summary?.sks_passed ?? null;
  const status = krs.data?.data?.insights?.status ?? krs.data?.data?.status ?? null;
  const academic = FEATURE_GROUPS.find((group) => group.title === 'Akademik')?.features ?? [];
  const loading = grades.isLoading && krs.isLoading;

  function refresh() {
    void grades.refresh();
    void krs.refresh();
  }

  return (
    <Screen
      title="Akademik"
      subtitle={krs.data?.data?.academic_period?.label ?? null}
      refreshing={grades.isRefreshing || krs.isRefreshing}
      onRefresh={refresh}
      dataSavedAt={grades.savedAt}>
      {grades.error && krs.error ? <Notice tone="error" message={grades.error} /> : null}

      <Section
        label="Prestasi"
        accessory={summary?.predicate_estimate ? <StatusPill label={summary.predicate_estimate} tone="positive" /> : null}>
        <Panel padded={false}>
          <StatRow
            stats={[
              { label: 'IPK', value: ipk === null ? '–' : formatDecimal(ipk), color: theme.text },
              { label: 'IPS terakhir', value: latestIps === null ? '–' : formatDecimal(latestIps) },
              { label: 'SKS lulus', value: sks === null ? (loading ? '–' : '0') : String(sks) },
            ]}
          />
          {progress?.target_sks ? (
            <>
              <Divider inset={Spacing.three} />
              <View style={styles.progress}>
                <ProgressBar percent={progress.progress_percent} color={theme.success} />
                <ThemedText type="caption" themeColor="textTertiary">
                  {`${progress.earned_sks ?? 0} dari ${progress.target_sks} SKS untuk lulus`}
                </ThemedText>
              </View>
            </>
          ) : null}
        </Panel>
      </Section>

      <Section label="KRS semester ini" accessory={status?.label ? <StatusPill label={status.label} tone={krsStatusTone(status.code)} /> : null}>
        <Panel padded={false}>
          <ListRow
            icon="list"
            label="KRS & Perwalian"
            value={krs.data?.data?.totals?.sks ? `${krs.data.data.totals.courses ?? 0} mata kuliah · ${krs.data.data.totals.sks} SKS` : 'Rencana studi dan dosen wali'}
            onPress={() => router.push('/krs')}
          />
        </Panel>
      </Section>

      <Section label="Menu akademik">
        <Panel padded={false}>
          {academic
            .filter((feature) => feature.key !== 'krs')
            .map((feature, index) => (
              <Fragment key={feature.key}>
                {index > 0 ? <Divider inset={ListRowTextInset} /> : null}
                <ListRow icon={feature.icon} label={feature.title} value={feature.description} onPress={() => router.push(feature.href)} />
              </Fragment>
            ))}
        </Panel>
      </Section>
    </Screen>
  );
}

const styles = StyleSheet.create({
  progress: {
    gap: Spacing.two,
    padding: Spacing.three,
  },
});
