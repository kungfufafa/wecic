import { Fragment } from 'react';
import { StyleSheet, View } from 'react-native';

import { FlashcardDeck } from '@/components/classes/flashcard-deck';
import { QuizPractice, type PracticeProps } from '@/components/classes/quiz-practice';
import { Divider } from '@/components/section';
import { ThemedText } from '@/components/themed-text';
import { Spacing } from '@/constants/theme';
import { mindMapLines, type MindMapNode } from '@/lib/classes';

type TermItem = { term?: string; definition?: string };

/**
 * A studio result: plain text, or the quiz (QuizPractice), flashcards (FlashcardDeck), glossary or mind map it holds.
 * The practice callbacks reach the quiz and flashcards only.
 */
export function StudioResult({ message, ...practice }: PracticeProps) {
  const data = message.data;

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
              <ThemedText type="small" themeColor="textSecondary">
                {item.definition ?? ''}
              </ThemedText>
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

  return (
    <ThemedText type="small" themeColor="textSecondary" selectable>
      {plainText(message.content)}
    </ThemedText>
  );
}

/** Markdown the assistant writes, readable as plain text: no `**`, `#` or `[n]` markers left. */
export function plainText(content: string | null | undefined): string {
  return (content ?? '')
    .replace(/\*\*(.+?)\*\*/g, '$1')
    .replace(/^#{1,6}\s+/gm, '')
    .replace(/^\s*[-*]\s+/gm, '• ')
    .trim();
}

const styles = StyleSheet.create({
  list: {
    gap: Spacing.two + Spacing.half,
  },
  item: {
    gap: Spacing.one + Spacing.half,
  },
  mindMap: {
    gap: Spacing.one + Spacing.half,
  },
});
