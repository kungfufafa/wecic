import { useRouter } from 'expo-router';
import { Fragment } from 'react';
import { Pressable, StyleSheet, View } from 'react-native';

import type { ExamListItem, ExamListResponse } from '@/api/types/classes';
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
import { useTheme } from '@/hooks/use-theme';

const TILE_SIZE = 44;

export default function ExamsScreen() {
  const exams = useApiResource<ExamListResponse>('exam');
  const items = exams.data?.data.exams ?? [];
  const uploadable = items.filter((exam) => exam.can_upload).length;

  return (
    <Screen
      back
      title="Ujian"
      subtitle="Ujian mata kuliah semester ini"
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
            <EmptyState icon="checklist" title="Belum ada ujian" description="Ujian yang dibuka dosen akan muncul di sini." />
          </Panel>
        </Section>
      ) : null}

      {items.length > 0 ? (
        <Section label="Daftar ujian" accessory={<ThemedText type="small" themeColor="textTertiary" style={styles.count}>{`${items.length} mata kuliah`}</ThemedText>}>
          <Panel padded={false}>
            {items.map((exam, index) => (
              <Fragment key={`${exam.id ?? exam.course_code ?? 'ujian'}-${index}`}>
                {index > 0 ? <Divider inset={Spacing.three + TILE_SIZE + Spacing.three - Spacing.one} /> : null}
                <ExamRow exam={exam} />
              </Fragment>
            ))}
          </Panel>
        </Section>
      ) : null}

      {items.length > 0 && items.every((exam) => !exam.id) ? (
        <Notice message="Portal belum membuka ujian mana pun. Ujian bisa dibuka setelah dosen menjadwalkannya." />
      ) : null}
    </Screen>
  );
}

function ExamRow({ exam }: { exam: ExamListItem }) {
  const theme = useTheme();
  const router = useRouter();
  const id = exam.id !== null && exam.id !== undefined && String(exam.id) !== '' ? String(exam.id) : null;
  const status = exam.status?.trim() || null;

  const content = (
    <>
      <CourseTile name={exam.course_name} code={exam.course_code} size={TILE_SIZE} />
      <View style={styles.text}>
        <View style={styles.title}>
          <ThemedText type="bodyMedium" numberOfLines={2}>
            {exam.course_name ?? 'Mata kuliah'}
          </ThemedText>
          {exam.course_code ? (
            <ThemedText type="caption" themeColor="textTertiary">
              {exam.course_code}
            </ThemedText>
          ) : null}
        </View>
        <MetaRow icon="calendarClock" text={exam.schedule} />
        <MetaRow icon="person" text={exam.lecturer} />
        <View style={styles.pills}>
          {exam.can_upload ? <StatusPill label="Unggah jawaban dibuka" tone="attention" /> : null}
          {status ? <StatusPill label={status} tone="neutral" /> : null}
          {!id ? <StatusPill label="Belum dibuka" tone="muted" /> : null}
        </View>
      </View>
      {id ? <Icon name="chevronRight" size={14} color={theme.textQuaternary} style={styles.chevron} /> : null}
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
    gap: Spacing.two - Spacing.half,
    marginTop: Spacing.one,
  },
  chevron: {
    alignSelf: 'center',
  },
});
