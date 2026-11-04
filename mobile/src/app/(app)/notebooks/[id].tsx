import { useLocalSearchParams, useRouter } from 'expo-router';
import { Fragment, useState } from 'react';
import { ActivityIndicator, Alert, Platform, ScrollView, StyleSheet, View } from 'react-native';

import type { ApiEnvelope } from '@/api/types';
import type { NotebookDetailResponse, NotebookMeeting, NotebookMessage, NotebookSource } from '@/api/types/classes';
import { useSession } from '@/auth/session-provider';
import { Button } from '@/components/button';
import { Chip } from '@/components/chip';
import { StudioResult, plainText } from '@/components/classes/studio-result';
import { EmptyState } from '@/components/empty-state';
import { IconButton } from '@/components/icon-button';
import { Notice } from '@/components/notice';
import { Screen } from '@/components/screen';
import { Divider, Panel, Section } from '@/components/section';
import { SkeletonRows } from '@/components/skeleton';
import { StatusPill } from '@/components/status-pill';
import { TextField } from '@/components/text-field';
import { ThemedText } from '@/components/themed-text';
import { Radius, Spacing } from '@/constants/theme';
import { useApiResource } from '@/hooks/use-api-resource';
import { useTheme } from '@/hooks/use-theme';
import { aiStatusMessage, sourceStatus } from '@/lib/classes';
import { formatDateTime } from '@/lib/format';

export default function NotebookScreen() {
  const router = useRouter();
  const { api } = useSession();
  const { id } = useLocalSearchParams<{ id: string }>();
  const detail = useApiResource<NotebookDetailResponse>(id ? `notebooks/${encodeURIComponent(id)}` : null);
  const [question, setQuestion] = useState('');
  const [asking, setAsking] = useState<string | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const data = detail.data?.data;
  const notebook = data?.notebook;
  const sources = data?.sources ?? [];
  const messages = [...(data?.messages ?? [])].sort((a, b) => a.id - b.id);
  const chat = messages.filter((message) => message.kind === 'chat');
  const studio = messages.filter((message) => message.kind !== 'chat' && message.role !== 'user').reverse();
  const studioKinds = data?.studio_kinds ?? {};
  const meetings = data?.meetings?.meetings ?? [];
  const aiAvailable = data?.ai_available !== false;
  const aiNotice = aiStatusMessage(data?.ai_available, data?.ai_status);
  const hasReadySource = sources.some((source) => source.status === 'ready');
  const isCourse = notebook?.kind === 'course';

  async function run(key: string, action: () => Promise<unknown>) {
    setBusy(key);
    setActionError(null);

    try {
      await action();
      await detail.reload();
    } catch (error) {
      setActionError(error instanceof Error ? error.message : String(error));
    } finally {
      setBusy(null);
    }
  }

  async function ask() {
    const text = question.trim();

    if (text.length < 2 || !id) {
      return;
    }

    setAsking(text);
    setQuestion('');
    setActionError(null);

    try {
      await api.post(`notebooks/${encodeURIComponent(id)}/messages`, { question: text }, { timeoutMs: 120_000 });
      await detail.reload();
    } catch (error) {
      setQuestion(text);
      setActionError(error instanceof Error ? error.message : String(error));
    } finally {
      setAsking(null);
    }
  }

  function confirmDelete() {
    const title = isCourse ? 'Kosongkan notebook ini?' : 'Hapus notebook ini?';
    const message = isCourse
      ? 'Semua sumber, catatan, dan percakapan di notebook mata kuliah ini dihapus.'
      : 'Notebook beserta sumber dan percakapannya dihapus permanen.';
    const remove = () =>
      void run('delete', async () => {
        await api.delete<ApiEnvelope>(`notebooks/${encodeURIComponent(id ?? '')}`);

        if (!isCourse) {
          router.back();
        }
      });

    if (Platform.OS === 'web') {
      if (window.confirm(`${title}\n${message}`)) {
        remove();
      }

      return;
    }

    Alert.alert(title, message, [
      { text: 'Batal', style: 'cancel' },
      { text: isCourse ? 'Kosongkan' : 'Hapus', style: 'destructive', onPress: remove },
    ]);
  }

  return (
    <Screen
      back
      title={notebook?.title ?? 'Notebook'}
      subtitle={notebook ? [notebook.course_code, `${notebook.sources_count ?? sources.length} sumber`, notebook.lecturer_name].filter(Boolean).join(' · ') : null}
      accessory={notebook ? <IconButton icon="trash" label={isCourse ? 'Kosongkan notebook' : 'Hapus notebook'} size={44} onPress={confirmDelete} /> : null}
      refreshing={detail.isRefreshing}
      dataSavedAt={detail.savedAt}
      onRefresh={detail.reload}>
      {aiNotice ? <Notice tone="warning" message={aiNotice} /> : null}
      {detail.error ? <Notice tone="error" message={detail.error} /> : null}
      {actionError ? <Notice tone="error" message={actionError} /> : null}

      {detail.isLoading ? (
        <Section label="Memuat notebook">
          <Panel padded={false}>
            <SkeletonRows count={3} />
          </Panel>
        </Section>
      ) : null}

      {notebook ? (
        <>
          <Section label="Tanya jawab">
            <Panel style={styles.chat}>
              {chat.length === 0 && !asking ? (
                <ThemedText type="small" themeColor="textTertiary">
                  {hasReadySource
                    ? 'Jawaban hanya diambil dari sumber notebook ini.'
                    : 'Belum ada sumber yang siap.'}
                </ThemedText>
              ) : null}
              {chat.map((message) => (
                <ChatBubble key={message.id} message={message} />
              ))}
              {asking ? (
                <>
                  <ChatBubble message={{ id: -1, role: 'user', kind: 'chat', content: asking }} />
                  <View style={styles.thinking}>
                    <ActivityIndicator size="small" />
                    <ThemedText type="small" themeColor="textTertiary">
                      Menyusun jawaban…
                    </ThemedText>
                  </View>
                </>
              ) : null}
            </Panel>
            <Panel style={styles.ask}>
              <TextField
                placeholder={aiAvailable ? 'Tulis pertanyaan…' : 'Asisten AI belum aktif'}
                value={question}
                onChangeText={setQuestion}
                editable={aiAvailable && asking === null}
                multiline
                maxLength={2000}
                style={styles.askInput}
              />
              <Button
                icon="send"
                label="Tanya"
                loading={asking !== null}
                disabled={!aiAvailable || !hasReadySource || question.trim().length < 2}
                onPress={() => void ask()}
              />
            </Panel>
          </Section>

          <Section label="Studio">
            <Panel style={styles.studioPanel}>
              <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.chips}>
                {Object.entries(studioKinds).map(([kind, label]) => (
                  <Chip
                    key={kind}
                    icon="sparkles"
                    label={busy === `studio-${kind}` ? 'Membuat…' : label}
                    onPress={
                      aiAvailable && hasReadySource && busy === null
                        ? () => void run(`studio-${kind}`, () => api.post(`notebooks/${encodeURIComponent(id ?? '')}/studio`, { kind }, { timeoutMs: 180_000 }))
                        : undefined
                    }
                  />
                ))}
              </ScrollView>
            </Panel>
            {studio.map((message) => (
              <Panel key={message.id} style={styles.result}>
                <View style={styles.resultHead}>
                  <ThemedText type="smallBold" style={styles.flex}>
                    {studioKinds[message.kind] ?? message.kind}
                  </ThemedText>
                  <ThemedText type="caption" themeColor="textQuaternary">
                    {formatDateTime(message.created_at)}
                  </ThemedText>
                </View>
                <StudioResult message={message} />
              </Panel>
            ))}
          </Section>

          <Section
            label="Sumber"
            accessory={
              <ThemedText type="small" themeColor="textTertiary" style={styles.count}>
                {data?.source_limit ? `${sources.length}/${data.source_limit}` : `${sources.length}`}
              </ThemedText>
            }>
            <Panel padded={false}>
              {sources.length === 0 ? (
                <EmptyState
                  icon="document"
                  title="Belum ada sumber"
                  description={isCourse && meetings.length > 0 ? 'Tambahkan dari daftar pertemuan.' : undefined}
                />
              ) : null}
              {sources.map((source, index) => (
                <Fragment key={source.id}>
                  {index > 0 ? <Divider /> : null}
                  <SourceRow source={source} />
                </Fragment>
              ))}
            </Panel>
          </Section>

          {isCourse && meetings.length > 0 ? (
            <Section label="Pertemuan">
              <Panel padded={false}>
                {meetings.map((meeting, index) => (
                  <Fragment key={meeting.meeting_id}>
                    {index > 0 ? <Divider /> : null}
                    <MeetingRow
                      meeting={meeting}
                      busy={busy === `meeting-${meeting.meeting_id}`}
                      onAdd={() =>
                        void run(`meeting-${meeting.meeting_id}`, () =>
                          api.post('notebooks/meetings', { meeting_id: meeting.meeting_id, courses_id: meeting.courses_id ?? notebook.course_key }, { timeoutMs: 120_000 }),
                        )
                      }
                    />
                  </Fragment>
                ))}
              </Panel>
            </Section>
          ) : null}
        </>
      ) : null}
    </Screen>
  );
}

function ChatBubble({ message }: { message: NotebookMessage }) {
  const theme = useTheme();
  const mine = message.role === 'user';

  return (
    <View style={[styles.bubble, mine ? [styles.mine, { backgroundColor: theme.selected }] : { backgroundColor: theme.elevated }]}>
      <ThemedText type="small" themeColor={mine ? 'text' : 'textSecondary'} selectable>
        {mine ? message.content : plainText(message.content)}
      </ThemedText>
    </View>
  );
}

function SourceRow({ source }: { source: NotebookSource }) {
  const status = sourceStatus(source);
  const detail = [
    source.meeting_number ? `Pertemuan ${source.meeting_number}` : null,
    source.page_count ? `${source.page_count} halaman` : null,
    source.char_count ? `${source.char_count.toLocaleString('id-ID')} karakter` : null,
  ]
    .filter(Boolean)
    .join(' · ');

  return (
    <View style={styles.row}>
      <View style={styles.flex}>
        <ThemedText type="bodyMedium" numberOfLines={2}>
          {source.title ?? 'Sumber'}
        </ThemedText>
        {detail ? (
          <ThemedText type="small" themeColor="textTertiary" numberOfLines={1}>
            {detail}
          </ThemedText>
        ) : null}
        {source.error ? (
          <ThemedText type="small" themeColor="danger" numberOfLines={2}>
            {source.error}
          </ThemedText>
        ) : null}
      </View>
      <StatusPill label={status.label} tone={status.tone} />
    </View>
  );
}

function MeetingRow({ meeting, busy, onAdd }: { meeting: NotebookMeeting; busy: boolean; onAdd: () => void }) {
  return (
    <View style={styles.row}>
      <View style={styles.flex}>
        <ThemedText type="bodyMedium" numberOfLines={2}>
          {meeting.title ?? `Pertemuan ${meeting.meeting_number ?? ''}`}
        </ThemedText>
        <ThemedText type="small" themeColor="textTertiary" numberOfLines={1}>
          {[meeting.meeting_number ? `Pertemuan ${meeting.meeting_number}` : null, meeting.date].filter(Boolean).join(' · ')}
        </ThemedText>
      </View>
      {meeting.added ? (
        <StatusPill label="Sudah masuk" tone="positive" />
      ) : (
        <Button size="small" variant="secondary" icon="download" label="Tambah" loading={busy} onPress={onAdd} />
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  flex: {
    flex: 1,
    gap: Spacing.half,
  },
  count: {
    paddingRight: Spacing.two,
  },
  chat: {
    gap: Spacing.two,
  },
  bubble: {
    maxWidth: '88%',
    alignSelf: 'flex-start',
    paddingHorizontal: Spacing.three - Spacing.one,
    paddingVertical: Spacing.two + Spacing.one,
    borderRadius: Radius.large,
  },
  mine: {
    alignSelf: 'flex-end',
  },
  thinking: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.two,
  },
  ask: {
    gap: Spacing.two,
    padding: Spacing.two,
  },
  askInput: {
    paddingVertical: Spacing.three - Spacing.one,
    maxHeight: 140,
  },
  studioPanel: {
    paddingHorizontal: Spacing.two,
    paddingVertical: Spacing.two + Spacing.one,
  },
  chips: {
    gap: Spacing.two,
    paddingHorizontal: Spacing.one,
  },
  result: {
    gap: Spacing.two,
  },
  resultHead: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.two,
  },
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.three - Spacing.one,
    paddingHorizontal: Spacing.three + Spacing.half,
    paddingVertical: Spacing.two + Spacing.one,
  },
});
