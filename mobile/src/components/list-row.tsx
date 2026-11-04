import { Pressable, StyleSheet, View } from 'react-native';

import { Icon, type IconName } from '@/components/icon';
import { ThemedText } from '@/components/themed-text';
import { Spacing } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';

type ListRowProps = {
  icon: IconName;
  label: string;
  /** One quiet line under the label: a fact, never an explanation. */
  value?: string | null;
  tone?: 'neutral' | 'danger';
  /** Opens something; the row then ends with an arrow. */
  onPress?: () => void;
  trailingIcon?: IconName;
};

/** One item in a panel: an outlined icon, what it is, and one line about it. */
export function ListRow({ icon, label, value, tone = 'neutral', onPress, trailingIcon = 'chevronRight' }: ListRowProps) {
  const theme = useTheme();
  const color = tone === 'danger' ? theme.danger : theme.text;

  const content = (
    <>
      <Icon name={icon} size={22} color={color} />
      <View style={styles.text}>
        <ThemedText style={[styles.label, { color }]} numberOfLines={2}>
          {label}
        </ThemedText>
        {value ? (
          <ThemedText type="small" themeColor="textTertiary" numberOfLines={1}>
            {value}
          </ThemedText>
        ) : null}
      </View>
      {onPress ? <Icon name={trailingIcon} size={15} color={theme.textQuaternary} /> : null}
    </>
  );

  if (!onPress) {
    return <View style={styles.row}>{content}</View>;
  }

  return (
    <Pressable accessibilityRole="button" onPress={onPress} style={({ pressed }) => [styles.row, pressed && { backgroundColor: theme.section }]}>
      {content}
    </Pressable>
  );
}

/** Rows in a panel are split by full-width hairlines. */
export const ListRowTextInset = 0;

const styles = StyleSheet.create({
  row: {
    minHeight: 56,
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.three - Spacing.half,
    paddingHorizontal: Spacing.three + Spacing.half,
    paddingVertical: Spacing.two + Spacing.one,
  },
  text: {
    flex: 1,
    gap: Spacing.half,
  },
  label: {
    fontSize: 17,
    lineHeight: 22,
  },
});
