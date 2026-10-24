import { StyleSheet, View } from 'react-native';

import { ThemedText } from '@/components/themed-text';
import { Radius, Spacing } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';
import type { Tone } from '@/lib/format';

export function StatusPill({ label, tone }: { label: string; tone: Tone }) {
  const theme = useTheme();
  const colors = {
    neutral: { background: theme.backgroundSelected, text: theme.textSecondary },
    positive: { background: theme.backgroundSelected, text: theme.success },
    attention: { background: theme.accentTint, text: theme.warning },
    muted: { background: theme.backgroundSelected, text: theme.textTertiary },
  }[tone];

  return (
    <View style={[styles.pill, { backgroundColor: colors.background }]}>
      <ThemedText type="caption" style={{ color: colors.text }}>
        {label}
      </ThemedText>
    </View>
  );
}

const styles = StyleSheet.create({
  pill: {
    alignSelf: 'flex-start',
    paddingHorizontal: Spacing.two,
    paddingVertical: Spacing.half,
    borderRadius: Radius.small,
  },
});
