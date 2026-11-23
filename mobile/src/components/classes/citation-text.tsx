import { useRouter } from 'expo-router';
import { Fragment, useState } from 'react';
import { Modal, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import type { NotebookCitation } from '@/api/types/classes';
import { Button } from '@/components/button';
import { ThemedText, type ThemedTextProps } from '@/components/themed-text';
import { Radius, Spacing } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';
import { citationSourceParams, citationTokens, findCitation, markdownText } from '@/lib/citations';

type CitationTextProps = Pick<ThemedTextProps, 'type' | 'themeColor'> & {
  /** Markdown the assistant wrote, with its `[n]` citations. */
  content: string | null | undefined;
  /** The output's citations; a number they do not list stays text. */
  citations?: NotebookCitation[] | null;
  /** The notebook and message the citations belong to, for "Lihat sumber". */
  notebookId?: string | number | null;
  messageId?: number | null;
  /** Called before "Lihat sumber" opens the source, e.g. to close a modal the text sits in. */
  onNavigate?: () => void;
};

/**
 * An answer with its citations as tappable chips (the server's citation rule: `arr[0]` stays text). A chip opens a
 * sheet with the source title, page and excerpt, and "Lihat sumber" to read it in its source.
 */
export function CitationText({ content, citations, notebookId, messageId, onNavigate, type = 'small', themeColor = 'textSecondary' }: CitationTextProps) {
  const theme = useTheme();
  const [open, setOpen] = useState<NotebookCitation | null>(null);

  return (
    <>
      <ThemedText type={type} themeColor={themeColor} selectable>
        {citationTokens(markdownText(content)).map((token, index) => {
          if (token.type === 'text') {
            return <Fragment key={index}>{token.text}</Fragment>;
          }

          const citation = findCitation(citations, token.n);

          if (!citation) {
            return <Fragment key={index}>{`[${token.n}]`}</Fragment>;
          }

          return (
            <Text
              key={index}
              accessibilityRole="button"
              accessibilityLabel={`Rujukan ${token.n}: ${citation.source_title ?? 'sumber'}`}
              onPress={() => setOpen(citation)}
              style={[styles.chip, { color: theme.brand, backgroundColor: theme.brandTint }]}>
              {` ${token.n} `}
            </Text>
          );
        })}
      </ThemedText>
      <CitationSheet citation={open} notebookId={notebookId} messageId={messageId} onNavigate={onNavigate} onClose={() => setOpen(null)} />
    </>
  );
}

type CitationSheetProps = {
  citation: NotebookCitation | null;
  notebookId?: string | number | null;
  messageId?: number | null;
  onNavigate?: () => void;
  onClose: () => void;
};

/** One citation: source title, page, the excerpt the AI read, and "Lihat sumber". */
export function CitationSheet({ citation, notebookId, messageId, onNavigate, onClose }: CitationSheetProps) {
  const theme = useTheme();
  const router = useRouter();
  // A Modal is a window of its own, where SafeAreaView reads no insets: the app's are applied by hand.
  const insets = useSafeAreaInsets();
  const params = citation && notebookId != null && messageId != null ? citationSourceParams(notebookId, messageId, citation) : null;

  if (!citation) {
    return null;
  }

  return (
    <Modal visible transparent animationType="slide" onRequestClose={onClose}>
      <View style={styles.root}>
        <Pressable style={styles.backdrop} accessibilityRole="button" accessibilityLabel="Tutup" onPress={onClose} />
        <View style={[styles.sheet, { backgroundColor: theme.panel, paddingBottom: Spacing.three + insets.bottom }]} accessibilityViewIsModal>
          <ThemedText type="label">{`Rujukan ${citation.n}`}</ThemedText>
          <ThemedText type="subtitle">{citation.source_title ?? 'Sumber'}</ThemedText>
          {citation.page ? (
            <ThemedText type="small" themeColor="textTertiary">
              {`Halaman ${citation.page}`}
            </ThemedText>
          ) : null}
          <ScrollView style={styles.excerpt}>
            <ThemedText type="small" themeColor="textSecondary" selectable>
              {citation.excerpt ?? ''}
            </ThemedText>
          </ScrollView>
          {params ? (
            <Button
              variant="secondary"
              icon="document"
              label="Lihat sumber"
              onPress={() => {
                onClose();
                onNavigate?.();
                router.push({ pathname: '/notebooks/[id]/sources/[sourceId]', params });
              }}
            />
          ) : null}
        </View>
      </View>
    </Modal>
  );
}

const styles = StyleSheet.create({
  chip: {
    fontWeight: 600,
  },
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
  excerpt: {
    maxHeight: 240,
  },
});
