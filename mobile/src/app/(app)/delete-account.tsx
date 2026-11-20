import * as WebBrowser from 'expo-web-browser';
import { useState } from 'react';
import { Alert, Platform, Pressable, StyleSheet, Switch, View } from 'react-native';

import { useSession } from '@/auth/session-provider';
import { Button } from '@/components/button';
import { Icon } from '@/components/icon';
import { Notice } from '@/components/notice';
import { Screen } from '@/components/screen';
import { Panel, Section } from '@/components/section';
import { SettingsRow } from '@/components/settings-row';
import { ThemedText } from '@/components/themed-text';
import { Spacing } from '@/constants/theme';
import { clearKnownUser } from '@/hooks/use-known-user';
import { useTheme } from '@/hooks/use-theme';
import { ACCOUNT_DELETION_ITEMS, ACCOUNT_DELETION_UNAFFECTED, legalPageUrl } from '@/lib/privacy';

/**
 * Deletes the student's WeCIC account and every piece of data the WeCIC
 * server keeps for them, in two steps: this screen lists what goes and
 * asks the student to acknowledge it, then a final confirmation sends
 * `DELETE account`. On success the device forgets everything and the
 * student is signed out.
 */
export default function DeleteAccountScreen() {
  const theme = useTheme();
  const { state, deleteAccount } = useSession();
  const [understood, setUnderstood] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const offline = state.status === 'signedIn' && state.offline;
  const deletionInfoUrl = legalPageUrl('dataDeletion');

  async function erase() {
    setDeleting(true);
    setError(null);

    try {
      await deleteAccount();
      clearKnownUser();
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : String(caught));
      setDeleting(false);
    }
  }

  function confirmErase() {
    const title = 'Hapus akun & data WeCIC sekarang?';
    const message = 'Semua data WeCIC-mu di server dan di perangkat ini dihapus permanen dan tidak bisa dikembalikan. Akun portal kampusmu tetap ada.';

    if (Platform.OS === 'web') {
      if (window.confirm(`${title}\n${message}`)) {
        void erase();
      }

      return;
    }

    Alert.alert(title, message, [
      { text: 'Batal', style: 'cancel' },
      { text: 'Hapus permanen', style: 'destructive', onPress: () => void erase() },
    ]);
  }

  return (
    <Screen back title="Hapus akun & data" subtitle="WeCIC">
      {error ? <Notice tone="error" message={error} /> : null}
      {offline ? <Notice tone="info" message="Portal kampus sedang offline. Penghapusan tetap bisa dilakukan selama server WeCIC terjangkau." /> : null}

      <Section label="Yang dihapus permanen">
        <Panel style={styles.panel}>
          {ACCOUNT_DELETION_ITEMS.map((item) => (
            <View key={item} style={styles.item}>
              <Icon name="trash" size={16} color={theme.danger} />
              <ThemedText type="small" style={styles.flex}>
                {item}
              </ThemedText>
            </View>
          ))}
          <View style={styles.item}>
            <Icon name="phone" size={16} color={theme.danger} />
            <ThemedText type="small" style={styles.flex}>
              Data tersimpan dan kata sandi &quot;Tetap masuk&quot; di perangkat ini
            </ThemedText>
          </View>
        </Panel>
      </Section>

      <Section label="Yang tidak terpengaruh">
        <Panel style={styles.panel}>
          <View style={styles.item}>
            <Icon name="check" size={16} color={theme.success} />
            <ThemedText type="small" themeColor="textSecondary" style={styles.flex}>
              {ACCOUNT_DELETION_UNAFFECTED}
            </ThemedText>
          </View>
        </Panel>
      </Section>

      {deletionInfoUrl ? (
        <Section>
          <Panel padded={false}>
            <SettingsRow
              icon="info"
              label="Tentang penghapusan data"
              trailingIcon="arrowUpRight"
              onPress={() => void WebBrowser.openBrowserAsync(deletionInfoUrl)}
            />
          </Panel>
        </Section>
      ) : null}

      <Section>
        <Panel padded={false}>
          <Pressable
            accessibilityRole="switch"
            accessibilityState={{ checked: understood }}
            onPress={() => setUnderstood((current) => !current)}
            style={styles.acknowledge}>
            <ThemedText type="bodyMedium" style={styles.flex}>
              Saya mengerti data ini tidak bisa dikembalikan
            </ThemedText>
            <Switch value={understood} onValueChange={setUnderstood} trackColor={{ true: theme.danger, false: theme.elevated }} />
          </Pressable>
        </Panel>
      </Section>

      <Button variant="danger" icon="trash" label="Hapus akun & data WeCIC" loading={deleting} disabled={!understood} onPress={confirmErase} />
    </Screen>
  );
}

const styles = StyleSheet.create({
  panel: {
    gap: Spacing.two + Spacing.half,
  },
  item: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: Spacing.two,
  },
  flex: {
    flex: 1,
  },
  acknowledge: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.three,
    paddingHorizontal: Spacing.three + Spacing.half,
    paddingVertical: Spacing.two + Spacing.half,
  },
});
