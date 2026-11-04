import { Fragment } from 'react';
import { StyleSheet, View } from 'react-native';

import { ThemedText } from '@/components/themed-text';
import { Spacing } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';

export type Stat = { label: string; value: string; color?: string };

/** Two to four numbers side by side in a panel, split by hairlines. */
export function StatRow({ stats }: { stats: Stat[] }) {
  const theme = useTheme();

  return (
    <View style={styles.row}>
      {stats.map((stat, index) => (
        <Fragment key={stat.label}>
          {index > 0 ? <View style={[styles.divider, { backgroundColor: theme.hairline }]} /> : null}
          <View style={styles.stat} accessible accessibilityLabel={`${stat.label}: ${stat.value}`}>
            <ThemedText type="heading" style={stat.color ? { color: stat.color } : undefined} numberOfLines={1} adjustsFontSizeToFit>
              {stat.value}
            </ThemedText>
            <ThemedText type="caption" themeColor="textTertiary" numberOfLines={1} adjustsFontSizeToFit minimumFontScale={0.8}>
              {stat.label}
            </ThemedText>
          </View>
        </Fragment>
      ))}
    </View>
  );
}

const styles = StyleSheet.create({
  row: {
    flexDirection: 'row',
    alignItems: 'stretch',
    paddingVertical: Spacing.three,
  },
  stat: {
    flex: 1,
    gap: Spacing.one,
    paddingHorizontal: Spacing.three,
  },
  divider: {
    width: StyleSheet.hairlineWidth,
  },
});
