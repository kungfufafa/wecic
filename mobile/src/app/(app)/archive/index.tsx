import { useRouter } from 'expo-router';
import { Fragment, useState } from 'react';
import { StyleSheet, Switch, View } from 'react-native';

import type { ArchiveSummaryResponse, BackgroundSync, BackgroundSyncResponse } from '@/api/types/admin';
import { useSession } from '@/auth/session-provider';
import { EmptyState } from '@/components/empty-state';
import { Icon } from '@/components/icon';
import { ListRow, ListRowTextInset } from '@/components/list-row';
import { Notice } from '@/components/notice';
import { Screen } from '@/components/screen';
import { Divider, Panel, Section } from '@/components/section';
import { SkeletonRows } from '@/components/skeleton';
import { StatRow } from '@/components/stat-row';
import { ThemedText } from '@/components/themed-text';
import { Spacing } from '@/constants/theme';
import { useApiResource } from '@/hooks/use-api-resource';
import { useTheme } from '@/hooks/use-theme';
import { fileSize } from '@/lib/admin';
import { formatDateTime } from '@/lib/format';

export default function ArchiveScreen() {
  const theme = useTheme();
  const router = useRouter();
  const { api } = useSession();
  const archive = useApiResource<ArchiveSummaryResponse>('archive');
  const [sync, setSync] = useState<BackgroundSync | null>(null);
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState<{ tone: 'success' | 'error'; text: string } | null>(null);
  const data = archive.data?.data;
  const datasets = data?.datasets ?? [];
  const background = sync ?? data?.background_sync ?? null;
  const versions = datasets.reduce((total, dataset) => total + dataset.versions, 0);
  const suspects = datasets.reduce((total, dataset) => total + dataset.open_suspects, 0);

  async function toggleSync(enabled: boolean) {
    setSaving(true);
    setMessage(null);

    try {
      const response = await api.patch<BackgroundSyncResponse>('archive/background-sync', { enabled });
      setSync(response.data);
      setMessage({ tone: 'success', text: response.message });
    } catch (error) {
      setMessage({ tone: 'error', text: error instanceof Error ? error.message : String(error) });
    } finally {
      setSaving(false);
    }
  }

  return (
    <Screen back title="Arsip data" refreshing={archive.isRefreshing} dataSavedAt={archive.savedAt} onRefresh={archive.refresh}>
      {archive.error ? <Notice tone="error" message={archive.error} /> : null}
      {message ? <Notice tone={message.tone} message={message.text} /> : null}

      {data ? (
        <Section label="Ringkasan">
          <Panel padded={false}>
            <StatRow
              stats={[
                { label: 'Jenis data', value: String(datasets.length) },
                { label: 'Versi', value: String(versions) },
                { label: 'Berkas', value: fileSize(data.files?.bytes) },
              ]}
            />
          </Panel>
        </Section>
      ) : null}

      {suspects > 0 ? <Notice tone="warning" message={`${suspects} versi janggal perlu dicek.`} /> : null}

      {background ? (
        <Section label="Sinkronisasi di background">
          <Panel padded={false} style={styles.syncPanel}>
            <View style={styles.syncRow}>
              <Icon name="sync" size={22} color={theme.text} />
              <View style={styles.flex}>
                <ThemedText style={styles.syncLabel} numberOfLines={1}>
                  Perbarui otomatis
                </ThemedText>
                <ThemedText type="caption" themeColor="textTertiary" numberOfLines={1}>
                  {background.available
                    ? `Tiap ${background.interval_minutes ?? 180} menit · sandi disimpan ${background.credential_days ?? 30} hari`
                    : 'Tidak tersedia di server ini'}
                </ThemedText>
              </View>
              <Switch
                value={background.enabled}
                disabled={!background.available || saving}
                onValueChange={(enabled) => void toggleSync(enabled)}
                trackColor={{ true: theme.success, false: theme.elevated }}
                accessibilityLabel="Sinkronisasi di background"
                accessibilityHint="Mematikannya menghapus kata sandi yang disimpan"
              />
            </View>
            {background.pending_login ? (
              <ThemedText type="caption" themeColor="warning">
                Aktif setelah kamu masuk lagi saat portal online.
              </ThemedText>
            ) : null}
            {background.last_synced_at ? (
              <ThemedText type="caption" themeColor="textTertiary">
                Terakhir sinkron {formatDateTime(background.last_synced_at)}
              </ThemedText>
            ) : null}
            {background.last_error ? (
              <ThemedText type="caption" themeColor="danger" numberOfLines={3}>
                {background.last_error}
              </ThemedText>
            ) : null}
          </Panel>
        </Section>
      ) : null}

      <Section label="Data yang diarsipkan">
        <Panel padded={false}>
          {archive.isLoading ? <SkeletonRows count={3} /> : null}
          {!archive.isLoading && datasets.length === 0 && !archive.error ? (
            <EmptyState icon="archive" title="Arsip masih kosong" />
          ) : null}
          {datasets.map((dataset, index) => (
            <Fragment key={dataset.dataset}>
              {index > 0 ? <Divider inset={ListRowTextInset} /> : null}
              <ListRow
                icon={dataset.open_suspects > 0 ? 'alert' : 'archive'}
                label={dataset.label}
                value={[
                  `${dataset.versions} versi`,
                  dataset.open_suspects > 0 ? `${dataset.open_suspects} perlu dicek` : null,
                  dataset.last_seen_at ? formatDateTime(dataset.last_seen_at) : null,
                ]
                  .filter(Boolean)
                  .join(' · ')}
                onPress={() => router.push({ pathname: '/archive/[dataset]', params: { dataset: dataset.dataset } })}
              />
            </Fragment>
          ))}
        </Panel>
      </Section>
    </Screen>
  );
}

const styles = StyleSheet.create({
  flex: {
    flex: 1,
    gap: Spacing.half,
  },
  syncPanel: {
    gap: Spacing.one,
    paddingHorizontal: Spacing.three + Spacing.half,
    paddingVertical: Spacing.two + Spacing.one,
  },
  syncRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.three - Spacing.half,
  },
  syncLabel: {
    fontSize: 17,
    lineHeight: 22,
  },
});
