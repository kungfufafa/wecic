import { Fragment } from 'react';
import { StyleSheet, View } from 'react-native';

import { Button } from '@/components/button';
import { Chip } from '@/components/chip';
import { CitationText } from '@/components/classes/citation-text';
import { FeedbackResult } from '@/components/classes/feedback-result';
import { FlashcardDeck } from '@/components/classes/flashcard-deck';
import { QuizPractice, type PracticeProps } from '@/components/classes/quiz-practice';
import { Divider } from '@/components/section';
import { ThemedText } from '@/components/themed-text';
import { Spacing } from '@/constants/theme';
import { mindMapLines, type MindMapNode } from '@/lib/classes';
import { feedbackRequest, outlinePrompt, practiceQuestions, type FeedbackRequest } from '@/lib/notebook-feedback';

type TermItem = { term?: string; definition?: string; citations?: number[] };

type StudioResultProps = PracticeProps & {
  /** The notebook the output belongs to, for a citation's "Lihat sumber". */
  notebookId?: string | number | null;
  /**
   * "Cek pemahamanku": opens the feedback sheet for a study guide's practice question ("Jawab"), an assignment
   * outline ("Cek kerangkaku"), or a feedback's follow-up question ("Jawab lagi"). Without it there are no such buttons.
   */
  onFeedback?: (request: FeedbackRequest) => void;
};

/**
 * A studio result: plain text, a "Cek pemahamanku" feedback (FeedbackResult), or the quiz (QuizPractice), flashcards (FlashcardDeck), glossary or mind map it holds.
 * The practice callbacks reach the quiz and flashcards only. Citations in text and in the glossary are chips (CitationText).
 */
export function StudioResult({ message, notebookId, onFeedback, ...practice }: StudioResultProps) {
  const data = message.data;
  const openFeedback = (prompt: string, mode: FeedbackRequest['mode']) => {
    const request = feedbackRequest(prompt, mode);

    if (request && onFeedback) {
      onFeedback(request);
    }
  };

  if (message.kind === 'feedback') {
    return (
      <FeedbackResult
        message={message}
        notebookId={notebookId}
        onAnswerAgain={onFeedback ? (question) => openFeedback(question, 'practice_answer') : undefined}
      />
    );
  }

  if (message.kind === 'study_guide' && onFeedback) {
    const questions = practiceQuestions(message.content);

    return (
      <View style={styles.list}>
        <CitationText content={message.content} citations={message.citations} notebookId={notebookId} messageId={message.id} />
        {questions.length > 0 ? (
          <View style={styles.feedback} accessibilityLabel="Jawab pertanyaan latihan">
            <ThemedText type="smallBold">Cek pemahamanku</ThemedText>
            <ThemedText type="caption" themeColor="textTertiary">
              Jawab satu pertanyaan latihan dengan kata-katamu sendiri, lalu dapatkan umpan balik dari sumber.
            </ThemedText>
            {questions.map((question) => (
              <Chip key={question} label={`Jawab: ${question}`} onPress={() => openFeedback(question, 'practice_answer')} />
            ))}
          </View>
        ) : null}
      </View>
    );
  }

  if (message.kind === 'assignment_plan' && onFeedback) {
    return (
      <View style={styles.list}>
        <CitationText content={message.content} citations={message.citations} notebookId={notebookId} messageId={message.id} />
        <View style={styles.action}>
          <Button size="small" variant="secondary" icon="sparkles" label="Cek kerangkaku" onPress={() => openFeedback(outlinePrompt(message), 'outline_check')} />
        </View>
      </View>
    );
  }

  if (message.kind === 'quiz' && Array.isArray(data)) {
    return <QuizPractice message={message} {...practice} />;
  }

  if (message.kind === 'flashcards' && Array.isArray(data)) {
    return <FlashcardDeck message={message} {...practice} />;
  }

  if (message.kind === 'glossary' && Array.isArray(data)) {
    return (
      <View style={styles.list}>
        {(data as TermItem[]).map((item, index) => (
          <Fragment key={index}>
            {index > 0 ? <Divider /> : null}
            <View style={styles.item}>
              <ThemedText type="smallBold">{item.term ?? ''}</ThemedText>
              <CitationText
                content={`${item.definition ?? ''}${glossaryCitations(item.citations)}`}
                citations={message.citations}
                notebookId={notebookId}
                messageId={message.id}
              />
            </View>
          </Fragment>
        ))}
      </View>
    );
  }

  if (message.kind === 'mind_map' && data && typeof data === 'object') {
    return (
      <View style={styles.mindMap}>
        {mindMapLines(data as MindMapNode).map((line, index) => (
          <ThemedText
            key={index}
            type={line.depth === 0 ? 'smallBold' : 'small'}
            themeColor={line.depth <= 1 ? 'text' : 'textSecondary'}
            style={{ paddingLeft: line.depth * Spacing.three }}>
            {line.depth === 0 ? line.title : `• ${line.title}`}
          </ThemedText>
        ))}
      </View>
    );
  }

  return <CitationText content={message.content} citations={message.citations} notebookId={notebookId} messageId={message.id} />;
}

/** A glossary item's citation numbers as the ` [1][2]` CitationText draws as chips. */
function glossaryCitations(numbers: number[] | undefined): string {
  const valid = (numbers ?? []).filter((n) => Number.isInteger(n) && n > 0);

  return valid.length > 0 ? ` ${valid.map((n) => `[${n}]`).join('')}` : '';
}

const styles = StyleSheet.create({
  list: {
    gap: Spacing.two + Spacing.half,
  },
  item: {
    gap: Spacing.one + Spacing.half,
  },
  feedback: {
    alignItems: 'flex-start',
    gap: Spacing.two,
  },
  action: {
    flexDirection: 'row',
  },
  mindMap: {
    gap: Spacing.one + Spacing.half,
  },
});
