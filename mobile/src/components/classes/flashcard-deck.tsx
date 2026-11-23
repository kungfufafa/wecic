import { Fragment, useEffect, useState } from 'react';
import { AccessibilityInfo, Animated, Pressable, StyleSheet, View } from 'react-native';

import type { NotebookFlashcardItem } from '@/api/types/classes';
import { Button } from '@/components/button';
import { Chip } from '@/components/chip';
import { citationLine, type PracticeProps } from '@/components/classes/quiz-practice';
import { Divider } from '@/components/section';
import { ThemedText } from '@/components/themed-text';
import { Radius, Spacing } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';
import { budgetLine, flashcardsToLearn } from '@/lib/classes';

/** Whether the student asked the system for less motion; follows the setting while the screen is open. */
function useReduceMotion(): boolean {
  const [reduce, setReduce] = useState(false);

  useEffect(() => {
    let mounted = true;
    const subscription = AccessibilityInfo.addEventListener('reduceMotionChanged', setReduce);

    void AccessibilityInfo.isReduceMotionEnabled().then((enabled) => {
      if (mounted) {
        setReduce(enabled);
      }
    });

    return () => {
      mounted = false;
      subscription.remove();
    };
  }, []);

  return reduce;
}

function shuffled(items: number[]): number[] {
  const copy = items.slice();

  for (let index = copy.length - 1; index > 0; index--) {
    const swap = Math.floor(Math.random() * (index + 1));
    [copy[index], copy[swap]] = [copy[swap], copy[index]];
  }

  return copy;
}

/**
 * Flashcards one at a time: tap to flip (a short squash, or none with reduced motion), then "Sudah hafal" or
 * "Ulangi"; previous, next and shuffle move freely, and the progress reads "Kartu x/N". The list of every card stays
 * as the other view.
 */
export function FlashcardDeck({ message, budget, canMakePractice = false, onSave, onPracticeFromMissed, onSaveToReview, savingToReview = false }: PracticeProps) {
  const theme = useTheme();
  const reduceMotion = useReduceMotion();
  const cards = (Array.isArray(message.data) ? message.data : []) as NotebookFlashcardItem[];
  const [mode, setMode] = useState<'deck' | 'list'>('deck');
  const [order, setOrder] = useState(() => cards.map((_, index) => index));
  const [position, setPosition] = useState(0);
  const [flipped, setFlipped] = useState(false);
  const [known, setKnown] = useState<number[]>([]);
  const [squash] = useState(() => new Animated.Value(1));
  const current = order[position];
  const card = current !== undefined ? cards[current] ?? {} : {};
  const finished = position >= order.length;
  const budgetText = budgetLine(budget);
  const toLearn = flashcardsToLearn(cards.length, known);

  function flip() {
    if (reduceMotion) {
      setFlipped(!flipped);

      return;
    }

    Animated.timing(squash, { toValue: 0, duration: 110, useNativeDriver: true }).start(({ finished: squashed }) => {
      // Stopped by move() (another card): that card opens on its front.
      if (!squashed) {
        return;
      }

      setFlipped((value) => !value);
      Animated.timing(squash, { toValue: 1, duration: 110, useNativeDriver: true }).start();
    });
  }

  function move(to: number) {
    squash.setValue(1);
    setFlipped(false);
    setPosition(to);
  }

  function grade(remembered: boolean) {
    const nextKnown = remembered ? [...new Set([...known, current])] : known.filter((index) => index !== current);

    setKnown(nextKnown);
    move(position + 1);

    if (position + 1 >= order.length) {
      AccessibilityInfo.announceForAccessibility(`Selesai. Hafal ${nextKnown.length} dari ${cards.length} kartu.`);
      onSave?.({ score: nextKnown.length, total: cards.length, missed: flashcardsToLearn(cards.length, nextKnown) });
    }
  }

  return (
    <View style={styles.root}>
      <View style={styles.modes} accessibilityLabel="Tampilan flashcard">
        <Chip label="Satu kartu" selected={mode === 'deck'} onPress={() => setMode('deck')} />
        <Chip label="Semua kartu" selected={mode === 'list'} onPress={() => setMode('list')} />
      </View>

      {mode === 'list' ? (
        <View style={styles.list}>
          {cards.map((item, index) => (
            <Fragment key={index}>
              {index > 0 ? <Divider /> : null}
              <View style={styles.item}>
                <ThemedText type="smallBold">{item.front ?? ''}</ThemedText>
                <ThemedText type="small" themeColor="textSecondary">
                  {item.back ?? ''}
                </ThemedText>
              </View>
            </Fragment>
          ))}
        </View>
      ) : finished ? (
        <View style={[styles.summary, { backgroundColor: theme.elevated }]} accessibilityLiveRegion="polite">
          <ThemedText type="subtitle">{`Hafal ${known.length}/${cards.length}`}</ThemedText>
          <ThemedText type="small" themeColor="textSecondary">
            {toLearn.length ? `${toLearn.length} kartu perlu diulang.` : 'Semua kartu sudah hafal.'}
          </ThemedText>
          <View style={styles.actions}>
            {toLearn.length ? (
              <Button
                size="small"
                variant="secondary"
                label="Ulangi yang belum hafal"
                onPress={() => {
                  setOrder(toLearn);
                  move(0);
                }}
              />
            ) : null}
            <Button
              size="small"
              variant="secondary"
              label="Mulai lagi"
              onPress={() => {
                setOrder(cards.map((_, index) => index));
                setKnown([]);
                move(0);
              }}
            />
            {toLearn.length ? (
              <Button
                size="small"
                variant="secondary"
                label="Simpan yang belum hafal ke ulasan"
                loading={savingToReview}
                disabled={!onSaveToReview}
                onPress={() => onSaveToReview?.(true)}
              />
            ) : null}
          </View>
          {toLearn.length && onPracticeFromMissed ? (
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
                disabled={!canMakePractice || budget?.remaining === 0}
                onPress={() => onPracticeFromMissed(toLearn)}
              />
            </>
          ) : null}
        </View>
      ) : (
        <View style={styles.deck}>
          <ThemedText type="caption" themeColor="textTertiary">
            {`Kartu ${position + 1}/${order.length}${known.length ? ` · ${known.length} hafal` : ''}`}
          </ThemedText>
          <Animated.View style={{ transform: [{ scaleX: squash }] }}>
            <Pressable
              accessibilityRole="button"
              accessibilityLabel={flipped ? `Jawaban: ${card.back ?? ''}` : `Pertanyaan: ${card.front ?? ''}`}
              accessibilityHint={flipped ? 'Ketuk untuk melihat pertanyaan' : 'Ketuk untuk membalik'}
              onPress={flip}
              style={({ pressed }) => [styles.card, { backgroundColor: pressed ? theme.elevatedPressed : theme.elevated, borderColor: flipped ? theme.brand : theme.panelBorder }]}>
              <ThemedText type="caption" themeColor="textTertiary">
                {flipped ? 'Jawaban' : 'Pertanyaan'}
              </ThemedText>
              <ThemedText type={flipped ? 'default' : 'bodyMedium'} themeColor={flipped ? 'textSecondary' : 'text'}>
                {flipped ? card.back ?? '' : card.front ?? ''}
              </ThemedText>
              {flipped && citationLine(message, card.citations) ? (
                <ThemedText type="caption" themeColor="textTertiary">
                  {citationLine(message, card.citations)}
                </ThemedText>
              ) : null}
            </Pressable>
          </Animated.View>
          {flipped ? (
            <View style={styles.grade}>
              <Button size="small" variant="secondary" label="Ulangi" style={styles.flex} onPress={() => grade(false)} />
              <Button size="small" label="Sudah hafal" style={styles.flex} onPress={() => grade(true)} />
            </View>
          ) : null}
          <View style={styles.actions}>
            <Button size="small" variant="secondary" label="Sebelumnya" disabled={position === 0} onPress={() => move(position - 1)} />
            <Button size="small" variant="secondary" label="Berikutnya" disabled={position >= order.length - 1} onPress={() => move(position + 1)} />
            <Button
              size="small"
              variant="secondary"
              label="Acak"
              onPress={() => {
                setOrder(shuffled(order));
                move(0);
                AccessibilityInfo.announceForAccessibility('Kartu diacak.');
              }}
            />
          </View>
        </View>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  root: {
    gap: Spacing.two + Spacing.half,
  },
  modes: {
    flexDirection: 'row',
    gap: Spacing.two,
  },
  list: {
    gap: Spacing.two + Spacing.half,
  },
  item: {
    gap: Spacing.one + Spacing.half,
  },
  deck: {
    gap: Spacing.two,
  },
  card: {
    minHeight: 160,
    justifyContent: 'center',
    gap: Spacing.two,
    padding: Spacing.three,
    borderRadius: Radius.large,
    borderWidth: 1,
  },
  grade: {
    flexDirection: 'row',
    gap: Spacing.two,
  },
  flex: {
    flex: 1,
  },
  actions: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: Spacing.two,
  },
  summary: {
    gap: Spacing.two,
    padding: Spacing.three,
    borderRadius: Radius.medium,
  },
});
