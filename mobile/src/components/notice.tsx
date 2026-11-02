import { StyleSheet, View } from 'react-native';

import { Icon, type IconName } from '@/components/icon';
import { ThemedText } from '@/components/themed-text';
import { Radius, Spacing } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';

type NoticeProps = {
  message: string;
  tone?: 'info' | 'warning' | 'error' | 'success';
};

/** A short message above the content: a failed request, offline mode. */
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
    <View accessibilityRole={tone === 'error' ? 'alert' : undefined} style={[styles.notice, { backgroundColor: theme.section }]}>
      <View style={[styles.iconTile, { backgroundColor: colors.tint }]}>
        <Icon name={colors.icon} size={16} color={colors.color} />
      </View>
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
    gap: Spacing.two + Spacing.one,
    padding: Spacing.two + Spacing.one,
    paddingRight: Spacing.three,
    borderRadius: Radius.panel,
  },
  iconTile: {
    width: 32,
    height: 32,
    borderRadius: 16,
    alignItems: 'center',
    justifyContent: 'center',
  },
  text: {
    flex: 1,
  },
});
