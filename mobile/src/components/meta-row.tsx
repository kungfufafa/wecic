import { StyleSheet, View } from 'react-native';

import { Icon, type IconName } from '@/components/icon';
import { ThemedText } from '@/components/themed-text';
import { Spacing } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';

/** One detail with its icon: the room, the lecturer, the time. Nothing when it is empty. */
export function MetaRow({ icon, text, color }: { icon: IconName; text: string | null | undefined; color?: string }) {
  const theme = useTheme();

  if (!text || text.trim() === '') {
    return null;
  }

  return (
    <View style={styles.row}>
      <Icon name={icon} size={14} color={color ?? theme.textTertiary} />
      <ThemedText type="small" style={[styles.text, { color: color ?? theme.textSecondary }]} numberOfLines={1}>
        {text}
      </ThemedText>
    </View>
  );
}

const styles = StyleSheet.create({
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.two - Spacing.half,
  },
  text: {
    flex: 1,
  },
});
