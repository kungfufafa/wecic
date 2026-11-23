import { useLocalSearchParams, useRouter } from 'expo-router';
import { StyleSheet, View } from 'react-native';

import type { NotebookChunk, NotebookSourceChunksResponse } from '@/api/types/classes';
import { Button } from '@/components/button';
import { EmptyState } from '@/components/empty-state';
import { Notice } from '@/components/notice';
import { Screen } from '@/components/screen';
import { Panel, Section } from '@/components/section';
import { SkeletonRows } from '@/components/skeleton';
import { ThemedText } from '@/components/themed-text';
import { Radius, Spacing } from '@/constants/theme';
import { useApiResource } from '@/hooks/use-api-resource';
import { useTheme } from '@/hooks/use-theme';
import { chunkGroups, chunkHeading, citationMissing, citedPosition, pagedSourceParams, sourceChunksPath, type CitationSourceParams } from '@/lib/citations';

/**
 * A source's text as the AI read it (40 chunks at a time), opened from a citation's "Lihat sumber": the cited
 * chunk first, then the chunks around it under "Halaman N" (or "Bagian N" without pages) with the cited one
 * highlighted. Reads stored text only: no AI request, no consent, no budget.
 */
export default function NotebookSourceScreen() {
  const router = useRouter();
  const params = useLocalSearchParams<CitationSourceParams>();
  const resource = useApiResource<NotebookSourceChunksResponse>(sourceChunksPath(params));
  const data = resource.data?.data;
  const chunks = data?.chunks ?? [];
  const cited = citedPosition(params, data?.around);
  const citedChunk = cited === null ? null : (chunks.find((chunk) => chunk.position === cited) ?? null);

  function page(around: number) {
    router.setParams(pagedSourceParams(around, cited));
  }

  return (
    <Screen
      back
      title={data?.source.title ?? 'Sumber'}
      subtitle="Teks yang dibaca AI, bukan tampilan berkas aslinya"
      refreshing={resource.isRefreshing}
      onRefresh={resource.reload}>
      {resource.error ? <Notice tone="error" message={resource.error} /> : null}

      {resource.isLoading ? (
        <Section label="Memuat sumber">
          <Panel padded={false}>
            <SkeletonRows count={4} />
          </Panel>
        </Section>
      ) : null}

      {data && citationMissing(params, cited, chunks) ? (
        <Notice tone="info" message="Bagian yang dirujuk tidak ditemukan lagi di sumber ini; sumbernya mungkin sudah dibaca ulang." />
      ) : null}

      {citedChunk ? (
        <Section label="Bagian yang dirujuk">
          <ChunkPanel chunk={citedChunk} cited heading={chunkHeading(citedChunk)} />
        </Section>
      ) : null}

      {data ? (
        <Section label="Isi sumber">
          {chunks.length === 0 ? (
            <Panel padded={false}>
              <EmptyState icon="document" title="Belum ada teks" description="Sumber ini belum punya teks yang bisa ditampilkan." />
            </Panel>
          ) : null}
          {data.has_before && chunks.length > 0 ? (
            <Button size="small" variant="secondary" label="Muat bagian sebelumnya" onPress={() => page(chunks[0].position - 25)} />
          ) : null}
          {chunkGroups(chunks).map((group) => (
            <Panel key={`${group.heading}-${group.chunks[0].position}`} style={styles.group}>
              <ThemedText type="label">{group.heading}</ThemedText>
              {group.chunks.map((chunk) => (
                <ChunkText key={chunk.position} chunk={chunk} cited={chunk.position === cited} />
              ))}
            </Panel>
          ))}
          {data.has_after && chunks.length > 0 ? (
            <Button size="small" variant="secondary" label="Muat bagian berikutnya" onPress={() => page(chunks[chunks.length - 1].position + 16)} />
          ) : null}
        </Section>
      ) : null}
    </Screen>
  );
}

function ChunkPanel({ chunk, heading, cited }: { chunk: NotebookChunk; heading: string; cited: boolean }) {
  return (
    <Panel style={styles.group}>
      <ThemedText type="label">{heading}</ThemedText>
      <ChunkText chunk={chunk} cited={cited} />
    </Panel>
  );
}

/** A chunk's text, set as text; the cited one on the accent tint. */
function ChunkText({ chunk, cited }: { chunk: NotebookChunk; cited: boolean }) {
  const theme = useTheme();

  return (
    <View style={cited ? [styles.cited, { backgroundColor: theme.accentTint }] : null} accessibilityLabel={cited ? 'Bagian yang dirujuk' : undefined}>
      <ThemedText type="small" themeColor={cited ? 'text' : 'textSecondary'} selectable>
        {chunk.content}
      </ThemedText>
    </View>
  );
}

const styles = StyleSheet.create({
  group: {
    gap: Spacing.two,
  },
  cited: {
    borderRadius: Radius.small,
    paddingHorizontal: Spacing.two,
    paddingVertical: Spacing.one + Spacing.half,
  },
});
