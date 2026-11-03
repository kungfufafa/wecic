import { useRouter } from 'expo-router';
import { Fragment, useState } from 'react';
import { ScrollView, StyleSheet } from 'react-native';

import type { ClassesResponse } from '@/api/types';
import type { NotebookCreateResponse, NotebookItem, NotebooksResponse } from '@/api/types/classes';
import { useSession } from '@/auth/session-provider';
import { Button } from '@/components/button';
import { Chip } from '@/components/chip';
import { EmptyState } from '@/components/empty-state';
import { ListRow, ListRowTextInset } from '@/components/list-row';
import { Notice } from '@/components/notice';
import { Screen } from '@/components/screen';
import { Divider, Panel, Section } from '@/components/section';
import { SkeletonRows } from '@/components/skeleton';
import { TextField } from '@/components/text-field';
import { ThemedText } from '@/components/themed-text';
import { Spacing } from '@/constants/theme';
import { useApiResource } from '@/hooks/use-api-resource';
import { aiStatusMessage, coursesWithoutNotebook } from '@/lib/classes';

export default function NotebooksScreen() {
  const router = useRouter();
  const { api } = useSession();
  const notebooks = useApiResource<NotebooksResponse>('notebooks');
  const classes = useApiResource<ClassesResponse>('class');
  const [title, setTitle] = useState('');
  const [busy, setBusy] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const items = notebooks.data?.data.notebooks ?? [];
  const courseNotebooks = items.filter((notebook) => notebook.kind === 'course');
  const freeNotebooks = items.filter((notebook) => notebook.kind !== 'course');
  const missingCourses = notebooks.data?.data.course_notebooks === false ? [] : coursesWithoutNotebook(classes.data?.classes ?? [], items);
  const aiNotice = aiStatusMessage(notebooks.data?.data.ai_available, notebooks.data?.data.ai_status);

  async function create(key: string, request: () => Promise<NotebookCreateResponse>) {
    setBusy(key);
    setActionError(null);

    try {
      const response = await request();
      setTitle('');
      router.push({ pathname: '/notebooks/[id]', params: { id: String(response.data.notebook.id) } });
    } catch (error) {
      setActionError(error instanceof Error ? error.message : String(error));
    } finally {
      setBusy(null);
    }
  }

  return (
    <Screen
      back
      title="Notebook AI"
      subtitle="Tanya jawab dan ringkasan dari materi kuliahmu"
      refreshing={notebooks.isRefreshing}
      dataSavedAt={notebooks.savedAt}
      onRefresh={() => {
        void notebooks.refresh();
        void classes.reload();
      }}>
      {aiNotice ? <Notice tone="warning" message={aiNotice} /> : null}
      {notebooks.error ? <Notice tone="error" message={notebooks.error} /> : null}
      {actionError ? <Notice tone="error" message={actionError} /> : null}

      {notebooks.isLoading ? (
        <Section label="Memuat notebook">
          <Panel padded={false}>
            <SkeletonRows count={3} />
          </Panel>
        </Section>
      ) : null}

      {!notebooks.isLoading && courseNotebooks.length > 0 ? <NotebookSection label="Mata kuliah" notebooks={courseNotebooks} /> : null}
      {!notebooks.isLoading && freeNotebooks.length > 0 ? <NotebookSection label="Notebook pribadi" notebooks={freeNotebooks} /> : null}

      {!notebooks.isLoading && items.length === 0 && !notebooks.error ? (
        <Section>
          <Panel>
            <EmptyState icon="sparkles" title="Belum ada notebook" description="Buat notebook untuk mata kuliah, lalu masukkan materi pertemuan sebagai sumber." />
          </Panel>
        </Section>
      ) : null}

      {missingCourses.length > 0 ? (
        <Section label="Buka notebook mata kuliah">
          <Panel style={styles.chipsPanel}>
            <ThemedText type="small" themeColor="textTertiary" style={styles.hint}>
              Mata kuliah ini belum punya notebook.
            </ThemedText>
            <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.chips}>
              {missingCourses.map((portalClass) => {
                const key = portalClass.join_link?.params?.course_id as string;

                return (
                  <Chip
                    key={key}
                    icon="sparkles"
                    label={busy === `course-${key}` ? 'Membuka…' : (portalClass.course_info?.name ?? 'Mata kuliah')}
                    onPress={() => void create(`course-${key}`, () => api.post<NotebookCreateResponse>('notebooks/course', { course_key: key }))}
                  />
                );
              })}
            </ScrollView>
          </Panel>
        </Section>
      ) : null}

      <Section label="Notebook baru">
        <Panel style={styles.form}>
          <TextField placeholder="Judul, misalnya Persiapan UTS" value={title} onChangeText={setTitle} maxLength={120} returnKeyType="done" />
          <Button
            variant="secondary"
            icon="sparkles"
            label="Buat notebook"
            loading={busy === 'free'}
            disabled={title.trim() === ''}
            onPress={() => void create('free', () => api.post<NotebookCreateResponse>('notebooks', { title: title.trim() }))}
          />
        </Panel>
      </Section>
    </Screen>
  );
}

function NotebookSection({ label, notebooks }: { label: string; notebooks: NotebookItem[] }) {
  const router = useRouter();

  return (
    <Section label={label} accessory={<ThemedText type="small" themeColor="textTertiary" style={styles.count}>{`${notebooks.length} notebook`}</ThemedText>}>
      <Panel padded={false}>
        {notebooks.map((notebook, index) => (
          <Fragment key={notebook.id}>
            {index > 0 ? <Divider inset={ListRowTextInset} /> : null}
            <ListRow
              icon={notebook.kind === 'course' ? 'bookOutline' : 'sparkles'}
              label={notebook.title}
              value={[`${notebook.sources_count ?? 0} sumber`, `${notebook.messages_count ?? 0} pesan`, notebook.lecturer_name].filter(Boolean).join(' · ')}
              onPress={() => router.push({ pathname: '/notebooks/[id]', params: { id: String(notebook.id) } })}
            />
          </Fragment>
        ))}
      </Panel>
    </Section>
  );
}

const styles = StyleSheet.create({
  count: {
    paddingRight: Spacing.two,
  },
  chipsPanel: {
    gap: Spacing.two + Spacing.one,
    paddingHorizontal: Spacing.two,
  },
  hint: {
    paddingHorizontal: Spacing.two,
  },
  chips: {
    gap: Spacing.two,
    paddingHorizontal: Spacing.one,
  },
  form: {
    gap: Spacing.three - Spacing.one,
    padding: Spacing.two,
  },
});

