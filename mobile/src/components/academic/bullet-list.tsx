import { StyleSheet, View } from 'react-native';

import { ThemedText } from '@/components/themed-text';
import { Spacing } from '@/constants/theme';

/** Numbered lines of text: requirements, steps. */
export function NumberedList({ items }: { items: string[] }) {
  return (
    <View style={styles.list}>
      {items.map((item, index) => (
        <View key={`${index}-${item.slice(0, 16)}`} style={styles.row}>
          <ThemedText type="smallBold" themeColor="textTertiary" style={styles.number}>
            {index + 1}.
          </ThemedText>
          <ThemedText type="small" themeColor="textSecondary" style={styles.text}>
            {item}
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
    gap: Spacing.two,
  },
  number: {
    width: 20,
  },
  text: {
    flex: 1,
  },
});
