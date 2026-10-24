import { useState } from 'react';
import { Alert, Platform, StyleSheet, View } from 'react-native';

import { apiBaseUrl } from '@/api/config';
import { useSession } from '@/auth/session-provider';
import { Button } from '@/components/button';
import { Card } from '@/components/card';
import { Screen } from '@/components/screen';
import { ThemedText } from '@/components/themed-text';
import { Spacing } from '@/constants/theme';
import { studentNumber } from '@/lib/format';
import type { PushRegistration } from '@/push/push-notifications';

export default function MoreScreen() {
  const { state, push, signOut } = useSession();
  const [signingOut, setSigningOut] = useState(false);
  const user = state.status === 'signedIn' ? state.user : null;

  function leave() {
    setSigningOut(true);
    void signOut();
  }

  function confirmSignOut() {
    const title = 'Keluar dari MyCIC?';
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
      <Card>
        <ThemedText type="subtitle">{user?.name ?? 'Mahasiswa'}</ThemedText>
        {studentNumber(user) ? (
          <ThemedText type="small" themeColor="textSecondary">
            NIM {studentNumber(user)}
          </ThemedText>
        ) : null}
        {state.status === 'signedIn' && state.offline ? (
          <ThemedText type="caption" themeColor="warning">
            Mode offline (portal kampus tidak bisa diakses)
          </ThemedText>
        ) : null}
      </Card>

      <Card>
        <Row label="Notifikasi push" value={pushLabel(push)} />
        <Row label="Server" value={apiBaseUrl() ?? '-'} />
      </Card>

      <Button variant="danger" label="Keluar" loading={signingOut} onPress={confirmSignOut} />
    </Screen>
  );
}

function Row({ label, value }: { label: string; value: string }) {
  return (
    <View style={styles.row}>
      <ThemedText type="smallBold">{label}</ThemedText>
      <ThemedText type="small" themeColor="textSecondary">
        {value}
      </ThemedText>
    </View>
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
  row: {
    gap: Spacing.half,
  },
});
