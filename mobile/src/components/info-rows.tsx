import { Fragment } from 'react';
import { StyleSheet, View } from 'react-native';

import { Divider } from '@/components/section';
import { ThemedText } from '@/components/themed-text';
import { Spacing } from '@/constants/theme';

export type InfoRow = { label: string; value: string | number | null | undefined };

/**
 * Facts as label and value, one per line, in a panel without padding; rows
 * with nothing to say are left out, and `empty` shows when none are left.
 */
export function InfoRows({ rows, empty = 'Belum ada data.' }: { rows: InfoRow[]; empty?: string }) {
  const filled = rows.filter((row) => row.value !== null && row.value !== undefined && String(row.value).trim() !== '' && String(row.value).trim() !== '-');

  if (filled.length === 0) {
    return (
      <ThemedText type="small" themeColor="textTertiary" style={styles.empty}>
        {empty}
      </ThemedText>
    );
  }

  return (
    <View>
      {filled.map((row, index) => (
        <Fragment key={`${row.label}-${index}`}>
          {index > 0 ? <Divider inset={Spacing.three} /> : null}
          <View style={styles.row}>
            <ThemedText type="small" themeColor="textTertiary" style={styles.label}>
              {row.label}
            </ThemedText>
            <ThemedText type="small" style={styles.value} selectable>
              {String(row.value)}
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
    alignItems: 'flex-start',
    gap: Spacing.three,
    paddingHorizontal: Spacing.three,
    paddingVertical: Spacing.three - Spacing.one,
  },
  label: {
    width: '38%',
  },
  value: {
    flex: 1,
    textAlign: 'right',
  },
  empty: {
    padding: Spacing.three,
  },
});
