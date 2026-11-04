import { StyleSheet, View } from 'react-native';

import { Icon, type IconName } from '@/components/icon';
import { ThemedText } from '@/components/themed-text';
import { Radius, Spacing } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';

type NoticeProps = {
  message: string;
  tone?: 'info' | 'warning' | 'error' | 'success';
};

/** A one- or two-line message above the content: a failed request, offline mode. */
export function Notice({ message, tone = 'info' }: NoticeProps) {
  const theme = useTheme();
  const look: Record<NonNullable<NoticeProps['tone']>, { color: string; tint: string; icon: IconName }> = {
    info: { color: theme.brand, tint: theme.brandTint, icon: 'info' },
    warning: { color: theme.warning, tint: theme.warningTint, icon: 'warning' },
    error: { color: theme.danger, tint: theme.dangerTint, icon: 'error' },
    success: { color: theme.success, tint: theme.successTint, icon: 'check' },
  };
  const colors = look[tone];

  return (
    <View accessibilityRole={tone === 'error' ? 'alert' : undefined} style={[styles.notice, { backgroundColor: colors.tint }]}>
      <Icon name={colors.icon} size={16} color={colors.color} />
      <ThemedText type="small" themeColor="textSecondary" style={styles.text}>
        {message}
      </ThemedText>
    </View>
  );
}

const styles = StyleSheet.create({
  notice: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.two + Spacing.half,
    paddingVertical: Spacing.two + Spacing.half,
    paddingHorizontal: Spacing.three - Spacing.one,
    borderRadius: Radius.large,
  },
  text: {
    flex: 1,
  },
});
