import { useRouter } from 'expo-router';
import { Fragment } from 'react';
import { Pressable, StyleSheet, View } from 'react-native';

import type { ExamListItem, ExamListResponse } from '@/api/types/classes';
import { Chip } from '@/components/chip';
import { EmptyState } from '@/components/empty-state';
import { Icon } from '@/components/icon';
import { Notice } from '@/components/notice';
import { Screen } from '@/components/screen';
import { Divider, Panel, Section } from '@/components/section';
import { SkeletonRows } from '@/components/skeleton';
import { StatusPill } from '@/components/status-pill';
import { ThemedText } from '@/components/themed-text';
import { Spacing } from '@/constants/theme';
import { useApiResource } from '@/hooks/use-api-resource';
import { useTheme } from '@/hooks/use-theme';

export default function ExamsScreen() {
  const exams = useApiResource<ExamListResponse>('exam');
  const items = exams.data?.data.exams ?? [];
  const uploadable = items.filter((exam) => exam.can_upload).length;

  return (
    <Screen
      back
      title="Ujian"
      accessory={uploadable > 0 ? <Chip label={`${uploadable} bisa diunggah`} /> : null}
      refreshing={exams.isRefreshing}
      dataSavedAt={exams.savedAt}
      onRefresh={exams.refresh}>
      {exams.error ? <Notice tone="error" message={exams.error} /> : null}

      {exams.isLoading ? (
        <Section label="Memuat ujian">
          <Panel padded={false}>
            <SkeletonRows count={3} />
          </Panel>
        </Section>
      ) : null}

      {!exams.isLoading && items.length === 0 && !exams.error ? (
        <Section>
          <Panel>
            <EmptyState icon="checklist" title="Belum ada ujian" />
          </Panel>
        </Section>
      ) : null}

      {items.length > 0 ? (
        <Section label="Daftar ujian" accessory={<Chip label={`${items.length} MK`} />}>
          <Panel padded={false}>
            {items.map((exam, index) => (
              <Fragment key={`${exam.id ?? exam.course_code ?? 'ujian'}-${index}`}>
                {index > 0 ? <Divider /> : null}
                <ExamRow exam={exam} />
              </Fragment>
            ))}
          </Panel>
        </Section>
      ) : null}

      {items.length > 0 && items.every((exam) => !exam.id) ? (
        <Notice message="Belum ada ujian yang dibuka dosen." />
      ) : null}
    </Screen>
  );
}

function ExamRow({ exam }: { exam: ExamListItem }) {
  const theme = useTheme();
  const router = useRouter();
  const id = exam.id !== null && exam.id !== undefined && String(exam.id) !== '' ? String(exam.id) : null;
  const status = exam.status?.trim() || null;

  const meta = [exam.course_code, exam.schedule, exam.lecturer].filter((part) => part && part.trim() !== '').join(' · ');
  const hasPills = exam.can_upload || status !== null || !id;

  const content = (
    <>
      <View style={styles.text}>
        <ThemedText type="bodyMedium" numberOfLines={2}>
          {exam.course_name ?? 'Mata kuliah'}
        </ThemedText>
        {meta ? (
          <ThemedText type="small" themeColor="textTertiary" numberOfLines={2}>
            {meta}
          </ThemedText>
        ) : null}
        {hasPills ? (
          <View style={styles.pills}>
            {exam.can_upload ? <StatusPill label="Unggah dibuka" tone="attention" /> : null}
            {status ? <StatusPill label={status} tone="neutral" /> : null}
            {!id ? <StatusPill label="Belum dibuka" tone="muted" /> : null}
          </View>
        ) : null}
      </View>
      {id ? <Icon name="chevronRight" size={15} color={theme.textQuaternary} /> : null}
    </>
  );

  if (!id) {
    return <View style={styles.row}>{content}</View>;
  }

  return (
    <Pressable
      accessibilityRole="button"
      accessibilityHint="Buka detail ujian"
      onPress={() => router.push({ pathname: '/exams/[id]', params: { id } })}
      style={({ pressed }) => [styles.row, pressed && { backgroundColor: theme.section }]}>
      {content}
    </Pressable>
  );
}

const styles = StyleSheet.create({
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.three - Spacing.one,
    paddingVertical: Spacing.three - Spacing.one,
    paddingHorizontal: Spacing.three,
  },
  text: {
    flex: 1,
    gap: Spacing.half,
  },
  pills: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: Spacing.one,
    marginTop: Spacing.one,
  },
});
