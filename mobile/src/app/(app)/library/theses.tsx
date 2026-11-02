import { Fragment, useEffect, useState } from 'react';
import { ScrollView, StyleSheet, View } from 'react-native';

import type { Facet, ThesesResponse, ThesisRecord } from '@/api/types/admin';
import { Button } from '@/components/button';
import { Chip } from '@/components/chip';
import { EmptyState } from '@/components/empty-state';
import { Icon } from '@/components/icon';
import { MetaRow } from '@/components/meta-row';
import { Notice } from '@/components/notice';
import { Screen } from '@/components/screen';
import { Divider, Panel, Section } from '@/components/section';
import { SkeletonRows } from '@/components/skeleton';
import { TextField } from '@/components/text-field';
import { ThemedText } from '@/components/themed-text';
import { Spacing } from '@/constants/theme';
import { useApiResource } from '@/hooks/use-api-resource';
import { usePortalFile } from '@/hooks/use-portal-file';
import { useTheme } from '@/hooks/use-theme';
import { thesesQuery } from '@/lib/admin';

type FacetKey = 'prodi' | 'ta' | 'pembimbing';

const SORTS = [
  { key: 'terbaru', label: 'Terbaru' },
  { key: 'judul', label: 'Judul' },
  { key: 'relevansi', label: 'Relevan' },
];

const FACET_LABELS: Record<FacetKey, string> = { prodi: 'Program studi', ta: 'Tahun', pembimbing: 'Pembimbing' };

export default function ThesesScreen() {
  const [query, setQuery] = useState('');
  const [debouncedQuery, setDebouncedQuery] = useState('');
  const [filters, setFilters] = useState<Record<FacetKey, string | null>>({ prodi: null, ta: null, pembimbing: null });
  const [sort, setSort] = useState<string | null>(null);
  const [page, setPage] = useState(1);
  const path = `library/theses${thesesQuery({ q: debouncedQuery, ...filters, sort, page })}`;
  const theses = useApiResource<ThesesResponse>(path);
  const meta = theses.data?.meta;
  const items = theses.data?.data.items ?? [];
  const lastPage = meta?.last_page ?? 1;
  const filtering = debouncedQuery !== '' || Object.values(filters).some(Boolean);

  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedQuery(query.trim());
      setPage(1);
    }, 400);

    return () => clearTimeout(timer);
  }, [query]);

  function toggleFacet(key: FacetKey, value: string) {
    setFilters((current) => ({ ...current, [key]: current[key] === value ? null : value }));
    setPage(1);
  }

  function clearAll() {
    setQuery('');
    setDebouncedQuery('');
    setFilters({ prodi: null, ta: null, pembimbing: null });
    setSort(null);
    setPage(1);
  }

  return (
    <Screen
      back
      title="Repositori skripsi"
      subtitle={meta?.total !== undefined ? `${meta.total.toLocaleString('id-ID')} skripsi ditemukan` : 'Skripsi mahasiswa UCIC'}
      refreshing={theses.isRefreshing}
      onRefresh={theses.refresh}>
      <Section label="Cari">
        <Panel style={styles.filters}>
          <TextField
            icon="search"
            placeholder="Judul, penulis, NIM, atau kata kunci"
            value={query}
            onChangeText={setQuery}
            clearable
            autoCorrect={false}
            autoCapitalize="none"
            returnKeyType="search"
          />
          <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.chips}>
            {SORTS.map((option) => (
              <Chip
                key={option.key}
                label={option.label}
                selected={(sort ?? meta?.sort) === option.key}
                onPress={() => {
                  setSort(option.key);
                  setPage(1);
                }}
              />
            ))}
          </ScrollView>
          {(['prodi', 'ta', 'pembimbing'] as FacetKey[]).map((key) => (
            <FacetRow key={key} label={FACET_LABELS[key]} facets={meta?.facets?.[key] ?? []} selected={filters[key]} onToggle={(value) => toggleFacet(key, value)} />
          ))}
        </Panel>
      </Section>

      {theses.error ? <Notice tone="error" message={theses.error} /> : null}
      {meta?.index?.partial ? <Notice message="Indeks repositori masih dimuat dari portal, jadi hasilnya belum lengkap." /> : null}

      <Section label={lastPage > 1 ? `Halaman ${page} dari ${lastPage}` : 'Hasil'}>
        <Panel padded={false}>
          {theses.isLoading ? <SkeletonRows count={3} /> : null}
          {!theses.isLoading && items.length === 0 && !theses.error ? (
            <EmptyState
              icon="library"
              title="Tidak ada skripsi yang cocok"
              description="Coba kata lain atau hapus filter."
              action={filtering ? { label: 'Hapus filter', icon: 'close', onPress: clearAll } : null}
            />
          ) : null}
          {items.map((record, index) => (
            <Fragment key={record.record_key}>
              {index > 0 ? <Divider inset={Spacing.three} /> : null}
              <ThesisRow record={record} />
            </Fragment>
          ))}
        </Panel>
      </Section>

      {lastPage > 1 ? (
        <View style={styles.paging}>
          <Button variant="secondary" size="small" label="Sebelumnya" disabled={page <= 1} onPress={() => setPage((current) => Math.max(1, current - 1))} />
          <Button variant="secondary" size="small" label="Berikutnya" disabled={page >= lastPage} onPress={() => setPage((current) => current + 1)} />
        </View>
      ) : null}
    </Screen>
  );
}

function FacetRow({ label, facets, selected, onToggle }: { label: string; facets: Facet[]; selected: string | null; onToggle: (value: string) => void }) {
  if (facets.length === 0) {
    return null;
  }

  return (
    <View style={styles.facet}>
      <ThemedText type="caption" themeColor="textTertiary" style={styles.facetLabel}>
        {label}
      </ThemedText>
      <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.chips}>
        {facets.map((facet) => (
          <Chip
            key={facet.key ?? facet.value}
            label={facet.count ? `${facet.value} (${facet.count})` : facet.value}
            selected={selected === facet.value}
            onPress={() => onToggle(facet.value)}
          />
        ))}
      </ScrollView>
    </View>
  );
}

function ThesisRow({ record }: { record: ThesisRecord }) {
  const theme = useTheme();
  const file = usePortalFile();
  const advisors = record.advisors?.map((advisor) => advisor.name) ?? [record.advisor_1, record.advisor_2].filter((name): name is string => Boolean(name));

  return (
    <View style={styles.row}>
      <ThemedText type="bodyMedium" selectable>
        {record.title ?? 'Tanpa judul'}
      </ThemedText>
      <MetaRow icon="person" text={[record.author, record.nim].filter(Boolean).join(' · ')} />
      <MetaRow icon="graduationOutline" text={[record.program, record.academic_year].filter(Boolean).join(' · ')} />
      {advisors.length > 0 ? <MetaRow icon="idCard" text={advisors.join(', ')} /> : null}
      {record.file_path ? (
        <View style={styles.fileRow}>
          <Chip icon="document" label={file.busyHref === record.file_path ? 'Membuka…' : 'Buka berkas'} onPress={() => void file.open(record.file_path, record.title)} />
        </View>
      ) : (
        <View style={styles.fileRow}>
          <Icon name="info" size={14} color={theme.textQuaternary} />
          <ThemedText type="caption" themeColor="textQuaternary">
            Berkas tidak tersedia
          </ThemedText>
        </View>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  filters: {
    gap: Spacing.two + Spacing.one,
    padding: Spacing.two,
  },
  chips: {
    gap: Spacing.two,
    paddingHorizontal: Spacing.one,
  },
  facet: {
    gap: Spacing.one + Spacing.half,
  },
  facetLabel: {
    paddingHorizontal: Spacing.two,
  },
  row: {
    gap: Spacing.one + Spacing.half,
    padding: Spacing.three,
  },
  fileRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.one + Spacing.half,
    marginTop: Spacing.one,
  },
  paging: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    gap: Spacing.two,
  },
});
