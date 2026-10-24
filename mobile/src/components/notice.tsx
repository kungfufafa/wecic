import { StyleSheet, View } from 'react-native';

import { ThemedText } from '@/components/themed-text';
import { Radius, Spacing } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';

type NoticeProps = {
  message: string;
  tone?: 'info' | 'warning' | 'error';
};

/** A short message above the content: a failed request, offline mode. */
export function Notice({ message, tone = 'info' }: NoticeProps) {
  const theme = useTheme();
  const colors = {
    info: { background: theme.backgroundSelected, text: theme.primary },
    warning: { background: theme.accentTint, text: theme.warning },
    error: { background: theme.dangerTint, text: theme.danger },
  }[tone];

  return (
    <View
      accessibilityRole={tone === 'error' ? 'alert' : undefined}
      style={[styles.notice, { backgroundColor: colors.background }]}>
      <ThemedText type="small" style={{ color: colors.text }}>
        {message}
      </ThemedText>
    </View>
  );
}

const styles = StyleSheet.create({
  notice: {
    paddingHorizontal: Spacing.three,
    paddingVertical: Spacing.two + Spacing.one,
    borderRadius: Radius.small,
  },
});
