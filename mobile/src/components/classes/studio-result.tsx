import { Fragment } from 'react';
import { StyleSheet, View } from 'react-native';

import type { NotebookMessage } from '@/api/types/classes';
import { Divider } from '@/components/section';
import { ThemedText } from '@/components/themed-text';
import { Radius, Spacing } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';
import { mindMapLines, type MindMapNode } from '@/lib/classes';

type QuizItem = { question?: string; options?: string[]; answer_index?: number; explanation?: string };
type CardItem = { front?: string; back?: string };
type TermItem = { term?: string; definition?: string };

/** A studio result: plain text, or the quiz, flashcards, glossary or mind map it holds. */
export function StudioResult({ message }: { message: NotebookMessage }) {
  const theme = useTheme();
  const data = message.data;

  if (message.kind === 'quiz' && Array.isArray(data)) {
    return (
      <View style={styles.list}>
        {(data as QuizItem[]).map((item, index) => (
          <Fragment key={index}>
            {index > 0 ? <Divider /> : null}
            <View style={styles.item}>
              <ThemedText type="smallBold">{`${index + 1}. ${item.question ?? ''}`}</ThemedText>
              {(item.options ?? []).map((option, optionIndex) => {
                const correct = optionIndex === item.answer_index;

                return (
                  <View key={optionIndex} style={[styles.option, { backgroundColor: correct ? theme.successTint : theme.elevated }]}>
                    <ThemedText type="small" style={{ color: correct ? theme.success : theme.textSecondary }}>
                      {`${String.fromCharCode(65 + optionIndex)}. ${option}`}
                    </ThemedText>
                  </View>
                );
              })}
              {item.explanation ? (
                <ThemedText type="small" themeColor="textTertiary">
                  {item.explanation}
                </ThemedText>
              ) : null}
            </View>
          </Fragment>
        ))}
      </View>
    );
  }

  if ((message.kind === 'flashcards' || message.kind === 'glossary') && Array.isArray(data)) {
    const pairs = (data as (CardItem & TermItem)[]).map((item) =>
      message.kind === 'flashcards' ? { head: item.front, body: item.back } : { head: item.term, body: item.definition },
    );

    return (
      <View style={styles.list}>
        {pairs.map((pair, index) => (
          <Fragment key={index}>
            {index > 0 ? <Divider /> : null}
            <View style={styles.item}>
              <ThemedText type="smallBold">{pair.head ?? ''}</ThemedText>
              <ThemedText type="small" themeColor="textSecondary">
                {pair.body ?? ''}
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
  option: {
    paddingHorizontal: Spacing.two + Spacing.half,
    paddingVertical: Spacing.one + Spacing.half,
    borderRadius: Radius.small,
  },
  mindMap: {
    gap: Spacing.one + Spacing.half,
  },
});
