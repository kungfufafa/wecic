import { StyleSheet, View } from 'react-native';

import { ProgressBar } from '@/components/progress-bar';
import { ThemedText } from '@/components/themed-text';
import { Spacing } from '@/constants/theme';

export type Bar = { key: string; label: string; value: number | null | undefined; valueLabel: string; color?: string };

/** Values as horizontal bars against `max`, each with its label and number. */
export function BarList({ bars, max }: { bars: Bar[]; max: number }) {
  return (
    <View style={styles.list}>
      {bars.map((bar) => (
        <View key={bar.key} style={styles.row} accessible accessibilityLabel={`${bar.label}: ${bar.valueLabel}`}>
          <ThemedText type="small" themeColor="textTertiary" style={styles.label} numberOfLines={1}>
            {bar.label}
          </ThemedText>
          <View style={styles.bar}>
            <ProgressBar percent={bar.value === null || bar.value === undefined ? 0 : (bar.value / max) * 100} color={bar.color} />
          </View>
          <ThemedText type="smallBold" style={styles.value}>
            {bar.valueLabel}
          </ThemedText>
        </View>
      ))}
    </View>
  );
}

const styles = StyleSheet.create({
  list: {
    gap: Spacing.two,
  },
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.three - Spacing.one,
  },
  label: {
    width: 56,
  },
  bar: {
    flex: 1,
  },
  value: {
    width: 40,
    textAlign: 'right',
  },
});
