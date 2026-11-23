import { useState } from 'react';
import { KeyboardAvoidingView, Modal, Platform, Pressable, ScrollView, StyleSheet, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import type { NotebookAiBudget, NotebookSource } from '@/api/types/classes';
import { Button } from '@/components/button';
import { Chip } from '@/components/chip';
import { TextField } from '@/components/text-field';
import { ThemedText } from '@/components/themed-text';
import { Radius, Spacing } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';
import { budgetLine } from '@/lib/classes';
import { assignmentBriefChips, BRIEF_FOCUS_MAX, briefWithChip } from '@/lib/notebook-sources';

type AssignmentBriefSheetProps = {
  visible: boolean;
  label: string;
  /** The notebook's sources: meetings already read give "Tugas pertemuan N" shortcuts. */
  sources: readonly NotebookSource[];
  /** How many ready sources are in the context, and how many are ready. */
  chosenCount: number;
  readyCount: number;
  budget?: NotebookAiBudget | null;
  onClose: () => void;
  onSubmit: (focus: string | null) => void;
};

/**
 * The lecturer's brief before the assignment outline (one AI request):
 * the outline fits the task and the chosen sources, and leaves the
 * writing to the student.
 */
export function AssignmentBriefSheet({ visible, label, sources, chosenCount, readyCount, budget, onClose, onSubmit }: AssignmentBriefSheetProps) {
  const theme = useTheme();
  // A Modal is a window of its own, where SafeAreaView reads no insets: the app's are applied by hand.
  const insets = useSafeAreaInsets();
  const [focus, setFocus] = useState('');
  const chips = assignmentBriefChips(sources);
  const budgetText = budgetLine(budget);

  const [shownVisible, setShownVisible] = useState(visible);
  if (visible !== shownVisible) {
    setShownVisible(visible);
    if (visible) {
      setFocus('');
    }
  }

  return (
    <Modal visible={visible} transparent animationType="slide" onRequestClose={onClose}>
      <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={styles.root}>
        <Pressable style={styles.backdrop} accessibilityRole="button" accessibilityLabel="Tutup" onPress={onClose} />
        <View style={[styles.sheet, { backgroundColor: theme.panel, paddingBottom: Spacing.three + insets.bottom }]} accessibilityViewIsModal>
          <ThemedText type="subtitle">{label}</ThemedText>
          <ThemedText type="small" themeColor="textSecondary">
            Tulis instruksi tugas dari dosen (atau tempel dari portal). Kerangka, poin per bagian, konsep kunci bersitasi, langkah kerja, dan checklist disusun hanya dari sumber yang dipilih, agar kamu menulis sendiri dengan cepat dan rapi.
          </ThemedText>
          <TextField
            label="Instruksi / fokus tugas"
            value={focus}
            onChangeText={setFocus}
            placeholder="Contoh: Makalah 5 halaman tentang normalisasi basis data, sertakan contoh kasus, dikumpulkan pertemuan 7."
            multiline
            maxLength={BRIEF_FOCUS_MAX}
            style={styles.focus}
          />
          <ThemedText type="caption" themeColor="textQuaternary" style={styles.counter}>
            {`${focus.length.toLocaleString('id-ID')}/${BRIEF_FOCUS_MAX.toLocaleString('id-ID')}`}
          </ThemedText>
          {chips.length > 0 ? (
            <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.chips} accessibilityLabel="Tugas dari pertemuan">
              {chips.map((chip) => (
                <Chip key={chip.key} label={chip.label} onPress={() => setFocus((current) => briefWithChip(current, chip))} />
              ))}
            </ScrollView>
          ) : null}
          <ThemedText type="caption" themeColor="textTertiary">
            {`Konteks: ${chosenCount} sumber${chosenCount !== readyCount ? ` (dari ${readyCount} yang siap)` : ''}. Ubah pilihan sumber di bagian Tanya jawab bila perlu.`}
          </ThemedText>
          {budgetText ? (
            <ThemedText type="caption" themeColor="textTertiary">
              {budgetText.replace('Membuat latihan', 'Membuat ini')}
            </ThemedText>
          ) : null}
          <Button icon="sparkles" label="Susun kerangka" disabled={budget?.remaining === 0} onPress={() => onSubmit(focus.trim() !== '' ? focus.trim() : null)} />
        </View>
      </KeyboardAvoidingView>
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
  focus: {
    minHeight: 120,
    maxHeight: 220,
    textAlignVertical: 'top',
  },
  counter: {
    alignSelf: 'flex-end',
  },
  chips: {
    gap: Spacing.two,
    paddingHorizontal: Spacing.one,
  },
});
