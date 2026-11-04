import Constants from 'expo-constants';
import { useRouter } from 'expo-router';
import * as WebBrowser from 'expo-web-browser';
import { useState } from 'react';
import { Alert, Platform, StyleSheet, View } from 'react-native';

import { apiBaseUrl, webUrl } from '@/api/config';
import { useSession } from '@/auth/session-provider';
import { Avatar } from '@/components/avatar';
import { BrandLogo } from '@/components/brand-logo';
import { Button } from '@/components/button';
import { ListRow, ListRowTextInset } from '@/components/list-row';
import { MetaRow } from '@/components/meta-row';
import { Screen } from '@/components/screen';
import { Divider, Panel, Section } from '@/components/section';
import { StatusPill } from '@/components/status-pill';
import { ThemedText } from '@/components/themed-text';
import { Spacing } from '@/constants/theme';
import { clearKnownUser, useKnownUser } from '@/hooks/use-known-user';
import { useUnreadCountValue } from '@/hooks/use-unread-count';
import { initials, studentNumber } from '@/lib/format';
import { realtimeStatusLabel } from '@/lib/realtime';
import type { PushRegistration } from '@/push/push-notifications';
import { useRealtimeStatus } from '@/realtime/portal-realtime';

export default function MoreScreen() {
  const router = useRouter();
  const { state, push, signOut } = useSession();
  const [signingOut, setSigningOut] = useState(false);
  const knownUser = useKnownUser();
  const unreadCount = useUnreadCountValue();
  const realtimeStatus = useRealtimeStatus();
  const user = knownUser ?? (state.status === 'signedIn' ? state.user : null);
  const offline = state.status === 'signedIn' && state.offline;
  const nim = studentNumber(user);
  const website = webUrl();

  function leave() {
    setSigningOut(true);
    clearKnownUser();
    void signOut();
  }

  function confirmSignOut() {
    const title = 'Keluar dari WeCIC?';
    const message = 'Token di perangkat ini dicabut dan notifikasi push berhenti.';

    // The web preview has no native alert.
    if (Platform.OS === 'web') {
      if (window.confirm(`${title}\n${message}`)) {
        leave();
      }

      return;
    }

    Alert.alert(title, message, [
      { text: 'Batal', style: 'cancel' },
      { text: 'Keluar', style: 'destructive', onPress: leave },
    ]);
  }

  return (
    <Screen title="Lainnya">
      <Section label="Akun">
        <Panel style={styles.profile}>
          <Avatar initials={initials(user)} size={64} />
          <View style={styles.profileText}>
            <ThemedText type="subtitle" numberOfLines={2}>
              {user?.name ?? 'Mahasiswa'}
            </ThemedText>
            <MetaRow icon="idCard" text={nim ? `NIM ${nim}` : null} />
            <StatusPill label={offline ? 'Mode offline' : 'Terhubung ke portal'} tone={offline ? 'attention' : 'positive'} />
          </View>
        </Panel>
      </Section>

      <Section label="Layanan">
        <Panel padded={false}>
          <ListRow
            icon="bellOutline"
            label="Notifikasi"
            value={unreadCount > 0 ? `${unreadCount} belum dibaca` : 'Pengingat dan pembaruan dari portal'}
            onPress={() => router.push('/notifications')}
          />
          <Divider inset={ListRowTextInset} />
          <ListRow icon="wallet" label="Keuangan" value="Tagihan dan riwayat pembayaran" onPress={() => router.push('/finance')} />
          <Divider inset={ListRowTextInset} />
          <ListRow icon="megaphone" label="Pengumuman" value="Info resmi dari kampus" onPress={() => router.push('/announcements')} />
          <Divider inset={ListRowTextInset} />
          <ListRow icon="library" label="Perpustakaan" value="Tautan dan repositori skripsi" onPress={() => router.push('/library')} />
          <Divider inset={ListRowTextInset} />
          <ListRow icon="sparkles" label="Notebook AI" value="Belajar dari materi kuliah" onPress={() => router.push('/notebooks')} />
          <Divider inset={ListRowTextInset} />
          <ListRow icon="grid" label="Semua layanan" value="Semua fitur WeCIC dalam satu daftar" onPress={() => router.push('/services')} />
        </Panel>
      </Section>

      <Section label="Akun saya">
        <Panel padded={false}>
          <ListRow icon="biodata" label="Biodata" value="Data pribadi, kontak, dan pendidikan" onPress={() => router.push('/biodata')} />
          <Divider inset={ListRowTextInset} />
          <ListRow icon="key" label="Ganti kata sandi" value="Kata sandi portal kampus" onPress={() => router.push('/password')} />
        </Panel>
      </Section>

      <Section label="Data dan sinkronisasi">
        <Panel padded={false}>
          <ListRow icon="archive" label="Arsip data" value="Riwayat data portal dan sinkronisasi latar" onPress={() => router.push('/archive')} />
          <Divider inset={ListRowTextInset} />
          <ListRow icon="outbox" label="Antrean offline" value="Absensi dan unggahan yang menunggu portal" onPress={() => router.push('/outbox')} />
        </Panel>
      </Section>

      <Section label="Perangkat">
        <Panel padded={false}>
          <ListRow icon="bellOutline" label="Notifikasi push" value={pushLabel(push)} />
          <Divider inset={ListRowTextInset} />
          <ListRow icon="bolt" label="Pembaruan otomatis" value={realtimeStatusLabel(realtimeStatus)} />
          <Divider inset={ListRowTextInset} />
          <ListRow icon="server" label="Server WeCIC" value={apiBaseUrl() ?? '-'} />
        </Panel>
      </Section>

      <Section label="Aplikasi">
        <Panel padded={false}>
          {website ? (
            <>
              <ListRow
                icon="globe"
                label="Buka WeCIC versi web"
                value="Nilai, keuangan, KRS, dan fitur lainnya"
                trailingIcon="arrowUpRight"
                onPress={() => void WebBrowser.openBrowserAsync(website)}
              />
              <Divider inset={ListRowTextInset} />
            </>
          ) : null}
          <ListRow icon="phone" label="Versi aplikasi" value={Constants.expoConfig?.version ?? '-'} />
        </Panel>
      </Section>

      <Button variant="danger" icon="signOut" label="Keluar" loading={signingOut} onPress={confirmSignOut} />

      <View style={styles.footer}>
        <BrandLogo width={132} />
        <ThemedText type="caption" themeColor="textQuaternary" style={styles.centered}>
          WeCIC · aplikasi tidak resmi untuk MyCIC, tidak berafiliasi dengan UCIC
        </ThemedText>
      </View>
    </Screen>
  );
}

function pushLabel(push: PushRegistration | null): string {
  if (push === null) {
    return 'Mendaftarkan…';
  }

  if (push.status === 'registered') {
    return 'Aktif di perangkat ini';
  }

  if (push.status === 'denied') {
    return 'Izin notifikasi ditolak. Aktifkan lewat Pengaturan perangkat.';
  }

  return push.reason;
}

const styles = StyleSheet.create({
  profile: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.three,
  },
  profileText: {
    flex: 1,
    gap: Spacing.two - Spacing.half,
  },
  footer: {
    alignItems: 'center',
    gap: Spacing.two,
    marginTop: Spacing.two,
  },
  centered: {
    textAlign: 'center',
  },
});
