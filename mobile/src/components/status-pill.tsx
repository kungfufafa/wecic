import { StyleSheet, View } from 'react-native';

import { ThemedText } from '@/components/themed-text';
import { Colors, Radius, Spacing } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';
import type { Tone } from '@/lib/format';

/** A status with its dot: attendance, connection, a class in progress. */
export function StatusPill({ label, tone, onBrand = false }: { label: string; tone: Tone; onBrand?: boolean }) {
  const theme = useTheme();
  const colors = {
    neutral: { background: theme.elevated, text: theme.textSecondary, dot: theme.textTertiary },
    positive: { background: theme.successTint, text: theme.success, dot: theme.success },
    attention: { background: theme.accentTint, text: theme.accent, dot: theme.accent },
    muted: { background: theme.elevated, text: theme.textTertiary, dot: theme.textQuaternary },
  }[tone];

  return (
    <View style={[styles.pill, { backgroundColor: onBrand ? 'rgba(255, 255, 255, 0.16)' : colors.background }]}>
      <View style={[styles.dot, { backgroundColor: onBrand ? (tone === 'attention' ? Colors.dark.accent : theme.onColor) : colors.dot }]} />
      <ThemedText type="caption" style={{ color: onBrand ? theme.onColor : colors.text }}>
        {label}
      </ThemedText>
    </View>
  );
}

const styles = StyleSheet.create({
  pill: {
    alignSelf: 'flex-start',
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.two - Spacing.half,
    paddingLeft: Spacing.two + Spacing.half,
    paddingRight: Spacing.two + Spacing.one,
    paddingVertical: Spacing.one + Spacing.half,
    borderRadius: Radius.pill,
  },
  dot: {
    width: 6,
    height: 6,
    borderRadius: 3,
  },
});
