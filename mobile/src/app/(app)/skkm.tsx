import * as WebBrowser from 'expo-web-browser';
import { Fragment } from 'react';
import { StyleSheet, View } from 'react-native';

import { webUrl } from '@/api/config';
import { ToneBadge } from '@/components/academic/badges';
import type { SkkmEntry, SkkmResponse } from '@/api/types/academic';
import { Button } from '@/components/button';
import { Chip } from '@/components/chip';
import { EmptyState } from '@/components/empty-state';
import { ListRow } from '@/components/list-row';
import { Notice } from '@/components/notice';
import { Screen } from '@/components/screen';
import { Divider, Panel, Section } from '@/components/section';
import { SkeletonRows } from '@/components/skeleton';
import { StatRow } from '@/components/stat-row';
import { StatusPill } from '@/components/status-pill';
import { ThemedText } from '@/components/themed-text';
import { Spacing } from '@/constants/theme';
import { useApiResource } from '@/hooks/use-api-resource';
import { usePortalFile } from '@/hooks/use-portal-file';
import { useTheme } from '@/hooks/use-theme';
import { skkmTone } from '@/lib/academic';

export default function SkkmScreen() {
  const theme = useTheme();
  const skkm = useApiResource<SkkmResponse>('skkm');
  const file = usePortalFile();
  const data = skkm.data?.data;
  const summary = data?.summary;
  const entries = data?.entries ?? [];
  const activities = summary?.points_by_activity ?? [];
  const website = webUrl();

  return (
    <Screen back title="SKKM" subtitle="Satuan Kredit Kegiatan Mahasiswa" refreshing={skkm.isRefreshing}
      dataSavedAt={skkm.savedAt} onRefresh={skkm.refresh}>
      {skkm.error ? <Notice tone="error" message={skkm.error} /> : null}
      {data?.message ? <Notice tone="warning" message={data.message} /> : null}

      {skkm.isLoading ? (
        <Section label="Memuat SKKM">
          <Panel padded={false}>
            <SkeletonRows count={3} />
          </Panel>
        </Section>
      ) : null}

      {summary ? (
        <Section
          label="Poin SKKM"
          accessory={
            data?.graduation_status?.text ? (
              <StatusPill
                label={data.graduation_status.is_met ? 'Memenuhi syarat lulus' : 'Belum memenuhi'}
                tone={data.graduation_status.is_met ? 'positive' : 'attention'}
              />
            ) : null
          }>
          <Panel padded={false}>
            <StatRow
              stats={[
                { label: 'Valid', value: String(summary.valid_points ?? 0), color: theme.success },
                { label: 'Menunggu validasi', value: String(summary.pending_points ?? 0), color: theme.warning },
                { label: 'Tidak valid', value: String(summary.invalid_points ?? 0), color: theme.danger },
              ]}
            />
            <Divider />
            <View style={styles.summaryNote}>
              {summary.potential_points !== null && summary.potential_points !== undefined ? (
                <ThemedText type="small" themeColor="textSecondary">
                  Potensi {summary.potential_points} poin bila semua yang menunggu divalidasi.
                </ThemedText>
              ) : null}
              {data?.graduation_status?.text ? (
                <ThemedText type="small" themeColor="textTertiary">
                  {data.graduation_status.text}
                </ThemedText>
              ) : null}
            </View>
          </Panel>
        </Section>
      ) : null}

      {data?.notice?.text ? <Notice message={data.notice.text} /> : null}

      {activities.length > 0 ? (
        <Section label="Poin per jenis kegiatan">
          <Panel padded={false}>
            {activities.map((activity, index) => (
              <Fragment key={`${activity.activity ?? 'kegiatan'}-${index}`}>
                {index > 0 ? <Divider inset={Spacing.three} /> : null}
                <View style={styles.activity}>
                  <View style={styles.flex}>
                    <ThemedText type="bodyMedium">{activity.activity ?? 'Kegiatan'}</ThemedText>
                    <ThemedText type="small" themeColor="textTertiary">
                      {activity.entries ?? 0} kegiatan
                      {activity.pending_points ? ` · ${activity.pending_points} menunggu` : ''}
                      {activity.invalid_points ? ` · ${activity.invalid_points} tidak valid` : ''}
                    </ThemedText>
                  </View>
                  <ThemedText type="subtitle" style={{ color: theme.success }}>
                    {activity.valid_points ?? 0}
                  </ThemedText>
                </View>
              </Fragment>
            ))}
          </Panel>
        </Section>
      ) : null}

      {!skkm.isLoading && data && entries.length === 0 ? (
        <Section label="Kegiatan">
          <Panel>
            <EmptyState icon="award" title="Belum ada kegiatan" description="Kegiatan yang kamu unggah di portal akan tampil di sini." />
          </Panel>
        </Section>
      ) : null}

      {entries.length > 0 ? (
        <Section label="Kegiatan" accessory={<Chip label={`${entries.length} kegiatan`} />}>
          <Panel padded={false}>
            {entries.map((entry, index) => (
              <Fragment key={`${entry.no ?? index}-${entry.name ?? ''}`}>
                {index > 0 ? <Divider inset={Spacing.three} /> : null}
                <EntryRow entry={entry} busy={file.busyHref === entry.attachment_path} onOpen={() => void file.open(entry.attachment_path, entry.name)} />
              </Fragment>
            ))}
          </Panel>
        </Section>
      ) : null}

      {data?.guide_path ? (
        <Section label="Pedoman">
          <Panel padded={false}>
            <ListRow
              icon="document"
              label={`Pedoman SKKM${data.guide_year ? ` ${data.guide_year}` : ''}`}
              value="Aturan poin dan bukti yang diterima"
              trailingIcon="download"
              onPress={() => void file.open(data.guide_path, `Pedoman SKKM ${data.guide_year ?? ''}`.trim())}
            />
          </Panel>
        </Section>
      ) : null}

      {data ? (
        <Section label="Tambah kegiatan">
          <Panel style={styles.add}>
            <ThemedText type="small" themeColor="textSecondary">
              Kegiatan baru diunggah lewat portal kampus, lengkap dengan buktinya. Setelah itu DPA akan memvalidasinya.
            </ThemedText>
            {data.add_available && website ? (
              <Button
                variant="secondary"
                icon="globe"
                label="Buka WeCIC versi web"
                onPress={() => void WebBrowser.openBrowserAsync(`${website}/skkm`)}
              />
            ) : null}
          </Panel>
        </Section>
      ) : null}
    </Screen>
  );
}

function EntryRow({ entry, busy, onOpen }: { entry: SkkmEntry; busy: boolean; onOpen: () => void }) {
  const theme = useTheme();

  return (
    <View style={styles.entry}>
      <View style={styles.entryTop}>
        <View style={styles.flex}>
          <ThemedText type="bodyMedium">{entry.name ?? 'Kegiatan'}</ThemedText>
          <ThemedText type="small" themeColor="textTertiary">
            {[entry.type, entry.level].filter(Boolean).join(' · ')}
          </ThemedText>
          {entry.activity ? (
            <ThemedText type="caption" themeColor="textQuaternary">
              {entry.activity}
            </ThemedText>
          ) : null}
        </View>
        <ThemedText type="heading" style={{ color: entry.status === 'valid' ? theme.success : theme.textSecondary }}>
          {entry.points_label ?? entry.points ?? 0}
        </ThemedText>
      </View>
      <View style={styles.entryActions}>
        <ToneBadge label={entry.status_label ?? entry.status ?? 'Tidak diketahui'} tone={skkmTone(entry.status)} />
        {entry.attachment_path ? <Chip icon="document" label={busy ? 'Membuka…' : 'Bukti'} onPress={onOpen} /> : null}
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  flex: {
    flex: 1,
    gap: Spacing.half,
  },
  summaryNote: {
    gap: Spacing.one,
    padding: Spacing.three,
  },
  activity: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.three,
    padding: Spacing.three,
  },
  entry: {
    gap: Spacing.two + Spacing.one,
    padding: Spacing.three,
  },
  entryTop: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: Spacing.three,
  },
  entryActions: {
    flexDirection: 'row',
    alignItems: 'center',
    flexWrap: 'wrap',
    gap: Spacing.two,
  },
  add: {
    gap: Spacing.three,
  },
});
