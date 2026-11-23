import { useState } from 'react';
import { Modal, Pressable, StyleSheet, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import type { NotebookAiBudget, NotebookDifficulty, NotebookStudioOptions } from '@/api/types/classes';
import { Button } from '@/components/button';
import { Chip } from '@/components/chip';
import { ThemedText } from '@/components/themed-text';
import { Radius, Spacing } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';
import { budgetLine, NotebookDifficulties, studioCounts } from '@/lib/classes';

type StudioOptionsSheetProps = {
  /** `quiz` or `flashcards`; null keeps the sheet closed. */
  kind: string | null;
  label: string;
  budget?: NotebookAiBudget | null;
  /** What the quiz centres on (a mind map node's Latihan); shown above the choices. */
  focus?: string | null;
  onClose: () => void;
  onSubmit: (options: NotebookStudioOptions) => void;
};

/** How many quiz questions or flashcards, and how hard, before they are made (one AI request). */
export function StudioOptionsSheet({ kind, label, budget, focus, onClose, onSubmit }: StudioOptionsSheetProps) {
  const theme = useTheme();
  // A Modal is a window of its own, where SafeAreaView reads no insets: the app's are applied by hand.
  const insets = useSafeAreaInsets();
  const counts = studioCounts(kind ?? 'quiz');
  const [count, setCount] = useState(counts.initial);
  const [difficulty, setDifficulty] = useState<NotebookDifficulty>('campuran');
  const budgetText = budgetLine(budget);

  const [shownKind, setShownKind] = useState(kind);
  if (kind !== shownKind) {
    setShownKind(kind);
    if (kind) {
      setCount(studioCounts(kind).initial);
      setDifficulty('campuran');
    }
  }

  return (
    <Modal visible={kind !== null} transparent animationType="slide" onRequestClose={onClose}>
      <View style={styles.root}>
        <Pressable style={styles.backdrop} accessibilityRole="button" accessibilityLabel="Tutup" onPress={onClose} />
        <View style={[styles.sheet, { backgroundColor: theme.panel, paddingBottom: Spacing.three + insets.bottom }]} accessibilityViewIsModal>
          <ThemedText type="subtitle">{label}</ThemedText>
          <ThemedText type="small" themeColor="textSecondary">
            {kind === 'flashcards'
              ? 'Flashcard untuk menghafal istilah dan konsep penting, disusun hanya dari sumber notebook ini.'
              : 'Kuis latihan untuk menguji pemahamanmu, dengan penjelasan dan rujukan sumber di tiap soal.'}
          </ThemedText>
          {focus ? (
            <ThemedText type="small">{`Fokus: ${focus}`}</ThemedText>
          ) : null}
          <ThemedText type="label">{`Jumlah ${counts.unit}`}</ThemedText>
          <View style={styles.row}>
            {counts.counts.map((value) => (
              <Chip key={value} label={String(value)} selected={count === value} onPress={() => setCount(value)} />
            ))}
          </View>
          <ThemedText type="label">Tingkat kesulitan</ThemedText>
          <View style={styles.row}>
            {NotebookDifficulties.map((item) => (
              <Chip key={item.difficulty} label={item.label} selected={difficulty === item.difficulty} onPress={() => setDifficulty(item.difficulty)} />
            ))}
          </View>
          {budgetText ? (
            <ThemedText type="caption" themeColor="textTertiary">
              {budgetText.replace('Membuat latihan', 'Membuat ini')}
            </ThemedText>
          ) : null}
          <Button icon="sparkles" label={`Buat ${label.toLowerCase()}`} disabled={budget?.remaining === 0} onPress={() => onSubmit({ count, difficulty })} />
        </View>
      </View>
    </Modal>
  );
}

const styles = StyleSheet.create({
  root: {
    flex: 1,
    justifyContent: 'flex-end',
  },
  backdrop: {
    ...StyleSheet.absoluteFill,
    backgroundColor: 'rgba(0, 0, 0, 0.4)',
  },
  sheet: {
    gap: Spacing.two + Spacing.half,
    paddingHorizontal: Spacing.three + Spacing.half,
    paddingTop: Spacing.three + Spacing.half,
    borderTopLeftRadius: Radius.section,
    borderTopRightRadius: Radius.section,
  },
  row: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: Spacing.two,
  },
});
