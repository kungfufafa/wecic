import { useRouter } from 'expo-router';
import { useState } from 'react';
import { Pressable, ScrollView, StyleSheet, View } from 'react-native';

import type { ClassesResponse, PortalClass } from '@/api/types';
import { Chip } from '@/components/chip';
import { CourseTile } from '@/components/course-tile';
import { EmptyState } from '@/components/empty-state';
import { Icon } from '@/components/icon';
import { joinMeta } from '@/components/meta-row';
import { Notice } from '@/components/notice';
import { Screen } from '@/components/screen';
import { Divider, Panel, Section } from '@/components/section';
import { SkeletonRows } from '@/components/skeleton';
import { TextField } from '@/components/text-field';
import { ThemedText } from '@/components/themed-text';
import { Spacing } from '@/constants/theme';
import { useApiResource } from '@/hooks/use-api-resource';
import { useTheme } from '@/hooks/use-theme';
import { groupClassesByDay, matchesClassQuery, timeRange } from '@/lib/format';

const TILE_SIZE = 36;

export default function ClassesScreen() {
  const router = useRouter();
  const classes = useApiResource<ClassesResponse>('class');
  const [query, setQuery] = useState('');
  const [day, setDay] = useState<string | null>(null);
  const items = classes.data?.classes ?? [];
  const groups = groupClassesByDay(items);
  const visible = groups
    .filter((group) => day === null || group.day === day)
    .map((group) => ({ ...group, classes: group.classes.filter((portalClass) => matchesClassQuery(portalClass, query)) }))
    .filter((group) => group.classes.length > 0);
  const filtering = query.trim() !== '' || day !== null;

  function clearFilters() {
    setQuery('');
    setDay(null);
  }

  return (
    <Screen
      title="Kuliah"
      accessory={items.length > 0 ? <Chip label={`${items.length} mata kuliah`} /> : null}
      refreshing={classes.isRefreshing}
      dataSavedAt={classes.savedAt}
      onRefresh={classes.refresh}>
      <View style={styles.shortcuts}>
        <Chip label="Tugas" icon="task" onPress={() => router.push('/assignments')} />
        <Chip label="Ujian" icon="checklist" onPress={() => router.push('/exams')} />
        <Chip label="Absen" icon="qr" onPress={() => router.navigate('/attendance')} />
      </View>

      <Section>
        <Panel style={styles.filters}>
          <TextField
            icon="search"
            placeholder="Nama, kode, dosen, atau ruang"
            value={query}
            onChangeText={setQuery}
            clearable
            autoCorrect={false}
            autoCapitalize="none"
            returnKeyType="search"
          />
          {groups.length > 1 ? (
            <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.chips}>
              <Chip label="Semua hari" selected={day === null} onPress={() => setDay(null)} />
              {groups.map((group) => (
                <Chip key={group.day} label={group.day} selected={day === group.day} onPress={() => setDay(group.day)} />
              ))}
            </ScrollView>
          ) : null}
        </Panel>
      </Section>

      {classes.error ? <Notice tone="error" message={classes.error} /> : null}

      {classes.isLoading ? (
        <Section label="Memuat jadwal">
          <Panel padded={false}>
            <SkeletonRows count={3} />
          </Panel>
        </Section>
      ) : null}

      {!classes.isLoading && items.length === 0 && !classes.error ? (
        <Section>
          <Panel>
            <EmptyState icon="book" title="Belum ada kelas" />
          </Panel>
        </Section>
      ) : null}

      {items.length > 0 && visible.length === 0 ? (
        <Section>
          <Panel>
            <EmptyState
              icon="search"
              title="Tidak ada kelas yang cocok"
              action={filtering ? { label: 'Hapus filter', icon: 'close', onPress: clearFilters } : null}
            />
          </Panel>
        </Section>
      ) : null}

      {visible.map((group) => (
        <Section
          key={group.day}
          label={group.day}
          accessory={
            <ThemedText type="small" themeColor="textTertiary" style={styles.count}>
              {group.classes.length} kelas
            </ThemedText>
          }>
          <Panel padded={false}>
            {group.classes.map((portalClass, index) => (
              <View key={portalClass.join_link?.params?.id ?? `${portalClass.course_info?.code ?? 'class'}-${index}`}>
                {index > 0 ? <Divider /> : null}
                <ClassRow portalClass={portalClass} />
              </View>
            ))}
          </Panel>
        </Section>
      ))}
    </Screen>
  );
}

function ClassRow({ portalClass }: { portalClass: PortalClass }) {
  const theme = useTheme();
  const router = useRouter();
  const params = portalClass.join_link?.params;
  const when = joinMeta(timeRange(portalClass.schedule), portalClass.class_room_info?.room, portalClass.class_room_info?.class_name);
  const who = joinMeta(portalClass.course_info?.code, portalClass.lecturer_info?.name);

  const content = (
    <>
      <CourseTile name={portalClass.course_info?.name} code={portalClass.course_info?.code} size={TILE_SIZE} />
      <View style={styles.rowText}>
        <ThemedText type="bodyMedium" numberOfLines={2}>
          {portalClass.course_info?.name ?? 'Mata kuliah'}
        </ThemedText>
        {when ? (
          <ThemedText type="small" themeColor="textSecondary" numberOfLines={1}>
            {when}
          </ThemedText>
        ) : null}
        {who ? (
          <ThemedText type="small" themeColor="textTertiary" numberOfLines={1}>
            {who}
          </ThemedText>
        ) : null}
      </View>
      {params?.id ? <Icon name="chevronRight" size={14} color={theme.textQuaternary} style={styles.chevron} /> : null}
    </>
  );

  if (!params?.id) {
    return <View style={styles.row}>{content}</View>;
  }

  return (
    <Pressable
      accessibilityRole="button"
      accessibilityHint="Buka detail kelas"
      onPress={() =>
        router.push({
          pathname: '/class/[id]',
          params: { id: params.id as string, ...(params.dosen_id ? { dosen_id: params.dosen_id } : {}), ...(params.course_id ? { course_id: params.course_id } : {}) },
        })
      }
      style={({ pressed }) => [styles.row, pressed && { backgroundColor: theme.section }]}>
      {content}
    </Pressable>
  );
}

const styles = StyleSheet.create({
  shortcuts: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: Spacing.two,
    paddingHorizontal: Spacing.one,
  },
  filters: {
    gap: Spacing.two + Spacing.one,
    padding: Spacing.two,
  },
  chips: {
    gap: Spacing.two,
    paddingHorizontal: Spacing.one,
    paddingBottom: Spacing.one,
  },
  count: {
    paddingRight: Spacing.two,
  },
  row: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: Spacing.three - Spacing.one,
    paddingHorizontal: Spacing.three,
    paddingVertical: Spacing.three - Spacing.one,
  },
  rowText: {
    flex: 1,
    gap: Spacing.half,
  },
  chevron: {
    alignSelf: 'center',
  },
});
