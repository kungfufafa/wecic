import { StyleSheet, View } from 'react-native';

import { Button } from '@/components/button';
import { Icon, type IconName } from '@/components/icon';
import { ThemedText } from '@/components/themed-text';
import { Radius, Spacing } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';

type EmptyStateProps = {
  icon: IconName;
  title: string;
  /** One short line, only when it tells the student something the title does not. */
  description?: string;
  action?: { label: string; icon?: IconName; onPress: () => void } | null;
};

/** What a panel says when it has nothing to show, and the one way forward. */
export function EmptyState({ icon, title, description, action }: EmptyStateProps) {
  const theme = useTheme();

  return (
    <View style={styles.empty}>
      <View style={[styles.iconTile, { backgroundColor: theme.elevated }]}>
        <Icon name={icon} size={20} color={theme.textSecondary} />
      </View>
      <View style={styles.text}>
        <ThemedText type="bodyMedium" style={styles.centered}>
          {title}
        </ThemedText>
        {description ? (
          <ThemedText type="small" themeColor="textTertiary" style={styles.centered}>
            {description}
          </ThemedText>
        ) : null}
      </View>
      {action ? <Button size="small" variant="secondary" label={action.label} icon={action.icon} onPress={action.onPress} /> : null}
    </View>
  );
}

const styles = StyleSheet.create({
  empty: {
    alignItems: 'center',
    gap: Spacing.two + Spacing.one,
    paddingVertical: Spacing.four - Spacing.one,
    paddingHorizontal: Spacing.three,
  },
  iconTile: {
    width: 44,
    height: 44,
    borderRadius: Radius.medium,
    alignItems: 'center',
    justifyContent: 'center',
  },
  text: {
    gap: Spacing.half,
  },
  centered: {
    textAlign: 'center',
  },
});
