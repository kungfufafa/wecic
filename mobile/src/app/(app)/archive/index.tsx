import { useRouter } from 'expo-router';
import { Fragment, useState } from 'react';

import type { ArchiveSummaryResponse, BackgroundSync } from '@/api/types/admin';
import { BackgroundSyncSetting } from '@/components/background-sync-setting';
import { EmptyState } from '@/components/empty-state';
import { ListRow, ListRowTextInset } from '@/components/list-row';
import { Notice } from '@/components/notice';
import { Screen } from '@/components/screen';
import { Divider, Panel, Section } from '@/components/section';
import { SkeletonRows } from '@/components/skeleton';
import { StatRow } from '@/components/stat-row';
import { useApiResource } from '@/hooks/use-api-resource';
import { fileSize } from '@/lib/admin';
import { formatDateTime } from '@/lib/format';

export default function ArchiveScreen() {
  const router = useRouter();
  const archive = useApiResource<ArchiveSummaryResponse>('archive');
  const [sync, setSync] = useState<BackgroundSync | null>(null);
  const data = archive.data?.data;
  const datasets = data?.datasets ?? [];
  const background = sync ?? data?.background_sync ?? null;
  const versions = datasets.reduce((total, dataset) => total + dataset.versions, 0);
  const suspects = datasets.reduce((total, dataset) => total + dataset.open_suspects, 0);

  return (
    <Screen back title="Arsip data" refreshing={archive.isRefreshing} dataSavedAt={archive.savedAt} onRefresh={archive.refresh}>
      {archive.error ? <Notice tone="error" message={archive.error} /> : null}

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
          <Panel padded={false}>
            <BackgroundSyncSetting status={background} onChange={setSync} />
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
