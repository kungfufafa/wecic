import { StyleSheet, View } from 'react-native';

import { Button } from '@/components/button';
import { Icon, type IconName } from '@/components/icon';
import { ThemedText } from '@/components/themed-text';
import { Radius, Spacing } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';

type EmptyStateProps = {
  icon: IconName;
  title: string;
  description?: string;
  action?: { label: string; icon?: IconName; onPress: () => void } | null;
};

/** What a panel says when it has nothing to show, and the one way forward. */
export function EmptyState({ icon, title, description, action }: EmptyStateProps) {
  const theme = useTheme();

  return (
    <View style={styles.empty}>
      <View style={[styles.iconTile, { backgroundColor: theme.elevated }]}>
        <Icon name={icon} size={26} color={theme.textSecondary} />
      </View>
      <View style={styles.text}>
        <ThemedText type="subtitle" style={styles.centered}>
          {title}
        </ThemedText>
        {description ? (
          <ThemedText type="small" themeColor="textTertiary" style={styles.centered}>
            {description}
          </ThemedText>
        ) : null}
      </View>
      {action ? <Button label={action.label} icon={action.icon} onPress={action.onPress} style={styles.action} /> : null}
    </View>
  );
}

const styles = StyleSheet.create({
  empty: {
    alignItems: 'center',
    gap: Spacing.three,
    paddingVertical: Spacing.four,
    paddingHorizontal: Spacing.two,
  },
  iconTile: {
    width: 64,
    height: 64,
    borderRadius: Radius.large + 2,
    alignItems: 'center',
    justifyContent: 'center',
  },
  text: {
    gap: Spacing.one + Spacing.half,
    paddingHorizontal: Spacing.three,
  },
  centered: {
    textAlign: 'center',
  },
  action: {
    alignSelf: 'stretch',
    marginTop: Spacing.two,
  },
});
