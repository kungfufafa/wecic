import { useFocusEffect, useLocalSearchParams, useRouter } from 'expo-router';
import { Fragment, useCallback, useEffect, useEffectEvent, useRef, useState } from 'react';
import { ActivityIndicator, Alert, AppState, Platform, ScrollView, StyleSheet, View } from 'react-native';

import type { ApiEnvelope } from '@/api/types';
import type { NotebookAskMode, NotebookDetailResponse, NotebookMeeting, NotebookMessage, NotebookSource, NotebookStudioOptions } from '@/api/types/classes';
import { useSession } from '@/auth/session-provider';
import { Button } from '@/components/button';
import { Chip } from '@/components/chip';
import { AddSourceSheet } from '@/components/classes/add-source-sheet';
import { AssignmentBriefSheet } from '@/components/classes/assignment-brief-sheet';
import { StudioOptionsSheet } from '@/components/classes/studio-options-sheet';
import { CitationText } from '@/components/classes/citation-text';
import { StudioResult } from '@/components/classes/studio-result';
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
import { useAiConsent } from '@/hooks/use-ai-consent';
import { useApiResource } from '@/hooks/use-api-resource';
import { useSourceSelection } from '@/hooks/use-source-selection';
import { useTheme } from '@/hooks/use-theme';
import { aiProviderLabel, isAiConsentRequired, rememberAiConsentFrom } from '@/lib/ai-consent';
import { aiStatusMessage, askModeLabel, askModeOf, NotebookAskModes, practiceCount, sourceStatus } from '@/lib/classes';
import { formatDateTime } from '@/lib/format';
import {
  buildUploadForm,
  canRetrySource,
  contextSourceIds,
  noContextChosen,
  pollDelay,
  pruneExcluded,
  readingKey,
  retryLabel,
  showsSourceSelection,
} from '@/lib/notebook-sources';
import type { PickedFile } from '@/lib/pick-file';

/** Studio tools that ask how many and how hard first (StudioOptionsSheet). */
const OPTION_KINDS = ['quiz', 'flashcards'];

/** The studio tool that asks for the lecturer's brief first (AssignmentBriefSheet). */
const BRIEF_KIND = 'assignment_plan';

export default function NotebookScreen() {
  const router = useRouter();
  const { api } = useSession();
  const { id } = useLocalSearchParams<{ id: string }>();
  const detail = useApiResource<NotebookDetailResponse>(id ? `notebooks/${encodeURIComponent(id)}` : null);
  const [question, setQuestion] = useState('');
  const [askMode, setAskMode] = useState<NotebookAskMode>('answer');
  const [asking, setAsking] = useState<string | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const [optionsKind, setOptionsKind] = useState<string | null>(null);
  const [briefOpen, setBriefOpen] = useState(false);
  const [addOpen, setAddOpen] = useState(false);
  const [addBusy, setAddBusy] = useState<'upload' | 'text' | null>(null);
  const [addError, setAddError] = useState<string | null>(null);
  const [excluded, setExcluded] = useSourceSelection(id);
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
  const readySources = sources.filter((source) => source.status === 'ready');
  const hasReadySource = readySources.length > 0;
  const chosenIds = contextSourceIds(sources, excluded);
  const chosenCount = excluded.size === 0 ? readySources.length : chosenIds.length;
  const nothingChosen = noContextChosen(sources, excluded);
  const room = data?.source_limit ? Math.max(0, data.source_limit - sources.length) : null;
  const isCourse = notebook?.kind === 'course';
  const { consent } = useAiConsent();
  const needsConsent = aiAvailable && consent !== null && !consent.granted;

  useEffect(() => {
    rememberAiConsentFrom(detail.data);
  }, [detail.data]);

  // A deleted source is forgotten from the selection, as on the web.
  const sourceIds = sources.map((source) => source.id).join(',');
  const forgetDeleted = useEffectEvent(() => {
    const kept = pruneExcluded(excluded, sources);

    if (kept.size !== excluded.size) {
      setExcluded(kept);
    }
  });

  useEffect(() => {
    if (data) {
      forgetDeleted();
    }
  }, [data, sourceIds, excluded]);

  // While a source is read: ask again every 4 s, every 20 s after two minutes without progress, and stop
  // after an hour (the web's POLL_* constants); only while this screen is shown and the app is in front.
  const reading = readingKey(sources);
  const pollWindow = useRef({ key: '', startedAt: 0 });
  const [onScreen, setOnScreen] = useState(true);
  const [appActive, setAppActive] = useState(AppState.currentState === 'active');
  const [pollTick, setPollTick] = useState(0);
  const pollOnce = useEffectEvent(async () => {
    await detail.reload();
    setPollTick((tick) => tick + 1);
  });

  useFocusEffect(
    useCallback(() => {
      setOnScreen(true);

      return () => setOnScreen(false);
    }, []),
  );

  useEffect(() => {
    const subscription = AppState.addEventListener('change', (status) => setAppActive(status === 'active'));

    return () => subscription.remove();
  }, []);

  useEffect(() => {
    // Any progress (a source added, read, or read again) starts a fresh polling window.
    if (reading !== pollWindow.current.key) {
      pollWindow.current = { key: reading, startedAt: Date.now() };
    }

    if (!onScreen || !appActive) {
      return;
    }

    const delay = pollDelay(Date.now() - pollWindow.current.startedAt, reading !== '');

    if (delay === null) {
      return;
    }

    const timer = setTimeout(() => void pollOnce(), delay);

    return () => clearTimeout(timer);
  }, [reading, onScreen, appActive, pollTick]);

  function openConsent() {
    router.push('/ai-consent');
  }

  /** A refusal for lack of consent shows the consent screen instead of an error. */
  function showError(error: unknown) {
    if (isAiConsentRequired(error)) {
      rememberAiConsentFrom(error);
      openConsent();

      return;
    }

    setActionError(error instanceof Error ? error.message : String(error));
  }

  async function run(key: string, action: () => Promise<unknown>) {
    setBusy(key);
    setActionError(null);

    try {
      await action();
      await detail.reload();
    } catch (error) {
      showError(error);
    } finally {
      setBusy(null);
    }
  }

  /** `source_ids` of the chosen ready sources, only when the student left some out (none means all, as on the web). */
  function withContext<T extends object>(body: T): T & { source_ids?: number[] } {
    return chosenIds.length > 0 ? { ...body, source_ids: chosenIds } : body;
  }

  function toggleSource(sourceId: number) {
    const next = new Set(excluded);

    if (next.has(sourceId)) {
      next.delete(sourceId);
    } else {
      next.add(sourceId);
    }

    setExcluded(next);
  }

  function openAddSource() {
    setAddError(null);
    setAddOpen(true);
  }

  /**
   * One source from the "+ Sumber" sheet. The server's refusal (a format
   * or size, or a file the configured AI model cannot read) is shown in the
   * sheet as it came; a refusal for lack of consent opens the consent screen.
   */
  async function addSource(kind: 'upload' | 'text', body: FormData | { type: 'text'; title: string; content: string }) {
    if (kind === 'upload' && needsConsent) {
      setAddOpen(false);
      openConsent();

      return;
    }

    setAddBusy(kind);
    setAddError(null);

    try {
      await api.post(`notebooks/${encodeURIComponent(id ?? '')}/sources`, body, { timeoutMs: 180_000 });
      setAddOpen(false);
      await detail.reload();
    } catch (error) {
      if (isAiConsentRequired(error)) {
        setAddOpen(false);
        showError(error);
      } else {
        setAddError(error instanceof Error ? error.message : String(error));
      }
    } finally {
      setAddBusy(null);
    }
  }

  function retrySource(source: NotebookSource) {
    if (needsConsent) {
      openConsent();

      return;
    }

    void run(`retry-${source.id}`, () => api.post(`notebooks/${encodeURIComponent(id ?? '')}/sources/${source.id}/retry`));
  }

  function confirmDeleteSource(source: NotebookSource) {
    const title = 'Hapus sumber ini?';
    const message = `${source.title ?? 'Sumber'} dihapus dari notebook. Rujukan ke sumber ini tidak bisa dibuka lagi.`;
    const remove = () => void run(`delete-source-${source.id}`, () => api.delete<ApiEnvelope>(`notebooks/${encodeURIComponent(id ?? '')}/sources/${source.id}`));

    if (Platform.OS === 'web') {
      if (window.confirm(`${title}\n${message}`)) {
        remove();
      }

      return;
    }

    Alert.alert(title, message, [
      { text: 'Batal', style: 'cancel' },
      { text: 'Hapus', style: 'destructive', onPress: remove },
    ]);
  }

  async function ask() {
    const text = question.trim();

    if (text.length < 2 || !id) {
      return;
    }

    if (needsConsent) {
      openConsent();

      return;
    }

    setAsking(text);
    setQuestion('');
    setActionError(null);

    try {
      await api.post(`notebooks/${encodeURIComponent(id)}/messages`, withContext({ question: text, mode: askMode }), { timeoutMs: 120_000 });
      await detail.reload();
    } catch (error) {
      setQuestion(text);
      showError(error);
    } finally {
      setAsking(null);
    }
  }

  /** One studio request (one AI request from the daily budget), with its options or the practice it is made from. */
  function runStudio(kind: string, body: { options?: NotebookStudioOptions; practice?: { message_id: number; missed: number[] }; focus?: string | null } = {}) {
    void run(`studio-${kind}`, () => api.post(`notebooks/${encodeURIComponent(id ?? '')}/studio`, withContext({ kind, ...body }), { timeoutMs: 180_000 }));
  }

  function openStudio(kind: string) {
    if (kind === BRIEF_KIND) {
      setBriefOpen(true);
    } else if (OPTION_KINDS.includes(kind)) {
      setOptionsKind(kind);
    } else {
      runStudio(kind);
    }
  }

  /** A practice attempt is kept on the output without AI; a failed save leaves the practice as it is. */
  function savePractice(message: NotebookMessage, practice: Record<string, unknown>) {
    api.patch(`notebooks/${encodeURIComponent(id ?? '')}/messages/${message.id}`, { practice }).catch(() => {
      setActionError('Hasil latihan belum tersimpan.');
    });
  }

  const canStudio = aiAvailable && !needsConsent && hasReadySource && !nothingChosen && busy === null;

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

      {notebook && needsConsent ? (
        <Section label="Izin Notebook AI">
          <Panel style={styles.consent}>
            <ThemedText type="small" themeColor="textSecondary">
              {`Tanya jawab, Studio, dan pembacaan PDF atau gambar mengirim isi notebook ke ${aiProviderLabel(consent)}. Setujui dulu sebelum memakai AI. Tanpa izin, notebook tetap bisa dibuka dan dihapus.`}
            </ThemedText>
            <Button size="small" variant="secondary" icon="sparkles" label="Tinjau dan setujui" onPress={openConsent} />
          </Panel>
        </Section>
      ) : null}

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
                <ChatBubble key={message.id} message={message} notebookId={id} />
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
              {showsSourceSelection(sources, excluded) ? (
                <>
                  <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.chips} accessibilityLabel="Sumber yang dipakai AI">
                    <Chip label="Semua sumber" selected={excluded.size === 0} onPress={() => setExcluded(new Set())} />
                    {readySources.map((source) => (
                      <Chip key={source.id} label={source.title ?? 'Sumber'} selected={!excluded.has(source.id)} onPress={() => toggleSource(source.id)} />
                    ))}
                  </ScrollView>
                  <ThemedText type="caption" themeColor={nothingChosen ? 'danger' : 'textTertiary'}>
                    {nothingChosen ? 'Pilih minimal satu sumber untuk ditanyakan.' : `Konteks: ${chosenCount} dari ${readySources.length} sumber siap.`}
                  </ThemedText>
                </>
              ) : null}
              <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.chips} accessibilityLabel="Cara menjawab">
                {NotebookAskModes.map((item) => (
                  <Chip key={item.mode} label={item.label} selected={askMode === item.mode} onPress={() => setAskMode(item.mode)} />
                ))}
              </ScrollView>
              {askMode === 'tutor' ? (
                <ThemedText type="caption" themeColor="textTertiary">
                  Mode tutor menuntunmu selangkah demi selangkah dengan pertanyaan dan petunjuk, tanpa mengerjakan tugasmu.
                </ThemedText>
              ) : null}
              <TextField
                placeholder={aiAvailable ? (askMode === 'tutor' ? 'Ceritakan bagian yang ingin kamu pahami…' : 'Tulis pertanyaan…') : 'Asisten AI belum aktif'}
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
                disabled={!aiAvailable || !hasReadySource || nothingChosen || question.trim().length < 2}
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
                      aiAvailable && needsConsent
                        ? openConsent
                        : aiAvailable && hasReadySource && !nothingChosen && busy === null
                        ? () => openStudio(kind)
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
                <StudioResult
                  message={message}
                  notebookId={id}
                  budget={data?.ai_budget}
                  canMakePractice={canStudio}
                  onSave={(practice) => savePractice(message, practice)}
                  onPracticeFromMissed={(missed) =>
                    runStudio('quiz', { practice: { message_id: message.id, missed }, options: { count: practiceCount(missed) } })
                  }
                />
              </Panel>
            ))}
          </Section>

          <Section
            label="Sumber"
            accessory={
              <View style={styles.sourceAccessory}>
                <ThemedText type="small" themeColor="textTertiary">
                  {data?.source_limit ? `${sources.length}/${data.source_limit}` : `${sources.length}`}
                </ThemedText>
                <Button size="small" variant="secondary" label="+ Sumber" accessibilityLabel="Tambah sumber" hitSlop={4} onPress={openAddSource} />
              </View>
            }>
            <Panel padded={false}>
              {sources.length === 0 ? (
                <EmptyState
                  icon="document"
                  title="Belum ada sumber"
                  description={isCourse && meetings.length > 0 ? 'Tambahkan dari daftar pertemuan, atau ketuk + Sumber.' : 'Ketuk + Sumber untuk menambah foto, berkas, atau catatan.'}
                />
              ) : null}
              {sources.map((source, index) => (
                <Fragment key={source.id}>
                  {index > 0 ? <Divider /> : null}
                  <SourceRow
                    source={source}
                    canRetry={canRetrySource(source, aiAvailable)}
                    retrying={busy === `retry-${source.id}`}
                    deleting={busy === `delete-source-${source.id}`}
                    disabled={busy !== null}
                    onRetry={() => retrySource(source)}
                    onDelete={() => confirmDeleteSource(source)}
                  />
                </Fragment>
              ))}
            </Panel>
          </Section>

          <StudioOptionsSheet
            kind={optionsKind}
            label={optionsKind ? studioKinds[optionsKind] ?? optionsKind : ''}
            budget={data?.ai_budget}
            onClose={() => setOptionsKind(null)}
            onSubmit={(options) => {
              const kind = optionsKind;

              setOptionsKind(null);

              if (kind) {
                runStudio(kind, { options });
              }
            }}
          />

          <AssignmentBriefSheet
            visible={briefOpen}
            label={studioKinds[BRIEF_KIND] ?? 'Kerangka tugas'}
            sources={sources}
            chosenCount={chosenCount}
            readyCount={readySources.length}
            budget={data?.ai_budget}
            onClose={() => setBriefOpen(false)}
            onSubmit={(focus) => {
              setBriefOpen(false);
              runStudio(BRIEF_KIND, { focus });
            }}
          />

          <AddSourceSheet
            visible={addOpen}
            busy={addBusy}
            error={addError}
            room={room}
            onClose={() => {
              if (addBusy === null) {
                setAddOpen(false);
              }
            }}
            onUpload={(file: PickedFile) => void addSource('upload', buildUploadForm(file))}
            onText={(title, content) => void addSource('text', { type: 'text', title, content })}
          />

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

/** A question, or an answer with its citations as chips (CitationText). */
function ChatBubble({ message, notebookId }: { message: NotebookMessage; notebookId?: string }) {
  const theme = useTheme();
  const mine = message.role === 'user';

  return (
    <View style={[styles.bubble, mine ? [styles.mine, { backgroundColor: theme.selected }] : { backgroundColor: theme.elevated }]}>
      {mine ? (
        <ThemedText type="small" themeColor="text" selectable>
          {message.content}
        </ThemedText>
      ) : (
        <CitationText content={message.content} citations={message.citations} notebookId={notebookId} messageId={message.id} />
      )}
      {mine ? null : (
        <ThemedText type="caption" themeColor="textQuaternary">
          {askModeLabel(askModeOf(message.context))}
        </ThemedText>
      )}
    </View>
  );
}

type SourceRowProps = {
  source: NotebookSource;
  canRetry: boolean;
  retrying: boolean;
  deleting: boolean;
  disabled: boolean;
  onRetry: () => void;
  onDelete: () => void;
};

/** A source with its reading status, and "Baca ulang" (a failed or stalled file) and "Hapus" (after a confirm). */
function SourceRow({ source, canRetry, retrying, deleting, disabled, onRetry, onDelete }: SourceRowProps) {
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
        <View style={styles.sourceActions}>
          {canRetry ? (
            <Button size="small" variant="secondary" icon="refresh" label={retryLabel(source)} loading={retrying} disabled={disabled} hitSlop={4} onPress={onRetry} />
          ) : null}
          <Button
            size="small"
            variant="secondary"
            icon="trash"
            label="Hapus"
            accessibilityLabel={`Hapus ${source.title ?? 'sumber'}`}
            loading={deleting}
            disabled={disabled}
            hitSlop={4}
            onPress={onDelete}
          />
        </View>
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
  sourceAccessory: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.two,
    paddingRight: Spacing.two,
  },
  sourceActions: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: Spacing.two,
    paddingTop: Spacing.one,
  },
  chat: {
    gap: Spacing.two,
  },
  consent: {
    gap: Spacing.two + Spacing.half,
    alignItems: 'flex-start',
  },
  bubble: {
    gap: Spacing.one,
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
