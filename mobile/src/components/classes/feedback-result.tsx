import { StyleSheet, View } from 'react-native';

import type { NotebookMessage } from '@/api/types/classes';
import { Button } from '@/components/button';
import { CitationText } from '@/components/classes/citation-text';
import { StatusPill } from '@/components/status-pill';
import { ThemedText } from '@/components/themed-text';
import { Spacing } from '@/constants/theme';
import { FEEDBACK_MODES, FEEDBACK_VERDICTS, feedbackModeOf, feedbackOf, withCitations } from '@/lib/notebook-feedback';

type FeedbackResultProps = {
  message: NotebookMessage;
  notebookId?: string | number | null;
  /** "Jawab lagi": answer the follow-up question in the feedback sheet; without it the question is text only. */
  onAnswerAgain?: (question: string) => void;
  /** Called before a citation's "Lihat sumber" opens the source, e.g. to close the sheet this result sits in. */
  onNavigate?: () => void;
};

/**
 * "Cek pemahamanku" feedback: the verdict in words, what is right, missing
 * and mistaken with citation chips (CitationText), the next step, and the
 * follow-up question. There is never a corrected answer to show.
 */
export function FeedbackResult({ message, notebookId, onAnswerAgain, onNavigate }: FeedbackResultProps) {
  const feedback = feedbackOf(message);

  if (!feedback) {
    return (
      <ThemedText type="small" themeColor="textTertiary">
        Umpan balik ini tidak bisa ditampilkan.
      </ThemedText>
    );
  }

  const verdict = FEEDBACK_VERDICTS[feedback.verdict];
  const prompt = message.context?.feedback_prompt;
  const cite = (content: string) => <CitationText content={content} citations={message.citations} notebookId={notebookId} messageId={message.id} onNavigate={onNavigate} />;

  return (
    <View style={styles.root}>
      {prompt ? (
        <ThemedText type="caption" themeColor="textTertiary">
          {`${FEEDBACK_MODES[feedbackModeOf(message.context?.feedback_mode)]}: ${prompt}`}
        </ThemedText>
      ) : null}
      <View style={styles.verdict}>
        <StatusPill label={verdict.label} tone={verdict.tone} />
      </View>
      {feedback.strengths.length > 0 ? (
        <View style={styles.group}>
          <ThemedText type="smallBold">Yang sudah tepat</ThemedText>
          {feedback.strengths.map((item, index) => (
            <View key={index}>{cite(withCitations(`• ${item.point}`, item.citations))}</View>
          ))}
        </View>
      ) : null}
      {feedback.gaps.length > 0 ? (
        <View style={styles.group}>
          <ThemedText type="smallBold">Yang belum dibahas</ThemedText>
          {feedback.gaps.map((item, index) => (
            <View key={index}>{cite(withCitations(`• ${item.point}`, item.citations))}</View>
          ))}
        </View>
      ) : null}
      {feedback.misconceptions.length > 0 ? (
        <View style={styles.group}>
          <ThemedText type="smallBold">Yang perlu diluruskan</ThemedText>
          {feedback.misconceptions.map((item, index) => (
            <View key={index}>{cite(withCitations(`• ${item.claim}${item.correction ? ` → ${item.correction}` : ''}`, item.citations))}</View>
          ))}
        </View>
      ) : null}
      {feedback.next_step ? (
        <View style={styles.group}>
          <ThemedText type="smallBold">Langkah berikutnya</ThemedText>
          {cite(feedback.next_step)}
        </View>
      ) : null}
      {feedback.follow_up_question ? (
        <View style={styles.group}>
          <ThemedText type="smallBold">Pertanyaan lanjutan</ThemedText>
          {cite(feedback.follow_up_question)}
          {onAnswerAgain ? (
            <View style={styles.action}>
              <Button size="small" variant="secondary" label="Jawab lagi" onPress={() => onAnswerAgain(feedback.follow_up_question)} />
            </View>
          ) : null}
        </View>
      ) : null}
    </View>
  );
}

const styles = StyleSheet.create({
  root: {
    gap: Spacing.two + Spacing.half,
  },
  verdict: {
    flexDirection: 'row',
  },
  group: {
    gap: Spacing.one,
  },
  action: {
    flexDirection: 'row',
    paddingTop: Spacing.one,
  },
});
