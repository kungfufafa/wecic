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

  const title = reason === 'device' ? 'Tidak ada internet' : 'Server WeCIC tidak menjawab';
  const kept = savedAt ? `Data dari ${ageLabel(savedAt)}` : null;

  return (
    <View accessibilityRole="alert" accessibilityLiveRegion="polite" style={[styles.strip, { backgroundColor: theme.warningTint }]}>
      <Icon name="offline" size={18} color={theme.warning} />
      <View style={styles.text}>
        <ThemedText type="smallBold" style={{ color: theme.warning }}>
          {title}
        </ThemedText>
        {kept ? (
          <ThemedText type="caption" themeColor="textSecondary">
            {kept}
          </ThemedText>
        ) : null}
      </View>
      {reason === 'server' && onRetry ? <Chip label="Coba lagi" icon="refresh" onPress={onRetry} /> : null}
    </View>
  );
}

const styles = StyleSheet.create({
  strip: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.two + Spacing.half,
    paddingVertical: Spacing.two,
    paddingLeft: Spacing.three - Spacing.one,
    paddingRight: Spacing.two,
    borderRadius: Radius.large,
  },
  text: {
    flex: 1,
    gap: Spacing.half,
  },
});
