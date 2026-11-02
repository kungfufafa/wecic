import { useRouter } from 'expo-router';
import * as WebBrowser from 'expo-web-browser';
import { Fragment } from 'react';

import type { LibraryResponse, RepositorySummary } from '@/api/types/admin';
import { EmptyState } from '@/components/empty-state';
import { ListRow, ListRowTextInset } from '@/components/list-row';
import { Notice } from '@/components/notice';
import { Screen } from '@/components/screen';
import { Divider, Panel, Section } from '@/components/section';
import { SkeletonRows } from '@/components/skeleton';
import { StatRow } from '@/components/stat-row';
import { useApiResource } from '@/hooks/use-api-resource';

export default function LibraryScreen() {
  const router = useRouter();
  const library = useApiResource<LibraryResponse>('library');
  const data = library.data?.data;
  const links = data?.links ?? [];
  const repository = data?.repository ?? null;

  return (
    <Screen back title="Perpustakaan" subtitle="Tautan perpustakaan dan repositori skripsi" refreshing={library.isRefreshing} onRefresh={library.refresh}>
      {library.error ? <Notice tone="error" message={library.error} /> : null}

      <Section label="Repositori skripsi">
        <Panel padded={false}>
          {repository ? <StatRow stats={repositoryStats(repository)} /> : null}
          {repository ? <Divider inset={0} /> : null}
          <ListRow
            icon="search"
            label="Cari skripsi"
            value="Judul, penulis, pembimbing, atau tahun"
            onPress={() => router.push('/library/theses')}
          />
        </Panel>
      </Section>

      <Section label="Tautan perpustakaan">
        <Panel padded={false}>
          {library.isLoading ? <SkeletonRows count={3} /> : null}
          {!library.isLoading && links.length === 0 && !library.error ? (
            <EmptyState icon="library" title="Belum ada tautan" description="Tautan perpustakaan dari portal akan tampil di sini." />
          ) : null}
          {links.map((link, index) => (
            <Fragment key={link.key}>
              {index > 0 ? <Divider inset={ListRowTextInset} /> : null}
              <ListRow
                icon={link.is_internal ? 'library' : 'globe'}
                label={link.title}
                value={[link.host, link.requires_account ? 'perlu akun' : null].filter(Boolean).join(' · ')}
                trailingIcon={link.is_internal ? 'chevronRight' : 'arrowUpRight'}
                onPress={() =>
                  link.is_internal && link.internal_route?.includes('theses')
                    ? router.push('/library/theses')
                    : void WebBrowser.openBrowserAsync(link.url)
                }
              />
            </Fragment>
          ))}
        </Panel>
      </Section>
    </Screen>
  );
}

function repositoryStats(repository: RepositorySummary) {
  const total = repository.total ?? repository.record_count ?? null;

  return [
    { label: 'Skripsi tercatat', value: total === null ? '–' : total.toLocaleString('id-ID') },
    { label: 'Tahun terbaru', value: repository.latest_academic_year ?? '–' },
    { label: 'Indeks', value: repository.complete ? 'Lengkap' : repository.status === 'pending' ? 'Memuat' : 'Sebagian' },
  ];
}
