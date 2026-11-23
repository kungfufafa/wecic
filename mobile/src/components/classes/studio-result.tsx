import { Fragment, useState } from 'react';
import { Modal, Pressable, StyleSheet, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { Button } from '@/components/button';
import { Chip } from '@/components/chip';
import { CitationText } from '@/components/classes/citation-text';
import { FeedbackResult } from '@/components/classes/feedback-result';
import { FlashcardDeck } from '@/components/classes/flashcard-deck';
import { QuizPractice, type PracticeProps } from '@/components/classes/quiz-practice';
import { Divider } from '@/components/section';
import { ThemedText } from '@/components/themed-text';
import { Radius, Spacing } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';
import { mindMapBudgetLine, mindMapLines, mindMapParent, type MindMapLine, type MindMapNode } from '@/lib/classes';
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
  /**
   * A mind map node's action (Jelaskan, Latihan, Jelaskan dengan kata-katamu), chosen in its action sheet. Without it the
   * nodes are plain lines.
   */
  onMindMapAction?: (action: MindMapAction, line: MindMapLine) => void;
};

export type MindMapAction = 'explain' | 'practice' | 'feedback';

/**
 * A studio result: plain text, a "Cek pemahamanku" feedback (FeedbackResult), or the quiz (QuizPractice), flashcards (FlashcardDeck), glossary or mind map it holds.
 * The practice callbacks reach the quiz and flashcards only. Citations in text and in the glossary are chips (CitationText).
 */
export function StudioResult({ message, notebookId, onFeedback, onMindMapAction, ...practice }: StudioResultProps) {
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
    return (
      <View style={styles.list}>
        <FlashcardDeck message={message} {...practice} />
        {data.length > 0 ? (
          <View style={styles.action}>
            <Button
              size="small"
              variant="secondary"
              label="Simpan ke ulasan"
              accessibilityHint="Semua kartu masuk Ulasan harian, tanpa AI"
              loading={practice.savingToReview}
              disabled={!practice.onSaveToReview}
              onPress={() => practice.onSaveToReview?.(false)}
            />
          </View>
        ) : null}
      </View>
    );
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
    return <MindMap message={message} notebookId={notebookId} budget={practice.budget} onAction={onMindMapAction} />;
  }

  return <CitationText content={message.content} citations={message.citations} notebookId={notebookId} messageId={message.id} />;
}

type MindMapProps = {
  message: StudioResultProps['message'];
  notebookId?: string | number | null;
  budget?: PracticeProps['budget'];
  onAction?: (action: MindMapAction, line: MindMapLine) => void;
};

/** A mind map as indented lines with their citation chips; with `onAction`, a node opens an action sheet. */
function MindMap({ message, notebookId, budget, onAction }: MindMapProps) {
  const [chosen, setChosen] = useState<MindMapLine | null>(null);

  return (
    <View style={styles.mindMap}>
      {mindMapLines(message.data as MindMapNode).map((line, index) => {
        const label = line.depth === 0 ? line.title : `• ${line.title}`;
        const text = (
          <ThemedText type={line.depth === 0 ? 'smallBold' : 'small'} themeColor={line.depth <= 1 ? 'text' : 'textSecondary'}>
            {label}
          </ThemedText>
        );

        return (
          <View key={index} style={[styles.mindMapRow, { paddingLeft: line.depth * Spacing.three }]}>
            {onAction ? (
              <Pressable
                accessibilityRole="button"
                accessibilityLabel={line.title}
                accessibilityHint="Buka pilihan: jelaskan, latihan, atau jelaskan dengan kata-katamu"
                hitSlop={4}
                style={({ pressed }) => [styles.mindMapNode, pressed && styles.pressed]}
                onPress={() => setChosen(line)}>
                {text}
              </Pressable>
            ) : (
              text
            )}
            {line.citations.length > 0 ? (
              <CitationText content={glossaryCitations(line.citations).trim()} citations={message.citations} notebookId={notebookId} messageId={message.id} />
            ) : null}
          </View>
        );
      })}
      {onAction ? (
        <MindMapActionSheet
          line={chosen}
          budget={budget}
          onClose={() => setChosen(null)}
          onChoose={(action) => {
            const line = chosen;

            setChosen(null);

            if (line) {
              onAction(action, line);
            }
          }}
        />
      ) : null}
    </View>
  );
}

type MindMapActionSheetProps = {
  line: MindMapLine | null;
  budget?: PracticeProps['budget'];
  onClose: () => void;
  onChoose: (action: MindMapAction) => void;
};

/** A mind map node's three actions, each one daily AI request when it runs (as the web's popover). */
function MindMapActionSheet({ line, budget, onClose, onChoose }: MindMapActionSheetProps) {
  const theme = useTheme();
  // A Modal is a window of its own, where SafeAreaView reads no insets: the app's are applied by hand.
  const insets = useSafeAreaInsets();
  const parent = line ? mindMapParent(line) : null;
  const spent = budget?.remaining === 0;

  return (
    <Modal visible={line !== null} transparent animationType="slide" onRequestClose={onClose}>
      <View style={styles.sheetRoot}>
        <Pressable style={styles.backdrop} accessibilityRole="button" accessibilityLabel="Tutup" onPress={onClose} />
        <View style={[styles.sheet, { backgroundColor: theme.panel, paddingBottom: Spacing.three + insets.bottom }]} accessibilityViewIsModal>
          <ThemedText type="subtitle">{line?.title ?? ''}</ThemedText>
          {parent ? (
            <ThemedText type="small" themeColor="textSecondary">
              {`Bagian dari ${parent}`}
            </ThemedText>
          ) : null}
          <Button variant="secondary" icon="chat" label="Jelaskan" disabled={spent} onPress={() => onChoose('explain')} />
          <Button variant="secondary" icon="checklist" label="Latihan" disabled={spent} onPress={() => onChoose('practice')} />
          <Button variant="secondary" icon="sparkles" label="Jelaskan dengan kata-katamu" disabled={spent} onPress={() => onChoose('feedback')} />
          <ThemedText type="caption" themeColor="textTertiary">
            {mindMapBudgetLine(budget)}
          </ThemedText>
          <Button variant="secondary" size="small" label="Batal" onPress={onClose} />
        </View>
      </View>
    </Modal>
  );
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
  mindMapRow: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    alignItems: 'center',
    gap: Spacing.one,
  },
  mindMapNode: {
    minHeight: 44,
    justifyContent: 'center',
    borderRadius: Radius.small,
  },
  pressed: {
    opacity: 0.6,
  },
  sheetRoot: {
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
});
