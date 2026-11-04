import * as WebBrowser from 'expo-web-browser';
import { Fragment } from 'react';
import { StyleSheet, View } from 'react-native';

import { webUrl } from '@/api/config';
import { ToneBadge } from '@/components/academic/badges';
import type { SkkmEntry, SkkmResponse } from '@/api/types/academic';
import { Chip } from '@/components/chip';
import { EmptyState } from '@/components/empty-state';
import { Notice } from '@/components/notice';
import { Screen } from '@/components/screen';
import { Divider, Panel, Section } from '@/components/section';
import { SettingsRow } from '@/components/settings-row';
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
  const potential =
    summary?.potential_points !== null && summary?.potential_points !== undefined ? `Potensi ${summary.potential_points} poin setelah validasi` : null;

  return (
    <Screen back title="SKKM" refreshing={skkm.isRefreshing}
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
                label={data.graduation_status.is_met ? 'Memenuhi syarat' : 'Belum memenuhi'}
                tone={data.graduation_status.is_met ? 'positive' : 'attention'}
              />
            ) : null
          }>
          <Panel padded={false}>
            <StatRow
              stats={[
                { label: 'Valid', value: String(summary.valid_points ?? 0), color: theme.success },
                { label: 'Menunggu', value: String(summary.pending_points ?? 0), color: theme.warning },
                { label: 'Tidak valid', value: String(summary.invalid_points ?? 0), color: theme.danger },
              ]}
            />
            {potential || data?.graduation_status?.text ? (
              <>
                <Divider />
                <ThemedText type="small" themeColor="textTertiary" style={styles.summaryNote}>
                  {[potential, data?.graduation_status?.text].filter(Boolean).join(' · ')}
                </ThemedText>
              </>
            ) : null}
          </Panel>
        </Section>
      ) : null}

      {data?.notice?.text ? <Notice message={data.notice.text} /> : null}

      {activities.length > 0 ? (
        <Section label="Poin per jenis">
          <Panel padded={false}>
            {activities.map((activity, index) => (
              <Fragment key={`${activity.activity ?? 'kegiatan'}-${index}`}>
                {index > 0 ? <Divider /> : null}
                <View style={styles.activity}>
                  <View style={styles.flex}>
                    <ThemedText type="bodyMedium" numberOfLines={1}>
                      {activity.activity ?? 'Kegiatan'}
                    </ThemedText>
                    <ThemedText type="small" themeColor="textTertiary" numberOfLines={1}>
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
            <EmptyState icon="award" title="Belum ada kegiatan" />
          </Panel>
        </Section>
      ) : null}

      {entries.length > 0 ? (
        <Section label="Kegiatan" accessory={<Chip label={`${entries.length} kegiatan`} />}>
          <Panel padded={false}>
            {entries.map((entry, index) => (
              <Fragment key={`${entry.no ?? index}-${entry.name ?? ''}`}>
                {index > 0 ? <Divider /> : null}
                <EntryRow entry={entry} busy={file.busyHref === entry.attachment_path} onOpen={() => void file.open(entry.attachment_path, entry.name)} />
              </Fragment>
            ))}
          </Panel>
        </Section>
      ) : null}

      {data?.guide_path || (data?.add_available && website) ? (
        <Section label="Lainnya">
          <Panel padded={false}>
            {data.guide_path ? (
              <SettingsRow
                icon="documentOutline"
                label={`Pedoman SKKM${data.guide_year ? ` ${data.guide_year}` : ''}`}
                detail={file.busyHref === data.guide_path ? 'Membuka…' : null}
                trailingIcon="download"
                accessibilityHint="Aturan poin dan bukti yang diterima"
                onPress={() => void file.open(data.guide_path, `Pedoman SKKM ${data.guide_year ?? ''}`.trim())}
              />
            ) : null}
            {data.guide_path && data.add_available && website ? <Divider /> : null}
            {data.add_available && website ? (
              <SettingsRow
                icon="globe"
                label="Tambah kegiatan"
                trailingIcon="arrowUpRight"
                accessibilityHint="Buka WeCIC versi web untuk mengunggah kegiatan beserta buktinya, lalu DPA memvalidasinya"
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
  const meta = [entry.type, entry.level, entry.activity].filter(Boolean).join(' · ');

  return (
    <View style={styles.entry}>
      <View style={styles.entryTop}>
        <ThemedText type="bodyMedium" style={styles.flex} numberOfLines={2}>
          {entry.name ?? 'Kegiatan'}
        </ThemedText>
        <ThemedText type="subtitle" style={{ color: entry.status === 'valid' ? theme.success : theme.textSecondary }}>
          {entry.points_label ?? entry.points ?? 0}
        </ThemedText>
      </View>
      {meta ? (
        <ThemedText type="small" themeColor="textTertiary" numberOfLines={1}>
          {meta}
        </ThemedText>
      ) : null}
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
    paddingVertical: Spacing.three - Spacing.one,
    paddingHorizontal: Spacing.three,
  },
  activity: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.three - Spacing.one,
    paddingVertical: Spacing.three - Spacing.one,
    paddingHorizontal: Spacing.three,
  },
  entry: {
    gap: Spacing.one,
    paddingVertical: Spacing.three - Spacing.one,
    paddingHorizontal: Spacing.three,
  },
  entryTop: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: Spacing.three - Spacing.one,
  },
  entryActions: {
    flexDirection: 'row',
    alignItems: 'center',
    flexWrap: 'wrap',
    gap: Spacing.two,
    marginTop: Spacing.one,
  },
});
