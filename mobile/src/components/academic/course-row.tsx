import type { ReactNode } from 'react';
import { StyleSheet, View } from 'react-native';

import { CourseTile } from '@/components/course-tile';
import { ThemedText } from '@/components/themed-text';
import { Spacing } from '@/constants/theme';

type CourseRowProps = {
  code: string | null | undefined;
  name: string | null | undefined;
  /** The line under the name: code, SKS, semester. */
  detail?: string | null;
  /** A second quieter line: KHS components, attempts. */
  note?: string | null;
  /** Drawn at the right: a letter badge, a status. */
  trailing?: ReactNode;
  /** Without the monogram, when the trailing badge carries the row. */
  showTile?: boolean;
};

/** One course in a panel: its monogram, name, details, and what it scored. */
export function CourseRow({ code, name, detail, note, trailing, showTile = true }: CourseRowProps) {
  return (
    <View style={styles.row}>
      {showTile ? <CourseTile name={name} code={code} size={40} /> : null}
      <View style={styles.text}>
        <ThemedText type="bodyMedium" numberOfLines={2}>
          {name || 'Mata kuliah'}
        </ThemedText>
        {detail ? (
          <ThemedText type="small" themeColor="textTertiary" numberOfLines={2}>
            {detail}
          </ThemedText>
        ) : null}
        {note ? (
          <ThemedText type="caption" themeColor="textQuaternary" numberOfLines={2}>
            {note}
          </ThemedText>
        ) : null}
      </View>
      {trailing}
    </View>
  );
}

/** Where a course row's text starts, for the hairline between rows. */
export const CourseRowInset = Spacing.three + 40 + Spacing.three - Spacing.one;

const styles = StyleSheet.create({
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.three - Spacing.one,
    padding: Spacing.three,
  },
  text: {
    flex: 1,
    gap: Spacing.half,
  },
});
