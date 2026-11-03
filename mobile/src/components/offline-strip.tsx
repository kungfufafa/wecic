import { StyleSheet, View } from 'react-native';

import { Chip } from '@/components/chip';
import { Icon } from '@/components/icon';
import { ThemedText } from '@/components/themed-text';
import { Radius, Spacing } from '@/constants/theme';
import { useOfflineReason } from '@/hooks/use-connectivity';
import { useTheme } from '@/hooks/use-theme';
import { ageLabel } from '@/lib/cache-policies';

type OfflineStripProps = {
  /** When the data on screen came from the server, for its age. */
  savedAt?: number | null;
  onRetry?: () => void;
};

/**
 * Says the student is offline, and why: no Wi-Fi or mobile data on the
 * device, or WeCIC's server not answering. Shown from the moment the
 * device loses its connection, with the age of the data on screen.
 * Nothing while online.
 */
export function OfflineStrip({ savedAt, onRetry }: OfflineStripProps) {
  const theme = useTheme();
  const reason = useOfflineReason();

  if (reason === null) {
    return null;
  }

  const kept = savedAt ? ` Data di layar ini tersimpan dari ${ageLabel(savedAt)}.` : '';
  const copy =
    reason === 'device'
      ? { title: 'Tidak ada internet', body: `Nyalakan data seluler atau Wi-Fi.${kept}` }
      : { title: 'WeCIC tidak bisa dihubungi', body: `Internet tersambung, tetapi server belum menjawab.${kept}` };

  return (
    <View accessibilityRole="alert" accessibilityLiveRegion="polite" style={[styles.strip, { backgroundColor: theme.warningTint }]}>
      <Icon name="offline" size={18} color={theme.warning} />
      <View style={styles.text}>
        <ThemedText type="smallBold" style={{ color: theme.warning }}>
          {copy.title}
        </ThemedText>
        <ThemedText type="caption" themeColor="textSecondary">
          {copy.body}
        </ThemedText>
      </View>
      {reason === 'server' && onRetry ? <Chip label="Coba lagi" icon="refresh" onPress={onRetry} /> : null}
    </View>
  );
}

const styles = StyleSheet.create({
  strip: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.two + Spacing.one,
    paddingVertical: Spacing.two + Spacing.one,
    paddingLeft: Spacing.three,
    paddingRight: Spacing.two,
    borderRadius: Radius.panel,
  },
  text: {
    flex: 1,
    gap: Spacing.half,
  },
});
