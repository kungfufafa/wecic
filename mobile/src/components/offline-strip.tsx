import { StyleSheet, View } from 'react-native';

import { Chip } from '@/components/chip';
import { Icon } from '@/components/icon';
import { ThemedText } from '@/components/themed-text';
import { Radius, Spacing } from '@/constants/theme';
import { useReachable } from '@/hooks/use-connectivity';
import { useTheme } from '@/hooks/use-theme';
import { ageLabel } from '@/lib/cache-policies';

type OfflineStripProps = {
  /** When the shown data came from the server, for its age. */
  savedAt?: number | null;
  onRetry?: () => void;
};

/** Says the phone cannot reach MyCIC and how old the data on screen is; nothing while online. */
export function OfflineStrip({ savedAt, onRetry }: OfflineStripProps) {
  const theme = useTheme();
  const reachable = useReachable();

  if (reachable) {
    return null;
  }

  return (
    <View accessibilityRole="alert" style={[styles.strip, { backgroundColor: theme.warningTint }]}>
      <Icon name="offline" size={18} color={theme.warning} />
      <View style={styles.text}>
        <ThemedText type="smallBold" style={{ color: theme.warning }}>
          Tidak ada internet
        </ThemedText>
        <ThemedText type="caption" themeColor="textSecondary">
          {savedAt ? `Menampilkan data tersimpan dari ${ageLabel(savedAt)}.` : 'Data akan dimuat begitu koneksi kembali.'}
        </ThemedText>
      </View>
      {onRetry ? <Chip label="Coba lagi" icon="refresh" onPress={onRetry} /> : null}
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
