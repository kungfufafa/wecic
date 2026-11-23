import { Fragment, useState } from 'react';
import { AccessibilityInfo, Pressable, StyleSheet, View } from 'react-native';

import type { NotebookAiBudget, NotebookMessage, NotebookQuizItem } from '@/api/types/classes';
import { Button } from '@/components/button';
import { Divider } from '@/components/section';
import { ThemedText } from '@/components/themed-text';
import { Radius, Spacing } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';
import { budgetLine, quizChoicesPayload, quizItemView, quizReviewItems, scoreQuiz, type QuizChoices } from '@/lib/classes';

export type PracticeProps = {
  message: NotebookMessage;
  /** What is left of today's AI requests, said before "Buat latihan dari yang salah". */
  budget?: NotebookAiBudget | null;
  /** Whether a practice set can be made now (AI on, consent given, nothing else running). */
  canMakePractice?: boolean;
  /** Keep the attempt on the server (no AI, no budget). */
  onSave?: (practice: Record<string, unknown>) => void;
  /** Make a new quiz from the missed items: one AI request. */
  onPracticeFromMissed?: (missed: number[]) => void;
  /** Ulasan harian (no AI): `onlyMissed` saves the items missed in the last practice, otherwise every flashcard. */
  onSaveToReview?: (onlyMissed: boolean) => void;
  /** A save to Ulasan harian is running. */
  savingToReview?: boolean;
};

/** The citation numbers of an item as "[1] Modul, hlm. 2" lines, from the output's citations. */
export function citationLine(message: NotebookMessage, numbers: number[] | undefined): string | null {
  const citations = message.citations ?? [];
  const lines = (numbers ?? [])
    .map((n) => citations.find((citation) => citation.n === n))
    .filter((citation) => citation !== undefined)
    .map((citation) => `[${citation.n}] ${citation.source_title ?? 'Sumber'}${citation.page ? `, hlm. ${citation.page}` : ''}`);

  return lines.length ? `Rujukan: ${lines.join('; ')}` : null;
}

/**
 * A quiz to practise on: answers and explanations stay hidden until an option is tapped, the verdict is said in
 * words (not only by colour), a question can be marked wrong ("Soal ini salah?") to leave the score, and after the
 * last answer the score comes with Ulangi (on the phone, no AI), Tinjau yang salah, and Buat latihan dari yang salah.
 */
export function QuizPractice({ message, budget, canMakePractice = false, onSave, onPracticeFromMissed, onSaveToReview, savingToReview = false }: PracticeProps) {
  const theme = useTheme();
  const items = (Array.isArray(message.data) ? message.data : []) as NotebookQuizItem[];
  const [choices, setChoices] = useState<QuizChoices>({});
  const [flagged, setFlagged] = useState<number[]>([]);
  const [reviewing, setReviewing] = useState(false);
  const result = scoreQuiz(items, choices, flagged);
  const reviewed = reviewing ? quizReviewItems(items, result, flagged) : null;
  const shown = reviewed ?? items.map((_, index) => index);
  const last = message.context?.practice;
  const budgetText = budgetLine(budget);
  const budgetSpent = budget?.remaining === 0;

  function save(nextChoices: QuizChoices, nextFlagged: number[]) {
    if (scoreQuiz(items, nextChoices, nextFlagged).done) {
      onSave?.({ choices: quizChoicesPayload(items, nextChoices), flagged: nextFlagged });
    }
  }

  function choose(index: number, option: number) {
    const next = { ...choices, [index]: option };
    const view = quizItemView(items[index], option);
    const score = scoreQuiz(items, next, flagged);

    setChoices(next);
    AccessibilityInfo.announceForAccessibility(
      `${view.verdict ?? ''} ${view.explanation ?? ''}${score.done ? ` Skor ${score.score} dari ${score.total}.` : ''}`,
    );
    save(next, flagged);
  }

  function toggleFlag(index: number) {
    const next = flagged.includes(index) ? flagged.filter((item) => item !== index) : [...flagged, index];
    const wasDone = result.done;

    setFlagged(next);
    AccessibilityInfo.announceForAccessibility(next.includes(index) ? 'Soal ditandai salah dan tidak dihitung.' : 'Tanda salah dibatalkan.');

    if (wasDone || scoreQuiz(items, choices, next).done) {
      onSave?.({ choices: quizChoicesPayload(items, choices), flagged: next });
    }
  }

  return (
    <View style={styles.list}>
      {!result.done ? (
        <ThemedText type="caption" themeColor="textTertiary">
          {`Terjawab ${result.answered}/${result.total}${last && result.answered === 0 ? ` · Skor terakhir ${last.score}/${last.total}` : ''}`}
        </ThemedText>
      ) : null}
      {shown.map((index, position) => {
        const item = items[index] ?? {};
        const isFlagged = flagged.includes(index);
        const view = quizItemView(item, choices[index], isFlagged);
        const citations = view.answered ? citationLine(message, item.citations) : null;

        return (
          <Fragment key={index}>
            {position > 0 ? <Divider /> : null}
            <View style={[styles.item, isFlagged && styles.flagged]}>
              <ThemedText type="smallBold">{`${index + 1}. ${item.question ?? ''}`}</ThemedText>
              {item.topic ? (
                <ThemedText type="caption" themeColor="textTertiary">
                  {`Topik: ${item.topic}`}
                </ThemedText>
              ) : null}
              {(item.options ?? []).map((option, optionIndex) => {
                const state = view.options[optionIndex];
                const mark = state === 'correct' ? ' · Benar' : state === 'chosen-wrong' ? ' · Pilihanmu, salah' : '';
                const background = state === 'correct' ? theme.successTint : state === 'chosen-wrong' ? theme.dangerTint : theme.elevated;
                const color = state === 'correct' ? theme.success : state === 'chosen-wrong' ? theme.danger : theme.text;

                return (
                  <Pressable
                    key={optionIndex}
                    accessibilityRole="button"
                    accessibilityState={{ disabled: view.answered || isFlagged, selected: choices[index] === optionIndex }}
                    accessibilityLabel={`${String.fromCharCode(65 + optionIndex)}. ${option}${mark}`}
                    disabled={view.answered || isFlagged}
                    onPress={() => choose(index, optionIndex)}
                    style={({ pressed }) => [styles.option, { backgroundColor: pressed ? theme.elevatedPressed : background }]}>
                    <ThemedText type="small" style={{ color }}>
                      {`${String.fromCharCode(65 + optionIndex)}. ${option}${mark}`}
                    </ThemedText>
                  </Pressable>
                );
              })}
              {view.verdict ? (
                <View style={styles.explanation} accessibilityLiveRegion="polite">
                  <ThemedText type="smallBold">{view.verdict}</ThemedText>
                  {view.explanation ? (
                    <ThemedText type="small" themeColor="textSecondary">
                      {view.explanation}
                    </ThemedText>
                  ) : null}
                  {citations ? (
                    <ThemedText type="caption" themeColor="textTertiary">
                      {citations}
                    </ThemedText>
                  ) : null}
                </View>
              ) : null}
              {isFlagged ? (
                <ThemedText type="caption" themeColor="textTertiary">
                  Ditandai salah, tidak dihitung dalam skor.
                </ThemedText>
              ) : null}
              <Pressable
                accessibilityRole="button"
                accessibilityState={{ selected: isFlagged }}
                hitSlop={8}
                onPress={() => toggleFlag(index)}
                style={styles.flag}>
                <ThemedText type="caption" themeColor="textSecondary">
                  {isFlagged ? 'Batalkan tanda salah' : 'Soal ini salah?'}
                </ThemedText>
              </Pressable>
            </View>
          </Fragment>
        );
      })}
      {result.done ? (
        <View style={[styles.score, { backgroundColor: theme.elevated }]} accessibilityLiveRegion="polite">
          <ThemedText type="subtitle">{`Skor ${result.score}/${result.total}`}</ThemedText>
          <ThemedText type="small" themeColor="textSecondary">
            {`${result.missed.length ? `${result.missed.length} soal belum tepat.` : 'Semua jawaban benar.'}${flagged.length ? ` ${flagged.length} soal ditandai salah dan tidak dihitung.` : ''}`}
          </ThemedText>
          <View style={styles.actions}>
            <Button
              size="small"
              variant="secondary"
              label="Ulangi"
              onPress={() => {
                setChoices({});
                setReviewing(false);
              }}
            />
            {result.missed.length || reviewed ? (
              <Button size="small" variant="secondary" label={reviewed ? 'Tampilkan semua soal' : 'Tinjau yang salah'} onPress={() => setReviewing(!reviewed)} />
            ) : null}
            {result.missed.length ? (
              <Button
                size="small"
                variant="secondary"
                label="Simpan yang salah ke ulasan"
                loading={savingToReview}
                disabled={!onSaveToReview}
                onPress={() => onSaveToReview?.(true)}
              />
            ) : null}
          </View>
          {result.missed.length && onPracticeFromMissed ? (
            <>
              {budgetText ? (
                <ThemedText type="caption" themeColor="textTertiary">
                  {budgetText}
                </ThemedText>
              ) : null}
              <Button
                size="small"
                icon="sparkles"
                label="Buat latihan dari yang salah"
                disabled={!canMakePractice || budgetSpent}
                onPress={() => onPracticeFromMissed(result.missed)}
              />
            </>
          ) : null}
        </View>
      ) : null}
    </View>
  );
}

const styles = StyleSheet.create({
  list: {
    gap: Spacing.two + Spacing.half,
  },
  item: {
    gap: Spacing.one + Spacing.half,
  },
  flagged: {
    opacity: 0.7,
  },
  option: {
    minHeight: 44,
    justifyContent: 'center',
    paddingHorizontal: Spacing.two + Spacing.half,
    paddingVertical: Spacing.one + Spacing.half,
    borderRadius: Radius.small,
  },
  explanation: {
    gap: Spacing.half,
  },
  flag: {
    alignSelf: 'flex-start',
    minHeight: 28,
    justifyContent: 'center',
  },
  score: {
    gap: Spacing.two,
    padding: Spacing.three,
    borderRadius: Radius.medium,
  },
  actions: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: Spacing.two,
  },
});
