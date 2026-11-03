import { StyleSheet, View } from 'react-native';

import type { ProfileResponse } from '@/api/types/admin';
import { Avatar } from '@/components/avatar';
import { InfoRows } from '@/components/info-rows';
import { Notice } from '@/components/notice';
import { Screen } from '@/components/screen';
import { Panel, Section } from '@/components/section';
import { SkeletonRows } from '@/components/skeleton';
import { StatusPill } from '@/components/status-pill';
import { ThemedText } from '@/components/themed-text';
import { Spacing } from '@/constants/theme';
import { useApiResource } from '@/hooks/use-api-resource';
import { profileRows } from '@/lib/admin';
import { initials } from '@/lib/format';

export default function BiodataScreen() {
  const profile = useApiResource<ProfileResponse>('profile');
  const data = profile.data?.profile;
  const personal = data?.personal_data ?? {};
  const name = personal.nama ?? null;
  const status = personal.status ?? null;

  return (
    <Screen back title="Biodata" subtitle="Data pribadi dari portal kampus" refreshing={profile.isRefreshing}
      dataSavedAt={profile.savedAt} onRefresh={profile.refresh}>
      {profile.error ? <Notice tone="error" message={profile.error} /> : null}

      <Section label="Mahasiswa">
        <Panel padded={!profile.isLoading}>
          {profile.isLoading ? (
            <SkeletonRows count={1} />
          ) : (
            <View style={styles.identity}>
              <Avatar initials={initials({ name })} size={64} />
              <View style={styles.identityText}>
                <ThemedText type="subtitle" numberOfLines={2}>
                  {name ?? 'Mahasiswa'}
                </ThemedText>
                <ThemedText type="small" themeColor="textTertiary">
                  {[personal.nim, personal.program_studi].filter(Boolean).join(' · ')}
                </ThemedText>
                {status ? <StatusPill label={status} tone={/aktif/i.test(status) ? 'positive' : 'neutral'} /> : null}
              </View>
            </View>
          )}
        </Panel>
      </Section>

      {data ? (
        <>
          <Section label="Data pribadi">
            <Panel padded={false}>
              <InfoRows rows={profileRows(data.personal_data).filter((row) => row.label !== 'Nama')} />
            </Panel>
          </Section>
          <Section label="Kontak">
            <Panel padded={false}>
              <InfoRows rows={profileRows(data.contact_data)} empty="Kontak belum diisi di portal." />
            </Panel>
          </Section>
          <Section label="Pendidikan sebelumnya">
            <Panel padded={false}>
              <InfoRows rows={profileRows(data.education_data)} empty="Data pendidikan belum diisi di portal." />
            </Panel>
          </Section>
          <ThemedText type="caption" themeColor="textQuaternary" style={styles.footnote}>
            Data ini dibaca dari portal kampus. Untuk mengubahnya, hubungi BAAK.
          </ThemedText>
        </>
      ) : null}
    </Screen>
  );
}

const styles = StyleSheet.create({
  identity: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.three,
  },
  identityText: {
    flex: 1,
    gap: Spacing.two - Spacing.half,
  },
  footnote: {
    textAlign: 'center',
    paddingHorizontal: Spacing.three,
  },
});
