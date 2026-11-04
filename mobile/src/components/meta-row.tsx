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

/** Details on one line, "Lab 1 · Pak Budi", leaving out the empty ones. */
export function joinMeta(...parts: (string | null | undefined)[]): string {
  return parts.filter((part): part is string => typeof part === 'string' && part.trim() !== '').join(' · ');
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
