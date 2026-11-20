import Constants from 'expo-constants';
import { useRouter } from 'expo-router';
import * as WebBrowser from 'expo-web-browser';
import { useState } from 'react';
import { Alert, Platform, Pressable, StyleSheet, View } from 'react-native';

import { webUrl } from '@/api/config';
import type { BackgroundSync, BackgroundSyncResponse } from '@/api/types/admin';
import { useSession } from '@/auth/session-provider';
import { AppearancePicker } from '@/components/appearance-picker';
import { Avatar } from '@/components/avatar';
import { BackgroundSyncSetting } from '@/components/background-sync-setting';
import { BrandLogo } from '@/components/brand-logo';
import { Button } from '@/components/button';
import { Icon } from '@/components/icon';
import { Screen } from '@/components/screen';
import { Divider, Panel, Section } from '@/components/section';
import { CountBadge, SettingsRow, StatusTagPill } from '@/components/settings-row';
import { ThemedText } from '@/components/themed-text';
import { Spacing } from '@/constants/theme';
import { useAiConsent } from '@/hooks/use-ai-consent';
import { useApiResource } from '@/hooks/use-api-resource';
import { useTheme } from '@/hooks/use-theme';
import { clearKnownUser, useKnownUser } from '@/hooks/use-known-user';
import { useUnreadCountValue } from '@/hooks/use-unread-count';
import { initials, studentNumber } from '@/lib/format';
import { portalStatusTag, pushStatusTag, realtimeStatusTag } from '@/lib/device-status';
import { legalPageUrl } from '@/lib/privacy';
import { useRealtimeStatus } from '@/realtime/portal-realtime';

const SYNC_STILL_ON_TITLE = 'Sinkronisasi belum dimatikan';
const SYNC_STILL_ON_MESSAGE =
  'Server WeCIC tidak bisa dihubungi saat keluar, jadi sinkronisasi di background masih menyala dan kata sandi terenkripsi masih tersimpan di server. Masuk lagi lalu matikan di Lainnya, atau keluar lagi saat online. Kata sandi itu juga dihapus otomatis paling lama 30 hari sejak login online terakhir.';

/** Told after a logout the server never confirmed while background sync was on. */
function warnSyncStillOn() {
  // The web preview has no native alert.
  if (Platform.OS === 'web') {
    window.alert(`${SYNC_STILL_ON_TITLE}\n${SYNC_STILL_ON_MESSAGE}`);

    return;
  }

  Alert.alert(SYNC_STILL_ON_TITLE, SYNC_STILL_ON_MESSAGE);
}

export default function MoreScreen() {
  const router = useRouter();
  const theme = useTheme();
  const { state, push, signOut } = useSession();
  const [signingOut, setSigningOut] = useState(false);
  const knownUser = useKnownUser();
  const unreadCount = useUnreadCountValue();
  const realtimeStatus = useRealtimeStatus();
  const user = knownUser ?? (state.status === 'signedIn' ? state.user : null);
  const offline = state.status === 'signedIn' && state.offline;
  const nim = studentNumber(user);
  const website = webUrl();
  const portal = portalStatusTag(offline);
  const backgroundSync = useApiResource<BackgroundSyncResponse>('archive/background-sync');
  const [syncChange, setSyncChange] = useState<{ status: BackgroundSync; at: number } | null>(null);
  const shownSync =
    syncChange !== null && (backgroundSync.savedAt === null || syncChange.at >= backgroundSync.savedAt)
      ? syncChange.status
      : (backgroundSync.data?.data ?? null);
  const aiConsent = useAiConsent({ load: true });
  const privacyUrl = legalPageUrl('privacy');
  const supportUrl = legalPageUrl('support');

  function leave() {
    const syncWasOn = shownSync?.enabled === true;

    setSigningOut(true);
    clearKnownUser();
    void signOut().then((loggedOutOnServer) => {
      if (!loggedOutOnServer && syncWasOn) {
        warnSyncStillOn();
      }
    });
  }

  function confirmSignOut() {
    const title = 'Keluar dari WeCIC?';
    const message =
      'Token di perangkat ini dicabut dan notifikasi push berhenti. Jika server bisa dihubungi, sinkronisasi di background juga dimatikan dan kata sandi tersimpan di server dihapus.';

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
        <Panel padded={false}>
          <Pressable
            accessibilityRole="button"
            accessibilityLabel={`${user?.name ?? 'Mahasiswa'}${nim ? `, NIM ${nim}` : ''}, portal ${portal.label}`}
            accessibilityHint="Buka biodata"
            onPress={() => router.push('/biodata')}
            style={({ pressed }) => [styles.profile, pressed && { backgroundColor: theme.section }]}>
            <Avatar initials={initials(user)} size={52} />
            <View style={styles.profileText}>
              <ThemedText type="subtitle" numberOfLines={1}>
                {user?.name ?? 'Mahasiswa'}
              </ThemedText>
              <ThemedText type="small" themeColor="textTertiary" numberOfLines={1}>
                {nim ? `NIM ${nim}` : 'Mahasiswa'}
              </ThemedText>
            </View>
            <StatusTagPill tag={portal} />
            <Icon name="chevronRight" size={15} color={theme.textQuaternary} />
          </Pressable>
        </Panel>
      </Section>

      <Section label="Layanan">
        <Panel padded={false}>
          <SettingsRow
            icon="bellOutline"
            label="Notifikasi"
            accessory={unreadCount > 0 ? <CountBadge count={unreadCount} /> : null}
            accessibilityHint={unreadCount > 0 ? `${unreadCount} belum dibaca` : undefined}
            onPress={() => router.push('/notifications')}
          />
          <Divider />
          <SettingsRow icon="walletOutline" label="Keuangan" onPress={() => router.push('/finance')} />
          <Divider />
          <SettingsRow icon="megaphoneOutline" label="Pengumuman" onPress={() => router.push('/announcements')} />
          <Divider />
          <SettingsRow icon="libraryOutline" label="Perpustakaan" onPress={() => router.push('/library')} />
          <Divider />
          <SettingsRow icon="sparkles" label="Notebook AI" onPress={() => router.push('/notebooks')} />
          <Divider />
          <SettingsRow icon="gridOutline" label="Semua layanan" onPress={() => router.push('/services')} />
        </Panel>
      </Section>

      <Section label="Akun saya">
        <Panel padded={false}>
          <SettingsRow icon="idCard" label="Biodata" onPress={() => router.push('/biodata')} />
          <Divider />
          <SettingsRow icon="keyOutline" label="Ganti kata sandi" onPress={() => router.push('/password')} />
        </Panel>
      </Section>

      <Section label="Tampilan">
        <Panel padded={false}>
          <AppearancePicker />
        </Panel>
      </Section>

      <Section label="Data dan sinkronisasi">
        <Panel padded={false}>
          <SettingsRow icon="archiveOutline" label="Arsip data" onPress={() => router.push('/archive')} />
          <Divider />
          <SettingsRow icon="outboxOutline" label="Antrean offline" onPress={() => router.push('/outbox')} />
        </Panel>
      </Section>

      <Section label="Privasi">
        <Panel padded={false}>
          {shownSync ? (
            <>
              <BackgroundSyncSetting status={shownSync} onChange={(status) => setSyncChange({ status, at: Date.now() })} />
              <Divider />
            </>
          ) : null}
          <SettingsRow
            icon="sparkles"
            label="Izin Notebook AI"
            detail={aiConsent.consent === null ? null : aiConsent.granted ? 'Disetujui' : 'Belum disetujui'}
            onPress={() => router.push('/ai-consent')}
          />
          {privacyUrl ? (
            <>
              <Divider />
              <SettingsRow icon="lock" label="Kebijakan privasi" trailingIcon="arrowUpRight" onPress={() => void WebBrowser.openBrowserAsync(privacyUrl)} />
            </>
          ) : null}
          {supportUrl ? (
            <>
              <Divider />
              <SettingsRow icon="info" label="Bantuan" trailingIcon="arrowUpRight" onPress={() => void WebBrowser.openBrowserAsync(supportUrl)} />
            </>
          ) : null}
          <Divider />
          <SettingsRow icon="trash" label="Hapus akun & data WeCIC" onPress={() => router.push('/delete-account')} />
        </Panel>
      </Section>

      <Section label="Perangkat">
        <Panel padded={false}>
          <SettingsRow
            icon="bellOutline"
            label="Notifikasi push"
            accessory={<StatusTagPill tag={pushStatusTag(push)} />}
            accessibilityHint={push?.status === 'unavailable' ? push.reason : undefined}
          />
          <Divider />
          <SettingsRow icon="boltOutline" label="Pembaruan otomatis" accessory={<StatusTagPill tag={realtimeStatusTag(realtimeStatus)} />} />
        </Panel>
      </Section>

      <Section label="Aplikasi">
        <Panel padded={false}>
          {website ? (
            <>
              <SettingsRow
                icon="globe"
                label="WeCIC versi web"
                trailingIcon="arrowUpRight"
                onPress={() => void WebBrowser.openBrowserAsync(website)}
              />
              <Divider />
            </>
          ) : null}
          <SettingsRow icon="phone" label="Versi aplikasi" detail={Constants.expoConfig?.version ?? '-'} />
        </Panel>
      </Section>

      <Button variant="danger" icon="signOut" label="Keluar" loading={signingOut} onPress={confirmSignOut} />

      <View style={styles.footer}>
        <BrandLogo width={132} color={theme.text} />
        <ThemedText type="caption" themeColor="textQuaternary" style={styles.centered}>
          WeCIC · aplikasi unofficial untuk portal akademik kampus, tidak berafiliasi dengan kampus mana pun
        </ThemedText>
      </View>
    </Screen>
  );
}

const styles = StyleSheet.create({
  profile: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.three - Spacing.one,
    padding: Spacing.three,
  },
  profileText: {
    flex: 1,
    gap: Spacing.half,
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
