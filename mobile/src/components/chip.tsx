import { Pressable, StyleSheet, View } from 'react-native';

import { Icon, type IconName } from '@/components/icon';
import { ThemedText } from '@/components/themed-text';
import { Radius, Spacing } from '@/constants/theme';
import { useElevation, useTheme } from '@/hooks/use-theme';

type ChipProps = {
  label: string;
  selected?: boolean;
  icon?: IconName;
  /** Without it the chip is a label, not a control. */
  onPress?: () => void;
};

/**
 * A raised pill (white in light mode, deep charcoal in dark): a filter to
 * pick, or a small fact in a section's corner.
 */
export function Chip({ label, selected = false, icon, onPress }: ChipProps) {
  const theme = useTheme();
  const elevation = useElevation();
  const surface = { backgroundColor: theme.panel, borderColor: theme.panelBorder, boxShadow: elevation.raised };
  const textColor = selected ? theme.text : theme.textSecondary;

  const content = (
    <>
      {icon ? <Icon name={icon} size={14} color={textColor} /> : null}
      <ThemedText type="small" style={{ color: textColor, fontWeight: 500 }}>
        {label}
      </ThemedText>
    </>
  );

  if (!onPress) {
    return <View style={[styles.chip, surface]}>{content}</View>;
  }

  return (
    <Pressable
      accessibilityRole="button"
      accessibilityState={{ selected }}
      hitSlop={4}
      onPress={onPress}
      style={({ pressed }) => [
        styles.chip,
        surface,
        selected ? { backgroundColor: theme.selected } : pressed && { backgroundColor: theme.elevatedPressed },
      ]}>
      {content}
    </Pressable>
  );
}

const styles = StyleSheet.create({
  chip: {
    minHeight: 36,
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.one + Spacing.half,
    paddingHorizontal: Spacing.three - Spacing.one,
    borderRadius: Radius.pill,
    borderWidth: StyleSheet.hairlineWidth,
  },
});
