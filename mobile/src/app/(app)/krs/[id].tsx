import { useLocalSearchParams, useRouter } from 'expo-router';
import { Fragment } from 'react';
import { StyleSheet, View } from 'react-native';

import { ToneBadge } from '@/components/academic/badges';
import { CourseRow, CourseRowInset } from '@/components/academic/course-row';
import type { KrsDetailResponse } from '@/api/types/academic';
import { Button } from '@/components/button';
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
import { krsStatusTone, semesterLabel } from '@/lib/academic';
import { formatDate } from '@/lib/format';

export default function KrsDetailScreen() {
  const router = useRouter();
  const { id } = useLocalSearchParams<{ id: string }>();
  const krsId = typeof id === 'string' && /^\d+$/.test(id) ? id : null;
  const detail = useApiResource<KrsDetailResponse>(krsId ? `academic/krs/${krsId}` : null);
  const data = detail.data?.data;
  const header = data?.header;
  const courses = data?.courses ?? [];
  const navigation = data?.navigation;
  const title = header?.semester_label ?? (header?.semester ? semesterLabel(header.semester) : 'Detail KRS');
  const subtitle = [header?.academic_year, header?.date ? formatDate(header.date) : null].filter(Boolean).join(' · ') || null;

  function open(target: number | null | undefined) {
    if (target) {
      router.replace({ pathname: '/krs/[id]', params: { id: String(target) } });
    }
  }

  return (
    <Screen back title={title} subtitle={subtitle} refreshing={detail.isRefreshing} onRefresh={detail.refresh}>
      {krsId === null ? <Notice tone="error" message="Nomor KRS tidak valid." /> : null}
      {detail.error ? <Notice tone="error" message={detail.error} /> : null}

      {detail.isLoading ? (
        <Section label="Memuat KRS">
          <Panel padded={false}>
            <SkeletonRows count={3} />
          </Panel>
        </Section>
      ) : null}

      {!detail.isLoading && data?.found === false ? (
        <Section>
          <Panel>
            <EmptyState icon="list" title="KRS tidak ditemukan" description="Portal tidak punya KRS dengan nomor ini." />
          </Panel>
        </Section>
      ) : null}

      {data?.status?.label ? (
        <Section label="Status" accessory={<StatusPill label={data.status.label} tone={krsStatusTone(data.status.code)} />}>
          {data.status.raw_text ? (
            <Panel>
              <ThemedText type="small" themeColor="textTertiary">
                {data.status.raw_text}
              </ThemedText>
            </Panel>
          ) : null}
        </Section>
      ) : null}

      {data?.counts_as_study_load === false ? (
        <Notice message="KRS ini konversi, jadi tidak dihitung sebagai beban studi semester." />
      ) : null}

      {courses.length > 0 ? (
        <Section label="Mata kuliah" accessory={data?.totals?.sks ? <Chip label={`${data.totals.sks} SKS`} /> : null}>
          <Panel padded={false}>
            <StatRow
              stats={[
                { label: 'Mata kuliah', value: String(data?.totals?.courses ?? courses.length) },
                { label: 'Total SKS', value: String(data?.totals?.sks ?? courses.reduce((sum, course) => sum + (course.sks ?? 0), 0)) },
              ]}
            />
            {courses.map((course, index) => (
              <Fragment key={`${course.code ?? course.name ?? 'mk'}-${index}`}>
                <Divider inset={index === 0 ? 0 : CourseRowInset} />
                <CourseRow
                  code={course.code}
                  name={course.name}
                  detail={[course.code, course.sks ? `${course.sks} SKS` : null, course.semester ? `Smt ${course.semester}` : null]
                    .filter(Boolean)
                    .join(' · ')}
                  trailing={
                    course.status ? <ToneBadge label={course.status} tone={course.is_retake ? 'warning' : 'neutral'} /> : null
                  }
                />
              </Fragment>
            ))}
          </Panel>
        </Section>
      ) : null}

      {navigation?.older_krs_id || navigation?.newer_krs_id ? (
        <View style={styles.navigation}>
          <Button
            variant="secondary"
            icon="back"
            label="Sebelumnya"
            disabled={!navigation.older_krs_id}
            onPress={() => open(navigation.older_krs_id)}
            style={styles.flex}
          />
          <Button
            variant="secondary"
            icon="arrowRight"
            label="Berikutnya"
            disabled={!navigation.newer_krs_id}
            onPress={() => open(navigation.newer_krs_id)}
            style={styles.flex}
          />
        </View>
      ) : null}
    </Screen>
  );
}

const styles = StyleSheet.create({
  navigation: {
    flexDirection: 'row',
    gap: Spacing.two + Spacing.one,
  },
  flex: {
    flex: 1,
  },
});
