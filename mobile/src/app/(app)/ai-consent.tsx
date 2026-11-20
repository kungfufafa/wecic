import { useRouter } from 'expo-router';
import * as WebBrowser from 'expo-web-browser';
import { Alert, Platform, StyleSheet, View } from 'react-native';

import { Button } from '@/components/button';
import { Icon } from '@/components/icon';
import { Notice } from '@/components/notice';
import { Screen } from '@/components/screen';
import { Panel, Section } from '@/components/section';
import { SettingsRow } from '@/components/settings-row';
import { SkeletonRows } from '@/components/skeleton';
import { ThemedText } from '@/components/themed-text';
import { Spacing } from '@/constants/theme';
import { useAiConsent } from '@/hooks/use-ai-consent';
import { useTheme } from '@/hooks/use-theme';
import { AI_CONSENT_FREE_TIER, AI_CONSENT_SENT_ITEMS, AI_PROVIDER } from '@/lib/ai-consent';
import { formatDateTime } from '@/lib/format';
import { legalPageUrl } from '@/lib/privacy';

/**
 * Asks, before the first AI action, to send Notebook content to Google
 * Gemini, and lets the student withdraw it later. Declining leaves the
 * rest of the app, and the notebooks themselves, as they are.
 */
export default function AiConsentScreen() {
  const router = useRouter();
  const theme = useTheme();
  const { consent, granted, loading, busy, error, grant, withdraw } = useAiConsent({ load: true });
  const privacyUrl = legalPageUrl('privacy');
  const provider = consent?.provider ?? AI_PROVIDER;

  function leave() {
    if (router.canGoBack()) {
      router.back();
    } else {
      router.replace('/');
    }
  }

  async function agree() {
    if ((await grant()) !== null) {
      leave();
    }
  }

  function confirmWithdraw() {
    const title = 'Tarik izin Notebook AI?';
    const message = `Sejak sekarang tidak ada lagi isi notebook yang dikirim ke ${provider}. Tanya jawab, Studio, dan pembacaan PDF atau gambar berhenti sampai kamu setuju lagi. Notebook dan percakapan yang sudah ada tetap tersimpan.`;
    const run = () => void withdraw();

    if (Platform.OS === 'web') {
      if (window.confirm(`${title}\n${message}`)) {
        run();
      }

      return;
    }

    Alert.alert(title, message, [
      { text: 'Batal', style: 'cancel' },
      { text: 'Tarik izin', style: 'destructive', onPress: run },
    ]);
  }

  return (
    <Screen back title="Izin Notebook AI" subtitle={`Diproses oleh ${provider}`}>
      {error ? <Notice tone="error" message={error} /> : null}
      {granted ? (
        <Notice
          tone="success"
          message={consent?.granted_at ? `Kamu sudah setuju sejak ${formatDateTime(consent.granted_at)}.` : 'Kamu sudah setuju. Notebook AI aktif.'}
        />
      ) : null}

      <Section label="Yang dikirim">
        <Panel style={styles.panel}>
          <ThemedText type="small" themeColor="textSecondary">
            {`Fitur AI Notebook mengirim isi berikut ke ${provider} (layanan Google) untuk menjawab dan membuat ringkasan:`}
          </ThemedText>
          {AI_CONSENT_SENT_ITEMS.map((item) => (
            <View key={item} style={styles.item}>
              <Icon name="checkmark" size={16} color={theme.brand} />
              <ThemedText type="small" style={styles.flex}>
                {item}
              </ThemedText>
            </View>
          ))}
        </Panel>
      </Section>

      <Section label="Tingkat gratis">
        <Panel style={styles.panel}>
          <View style={styles.item}>
            <Icon name="warning" size={16} color={theme.warning} />
            <ThemedText type="small" themeColor="textSecondary" style={styles.flex}>
              {AI_CONSENT_FREE_TIER}
            </ThemedText>
          </View>
          <ThemedText type="caption" themeColor="textTertiary">
            Tanpa izin ini, notebook tetap bisa dibuka, diberi sumber teks, dan dihapus. Hanya fitur AI yang tidak berjalan. Izin bisa ditarik kapan saja di Lainnya.
          </ThemedText>
        </Panel>
      </Section>

      {privacyUrl ? (
        <Section>
          <Panel padded={false}>
            <SettingsRow icon="lock" label="Kebijakan privasi" trailingIcon="arrowUpRight" onPress={() => void WebBrowser.openBrowserAsync(privacyUrl)} />
          </Panel>
        </Section>
      ) : null}

      {loading ? (
        <Section>
          <Panel padded={false}>
            <SkeletonRows count={1} />
          </Panel>
        </Section>
      ) : null}

      {granted ? (
        <Button variant="danger" icon="cancel" label="Tarik izin" loading={busy === 'withdraw'} onPress={confirmWithdraw} />
      ) : (
        <View style={styles.actions}>
          <Button icon="checkmark" label="Setuju" loading={busy === 'grant'} disabled={loading} onPress={() => void agree()} />
          <Button variant="secondary" label="Tidak sekarang" disabled={busy === 'grant'} onPress={leave} />
        </View>
      )}
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
  actions: {
    gap: Spacing.two,
  },
});
