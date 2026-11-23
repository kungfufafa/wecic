import { useState } from 'react';
import { KeyboardAvoidingView, Modal, Platform, Pressable, ScrollView, StyleSheet, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import type { NotebookAiBudget, NotebookMessage } from '@/api/types/classes';
import { Button } from '@/components/button';
import { FeedbackResult } from '@/components/classes/feedback-result';
import { Notice } from '@/components/notice';
import { TextField } from '@/components/text-field';
import { ThemedText } from '@/components/themed-text';
import { Radius, Spacing } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';
import { answerProblem, FEEDBACK_ANSWER_MAX, FEEDBACK_MODES, feedbackBudgetLine, type FeedbackRequest } from '@/lib/notebook-feedback';

type FeedbackSheetProps = {
  /** The question, concept or brief to answer; null keeps the sheet closed. */
  request: FeedbackRequest | null;
  notebookId?: string | number | null;
  /** How many ready sources are in the context. */
  chosenCount: number;
  budget?: NotebookAiBudget | null;
  busy: boolean;
  error?: string | null;
  /** The feedback once it came back; the sheet then shows it instead of the form. */
  result?: NotebookMessage | null;
  /** Closes the sheet; it may be called while busy: the request carries on and its result lands in Studio. */
  onClose: () => void;
  onSubmit: (answer: string) => void;
  /** "Jawab lagi" on the follow-up question opens the sheet again for it. */
  onAnswerAgain?: (question: string) => void;
};

/**
 * "Cek pemahamanku": the student writes their own answer, explanation or
 * outline (one AI request), and the feedback names what is right, missing
 * and mistaken, never a corrected answer. Opened from a study guide's
 * practice questions, an assignment outline, a follow-up question, or any
 * later entry point with a FeedbackRequest.
 */
export function FeedbackSheet({ request, notebookId, chosenCount, budget, busy, error, result, onClose, onSubmit, onAnswerAgain }: FeedbackSheetProps) {
  const theme = useTheme();
  // A Modal is a window of its own, where SafeAreaView reads no insets: the app's are applied by hand.
  const insets = useSafeAreaInsets();
  const [answer, setAnswer] = useState('');
  const [problem, setProblem] = useState<string | null>(null);
  const budgetText = feedbackBudgetLine(budget);

  const [shownPrompt, setShownPrompt] = useState(request?.prompt ?? null);
  if ((request?.prompt ?? null) !== shownPrompt) {
    setShownPrompt(request?.prompt ?? null);
    setAnswer('');
    setProblem(null);
  }

  function submit() {
    const found = answerProblem(answer);

    setProblem(found);

    if (!found) {
      onSubmit(answer.trim());
    }
  }

  const outline = request?.mode === 'outline_check';

  return (
    <Modal visible={request !== null} transparent animationType="slide" onRequestClose={onClose}>
      <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={styles.root}>
        <Pressable style={styles.backdrop} accessibilityRole="button" accessibilityLabel="Tutup" onPress={onClose} />
        <View style={[styles.sheet, { backgroundColor: theme.panel, paddingBottom: Spacing.three + insets.bottom }]} accessibilityViewIsModal>
          <ScrollView contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
            <ThemedText type="subtitle">Cek pemahamanku</ThemedText>
            {request ? (
              <View style={[styles.prompt, { backgroundColor: theme.elevated }]}>
                <ThemedText type="caption" themeColor="textTertiary">
                  {FEEDBACK_MODES[request.mode]}
                </ThemedText>
                <ThemedText type="small" selectable>
                  {request.prompt}
                </ThemedText>
              </View>
            ) : null}
            {result ? (
              <>
                <FeedbackResult message={result} notebookId={notebookId} onAnswerAgain={onAnswerAgain} onNavigate={onClose} />
                <ThemedText type="caption" themeColor="textTertiary">
                  Tersimpan di Studio.
                </ThemedText>
                <Button variant="secondary" label="Tutup" onPress={onClose} />
              </>
            ) : (
              <>
                <ThemedText type="small" themeColor="textSecondary">
                  Kami memberi umpan balik, bukan menuliskan tugasmu. Tulis dengan kata-katamu sendiri; jawabanmu dinilai hanya dari sumber yang dipilih.
                </ThemedText>
                <TextField
                  label={outline ? 'Kerangkamu' : 'Jawabanmu'}
                  value={answer}
                  onChangeText={setAnswer}
                  placeholder={outline ? 'Tulis kerangkamu: bagian-bagian dan poin yang akan dibahas.' : 'Tulis jawabanmu sendiri, minimal 20 karakter.'}
                  multiline
                  maxLength={FEEDBACK_ANSWER_MAX}
                  editable={!busy}
                  style={styles.answer}
                />
                <ThemedText type="caption" themeColor="textQuaternary" style={styles.counter}>
                  {`${answer.length.toLocaleString('id-ID')}/${FEEDBACK_ANSWER_MAX.toLocaleString('id-ID')}`}
                </ThemedText>
                {problem || error ? <Notice tone="error" message={problem ?? error ?? ''} /> : null}
                <ThemedText type="caption" themeColor="textTertiary">
                  {`Konteks: ${chosenCount} sumber.${budgetText ? ` ${budgetText}` : ''}`}
                </ThemedText>
                <Button icon="sparkles" label="Cek pemahamanku" loading={busy} disabled={busy || budget?.remaining === 0} onPress={submit} />
              </>
            )}
          </ScrollView>
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
    maxHeight: '90%',
    paddingHorizontal: Spacing.three + Spacing.half,
    paddingTop: Spacing.three + Spacing.half,
    borderTopLeftRadius: Radius.section,
    borderTopRightRadius: Radius.section,
  },
  content: {
    gap: Spacing.two + Spacing.half,
  },
  prompt: {
    gap: Spacing.half,
    padding: Spacing.two + Spacing.half,
    borderRadius: Radius.large,
  },
  answer: {
    minHeight: 140,
    maxHeight: 240,
    textAlignVertical: 'top',
  },
  counter: {
    alignSelf: 'flex-end',
  },
});
