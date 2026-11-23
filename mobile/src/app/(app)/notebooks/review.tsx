import { useRouter } from 'expo-router';
import { useState } from 'react';
import { AccessibilityInfo, StyleSheet, View } from 'react-native';

import type {
  NotebookReviewCard,
  NotebookReviewCardResponse,
  NotebookReviewGrade,
  NotebookReviewQueueResponse,
  NotebookReviewResumeResponse,
} from '@/api/types/classes';
import { useSession } from '@/auth/session-provider';
import { Button } from '@/components/button';
import { EmptyState } from '@/components/empty-state';
import { Notice } from '@/components/notice';
import { Screen } from '@/components/screen';
import { Panel, Section } from '@/components/section';
import { SkeletonRows } from '@/components/skeleton';
import { StatusPill } from '@/components/status-pill';
import { TextField } from '@/components/text-field';
import { ThemedText } from '@/components/themed-text';
import { Spacing } from '@/constants/theme';
import { useApiResource } from '@/hooks/use-api-resource';
import {
  formatReviewInterval,
  REVIEW_GRADES,
  reviewAfterCard,
  reviewCardMeta,
  reviewFinishedCopy,
  reviewProgressLine,
  reviewQueue,
} from '@/lib/classes';

const QUEUE_PATH = 'notebooks/review?limit=50';
const SIDE_MAX_CHARS = 2000;

/**
 * Ulasan harian: the cards saved from flashcards and missed quiz questions of every notebook, one at a time. The
 * answer shows on request, then Lagi, Sulit, Bisa or Mudah grades it and the server schedules it (no AI, no budget).
 * A card graded or suspended here leaves this sitting's queue for good (reviewAfterCard); the queue is read again
 * when it runs out. A held card can be brought back at once (Batalkan), and the finished screen brings back every
 * held card (POST notebooks/review/resume).
 */
export default function NotebookReviewScreen() {
  const router = useRouter();
  const { api } = useSession();
  const queue = useApiResource<NotebookReviewQueueResponse>(QUEUE_PATH);
  const [done, setDone] = useState<number[]>([]);
  const [edited, setEdited] = useState<Record<number, NotebookReviewCard>>({});
  const [revealed, setRevealed] = useState(false);
  const [editing, setEditing] = useState(false);
  const [draft, setDraft] = useState({ front: '', back: '' });
  const [busy, setBusy] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const [held, setHeld] = useState<NotebookReviewCard | null>(null);
  const [sittingOf, setSittingOf] = useState(queue.data);
  const cards = reviewQueue(queue.data?.data.cards, done).map((card) => edited[card.id] ?? card);
  const current = cards[0] ?? null;
  const finished = reviewFinishedCopy(queue.data?.data.counts, queue.data?.data.next_due_at);

  // A fresh queue from the server starts this sitting over (adjusted while rendering, not in an effect).
  if (sittingOf !== queue.data) {
    setSittingOf(queue.data);
    setDone([]);
    setEdited({});
    setRevealed(false);
    setEditing(false);
  }

  async function grade(card: NotebookReviewCard, value: NotebookReviewGrade) {
    setBusy(`grade-${value}`);
    setActionError(null);

    try {
      await api.post<NotebookReviewCardResponse>(`notebooks/review/${card.id}`, { grade: value });
      const label = REVIEW_GRADES.find((item) => item.grade === value)?.label ?? value;
      AccessibilityInfo.announceForAccessibility(`${label}. Muncul lagi dalam ${formatReviewInterval(card.next?.[value])}.`);
      setRevealed(false);
      setHeld(null);
      await leave(card);
    } catch (error) {
      setActionError(error instanceof Error ? error.message : String(error));
    } finally {
      setBusy(null);
    }
  }

  // The card leaves this sitting before the queue is read again, so a failed read never shows it to be graded twice.
  async function leave(card: NotebookReviewCard) {
    const next = reviewAfterCard(cards, done, card.id);
    setDone(next.done);

    if (next.reload) {
      await queue.reload();
    }
  }

  async function update(card: NotebookReviewCard, changes: Partial<Pick<NotebookReviewCard, 'front' | 'back' | 'suspended'>>) {
    setBusy(changes.suspended ? 'suspend' : 'edit');
    setActionError(null);

    try {
      const response = await api.patch<NotebookReviewCardResponse>(`notebooks/review/${card.id}`, changes);

      if (changes.suspended) {
        AccessibilityInfo.announceForAccessibility('Kartu ditahan dan tidak muncul di ulasan. Pilih Batalkan untuk mengembalikannya.');
        setRevealed(false);
        setHeld(card);
        await leave(card);
      } else {
        setEdited((previous) => ({ ...previous, [card.id]: { ...card, ...response.data.card } }));
        setEditing(false);
      }
    } catch (error) {
      setActionError(error instanceof Error ? error.message : String(error));
    } finally {
      setBusy(null);
    }
  }

  // Batalkan: the card just held is active again, back in its place in this sitting (or in the queue read again).
  async function undoHold(card: NotebookReviewCard) {
    setBusy('undo');
    setActionError(null);

    try {
      await api.patch<NotebookReviewCardResponse>(`notebooks/review/${card.id}`, { suspended: false });
      setHeld(null);
      AccessibilityInfo.announceForAccessibility('Kartu kembali ke ulasan.');

      if (queue.data?.data.cards.some((item) => item.id === card.id)) {
        setDone((previous) => previous.filter((id) => id !== card.id));
      } else {
        await queue.reload();
      }
    } catch (error) {
      setActionError(error instanceof Error ? error.message : String(error));
    } finally {
      setBusy(null);
    }
  }

  async function resumeHeld() {
    setBusy('resume');
    setActionError(null);

    try {
      const response = await api.post<NotebookReviewResumeResponse>('notebooks/review/resume', {});
      setHeld(null);
      AccessibilityInfo.announceForAccessibility(response.message || 'Kartu kembali ke ulasan.');
      await queue.reload();
    } catch (error) {
      setActionError(error instanceof Error ? error.message : String(error));
    } finally {
      setBusy(null);
    }
  }

  function startEditing(card: NotebookReviewCard) {
    setDraft({ front: card.front, back: card.back });
    setEditing(true);
  }

  function reveal(card: NotebookReviewCard) {
    setRevealed(true);
    AccessibilityInfo.announceForAccessibility(`Jawaban: ${card.back}`);
  }

  return (
    <Screen back title="Ulasan harian" refreshing={queue.isRefreshing} onRefresh={() => void queue.reload()}>
      {queue.error ? <Notice tone="error" message={queue.error} /> : null}
      {actionError ? <Notice tone="error" message={actionError} /> : null}

      {queue.isLoading && !queue.data ? (
        <Section label="Memuat kartu">
          <Panel padded={false}>
            <SkeletonRows count={3} />
          </Panel>
        </Section>
      ) : null}

      {held ? (
        <Panel style={styles.undo}>
          <ThemedText type="small" themeColor="textSecondary" style={styles.metaText}>
            Kartu ditahan dan tidak muncul di ulasan.
          </ThemedText>
          <Button size="small" variant="secondary" label="Batalkan" loading={busy === 'undo'} disabled={busy !== null} onPress={() => void undoHold(held)} />
        </Panel>
      ) : null}

      {queue.data ? (
        <ThemedText type="small" themeColor="textSecondary" accessibilityLiveRegion="polite" style={styles.progress}>
          {reviewProgressLine(cards)}
        </ThemedText>
      ) : null}

      {current ? (
        <Section>
          <Panel style={styles.card}>
            <View style={styles.meta}>
              {current.is_new ? <StatusPill label="Baru" tone="positive" /> : null}
              {current.lapses ? <StatusPill label={`Lupa ${current.lapses}×`} tone="attention" /> : null}
              <ThemedText type="caption" themeColor="textTertiary" style={styles.metaText} numberOfLines={2}>
                {reviewCardMeta(current)}
              </ThemedText>
            </View>

            {editing ? (
              <View style={styles.gap}>
                <TextField label="Sisi depan" value={draft.front} onChangeText={(front) => setDraft((previous) => ({ ...previous, front }))} multiline maxLength={SIDE_MAX_CHARS} />
                <TextField label="Sisi belakang" value={draft.back} onChangeText={(back) => setDraft((previous) => ({ ...previous, back }))} multiline maxLength={SIDE_MAX_CHARS} />
                <View style={styles.row}>
                  <Button
                    size="small"
                    label="Simpan"
                    loading={busy === 'edit'}
                    disabled={busy !== null || draft.front.trim() === '' || draft.back.trim() === ''}
                    onPress={() => void update(current, { front: draft.front.trim(), back: draft.back.trim() })}
                  />
                  <Button size="small" variant="secondary" label="Batal" disabled={busy !== null} onPress={() => setEditing(false)} />
                </View>
              </View>
            ) : (
              <>
                <ThemedText type="subtitle" accessibilityRole="header">
                  {current.front}
                </ThemedText>

                {revealed ? (
                  <View style={styles.gap}>
                    <ThemedText themeColor="textSecondary">{current.back}</ThemedText>
                    {(current.citations ?? []).map((citation) => (
                      <ThemedText key={citation.n} type="caption" themeColor="textTertiary">
                        {`[${citation.n}] ${citation.source_title ?? 'Sumber'}${citation.page ? `, hlm. ${citation.page}` : ''}`}
                      </ThemedText>
                    ))}
                    <View style={styles.grades} accessibilityLabel="Seberapa ingat kamu?">
                      {REVIEW_GRADES.map(({ grade: value, label }) => (
                        <View key={value} style={styles.grade}>
                          <Button
                            size="small"
                            variant={value === 'bisa' ? 'primary' : 'secondary'}
                            label={`${label} · ${formatReviewInterval(current.next?.[value])}`}
                            accessibilityLabel={`${label}, muncul lagi dalam ${formatReviewInterval(current.next?.[value])}`}
                            loading={busy === `grade-${value}`}
                            disabled={busy !== null}
                            onPress={() => void grade(current, value)}
                          />
                        </View>
                      ))}
                    </View>
                  </View>
                ) : (
                  <Button label="Tampilkan jawaban" onPress={() => reveal(current)} />
                )}

                <View style={styles.row}>
                  <Button size="small" variant="secondary" label="Ubah kartu" disabled={busy !== null} onPress={() => startEditing(current)} />
                  <Button
                    size="small"
                    variant="secondary"
                    label="Tahan kartu"
                    loading={busy === 'suspend'}
                    disabled={busy !== null}
                    onPress={() => void update(current, { suspended: true })}
                  />
                  <Button
                    size="small"
                    variant="secondary"
                    label="Buka notebook"
                    onPress={() => router.push({ pathname: '/notebooks/[id]', params: { id: String(current.notebook_id) } })}
                  />
                </View>
              </>
            )}
          </Panel>
        </Section>
      ) : null}

      {queue.data && !current ? (
        <Section>
          <Panel padded={false}>
            <EmptyState
              icon="checkAll"
              title={finished.title}
              description={finished.description}
              action={
                finished.resume
                  ? {
                      label: busy === 'resume' ? 'Mengaktifkan…' : 'Aktifkan lagi kartu yang ditahan',
                      icon: 'refresh',
                      onPress: () => {
                        if (busy === null) {
                          void resumeHeld();
                        }
                      },
                    }
                  : (queue.data.data.counts?.total ?? 0) === 0
                    ? null
                    : { label: 'Muat ulang', icon: 'refresh', onPress: () => void queue.reload() }
              }
            />
          </Panel>
        </Section>
      ) : null}
    </Screen>
  );
}

const styles = StyleSheet.create({
  progress: {
    paddingHorizontal: Spacing.two,
  },
  card: {
    gap: Spacing.three,
  },
  undo: {
    alignItems: 'center',
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: Spacing.two,
    justifyContent: 'space-between',
  },
  meta: {
    alignItems: 'center',
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: Spacing.two,
  },
  metaText: {
    flexShrink: 1,
  },
  gap: {
    gap: Spacing.two,
  },
  grades: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: Spacing.two,
  },
  grade: {
    flexBasis: '47%',
    flexGrow: 1,
  },
  row: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: Spacing.two,
  },
});
