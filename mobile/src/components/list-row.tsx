import { Pressable, StyleSheet, View } from 'react-native';

import { Icon, type IconName } from '@/components/icon';
import { ThemedText } from '@/components/themed-text';
import { Spacing } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';

type ListRowProps = {
  icon: IconName;
  label: string;
  value?: string | null;
  tone?: 'neutral' | 'danger';
  /** Opens something; the row then ends with an arrow. */
  onPress?: () => void;
  trailingIcon?: IconName;
};

/** One setting or fact in a panel: an icon, what it is, and what it says. */
export function ListRow({ icon, label, value, tone = 'neutral', onPress, trailingIcon = 'chevronRight' }: ListRowProps) {
  const theme = useTheme();
  const iconColor = tone === 'danger' ? theme.danger : theme.text;

  const content = (
    <>
      <View style={[styles.iconCircle, { backgroundColor: tone === 'danger' ? theme.dangerTint : theme.elevated }]}>
        <Icon name={icon} size={18} color={iconColor} />
      </View>
      <View style={styles.text}>
        <ThemedText type="bodyMedium" style={tone === 'danger' && { color: theme.danger }}>
          {label}
        </ThemedText>
        {value ? (
          <ThemedText type="small" themeColor="textTertiary">
            {value}
          </ThemedText>
        ) : null}
      </View>
      {onPress ? <Icon name={trailingIcon} size={14} color={theme.textQuaternary} /> : null}
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

/** Where a row's text starts, for the hairline between rows. */
export const ListRowTextInset = Spacing.three + 40 + Spacing.three;

const styles = StyleSheet.create({
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.three,
    paddingHorizontal: Spacing.three,
    paddingVertical: Spacing.three - Spacing.one,
    minHeight: 64,
  },
  iconCircle: {
    width: 40,
    height: 40,
    borderRadius: 20,
    alignItems: 'center',
    justifyContent: 'center',
  },
  text: {
    flex: 1,
    gap: Spacing.half,
  },
});
