import { useLocalSearchParams } from 'expo-router';
import { Fragment, useState } from 'react';
import { ScrollView, StyleSheet, View } from 'react-native';

import type { ArchiveVersion, ArchiveVersionsResponse } from '@/api/types/admin';
import { useSession } from '@/auth/session-provider';
import { Button } from '@/components/button';
import { Chip } from '@/components/chip';
import { EmptyState } from '@/components/empty-state';
import { Notice } from '@/components/notice';
import { Screen } from '@/components/screen';
import { Divider, Panel, Section } from '@/components/section';
import { SkeletonRows } from '@/components/skeleton';
import { StatusPill } from '@/components/status-pill';
import { ThemedText } from '@/components/themed-text';
import { Spacing } from '@/constants/theme';
import { useApiResource } from '@/hooks/use-api-resource';
import { archiveStatusLook } from '@/lib/admin';
import { formatDateTime } from '@/lib/format';

export default function ArchiveDatasetScreen() {
  const { dataset } = useLocalSearchParams<{ dataset: string }>();
  const { api } = useSession();
  const [paramsHash, setParamsHash] = useState<string | null>(null);
  const [busy, setBusy] = useState<number | null>(null);
  const [message, setMessage] = useState<{ tone: 'success' | 'error'; text: string } | null>(null);
  const valid = typeof dataset === 'string' && /^[a-z_]+$/.test(dataset);
  const path = valid ? `archive/${dataset}${paramsHash ? `?params_hash=${paramsHash}` : ''}` : null;
  const resource = useApiResource<ArchiveVersionsResponse>(path);
  const data = resource.data?.data;
  const groups = data?.groups ?? [];
  const versions = data?.versions ?? [];
  const selected = paramsHash ?? data?.selected_params_hash ?? null;

  async function decide(version: ArchiveVersion, decision: 'accept' | 'reject') {
    setBusy(version.id);
    setMessage(null);

    try {
      const response = await api.post(`archive/versions/${version.id}/${decision}`);
      setMessage({ tone: 'success', text: response.message });
      await resource.reload();
    } catch (error) {
      setMessage({ tone: 'error', text: error instanceof Error ? error.message : String(error) });
    } finally {
      setBusy(null);
    }
  }

  return (
    <Screen back title={data?.label ?? 'Arsip'} refreshing={resource.isRefreshing} dataSavedAt={resource.savedAt} onRefresh={resource.refresh}>
      {resource.error ? <Notice tone="error" message={resource.error} /> : null}
      {message ? <Notice tone={message.tone} message={message.text} /> : null}

      {groups.length > 1 ? (
        <Section label="Pilih data">
          <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.chips}>
            {groups.map((group, index) => (
              <Chip
                key={group.params_hash}
                label={group.subject?.trim() || `Kelompok ${index + 1}`}
                selected={selected === group.params_hash}
                onPress={() => setParamsHash(group.params_hash)}
              />
            ))}
          </ScrollView>
        </Section>
      ) : null}

      <Section label={`${versions.length} versi`}>
        <Panel padded={false}>
          {resource.isLoading ? <SkeletonRows count={2} /> : null}
          {!resource.isLoading && versions.length === 0 && !resource.error ? (
            <EmptyState icon="archive" title="Belum ada versi" />
          ) : null}
          {versions.map((version, index) => {
            const look = archiveStatusLook(version.status);

            return (
              <Fragment key={version.id}>
                {index > 0 ? <Divider /> : null}
                <View style={styles.row}>
                  <View style={styles.rowTop}>
                    <ThemedText type="bodyMedium" style={styles.flex} numberOfLines={1}>
                      {version.is_current ? 'Versi saat ini' : `Versi #${version.id}`}
                    </ThemedText>
                    <StatusPill label={look.label} tone={look.tone} />
                  </View>
                  <ThemedText type="small" themeColor="textTertiary" numberOfLines={2}>
                    {[
                      version.first_seen_at ? `Pertama ${formatDateTime(version.first_seen_at)}` : null,
                      version.last_seen_at ? `terakhir ${formatDateTime(version.last_seen_at)}` : null,
                      version.seen_count ? `${version.seen_count}× terlihat` : null,
                    ]
                      .filter(Boolean)
                      .join(' · ')}
                  </ThemedText>
                  {(version.findings ?? []).map((finding, findingIndex) => (
                    <ThemedText key={`${finding.rule}-${findingIndex}`} type="small" themeColor="warning">
                      {finding.message ?? finding.label}
                    </ThemedText>
                  ))}
                  {version.status === 'suspect' ? (
                    <View style={styles.actions}>
                      <Button size="small" label="Terima" loading={busy === version.id} onPress={() => void decide(version, 'accept')} />
                      <Button size="small" variant="danger" label="Tolak" disabled={busy === version.id} onPress={() => void decide(version, 'reject')} />
                    </View>
                  ) : null}
                </View>
              </Fragment>
            );
          })}
        </Panel>
      </Section>
    </Screen>
  );
}

const styles = StyleSheet.create({
  flex: {
    flex: 1,
  },
  chips: {
    gap: Spacing.two,
    paddingHorizontal: Spacing.one,
  },
  row: {
    gap: Spacing.one,
    paddingHorizontal: Spacing.three + Spacing.half,
    paddingVertical: Spacing.two + Spacing.one,
  },
  rowTop: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.two,
  },
  actions: {
    flexDirection: 'row',
    gap: Spacing.two,
    marginTop: Spacing.half,
  },
});
