import type { ReactNode } from 'react';
import { Pressable, StyleSheet, View } from 'react-native';

import { Icon, type IconName } from '@/components/icon';
import { ThemedText } from '@/components/themed-text';
import { Radius, Spacing } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';
import type { StatusTag } from '@/lib/device-status';

type SettingsRowProps = {
  icon: IconName;
  label: string;
  /** A short value at the end of the row: a version, a count. */
  detail?: string | null;
  /** Drawn before the arrow: a status tag, a badge. */
  accessory?: ReactNode;
  /** Opens something; the row then ends with `trailingIcon`. */
  onPress?: () => void;
  trailingIcon?: IconName;
  /** A choice in a list: a check instead of an arrow on the one in use. */
  checked?: boolean;
  accessibilityHint?: string;
};

/**
 * One line of a settings panel: an outlined icon, its name, a short value
 * and an arrow, with nothing under the name.
 */
export function SettingsRow({ icon, label, detail, accessory, onPress, trailingIcon = 'chevronRight', checked, accessibilityHint }: SettingsRowProps) {
  const theme = useTheme();
  const isChoice = checked !== undefined;

  const content = (
    <>
      <Icon name={icon} size={22} color={theme.text} />
      <ThemedText style={styles.label} numberOfLines={1}>
        {label}
      </ThemedText>
      {detail ? (
        <ThemedText type="small" themeColor="textTertiary" style={styles.detail} numberOfLines={1}>
          {detail}
        </ThemedText>
      ) : null}
      {accessory}
      {isChoice ? (
        checked ? <Icon name="checkmark" size={18} color={theme.brand} /> : null
      ) : onPress ? (
        <Icon name={trailingIcon} size={15} color={theme.textQuaternary} />
      ) : null}
    </>
  );

  if (!onPress) {
    return (
      <View style={styles.row} accessible accessibilityLabel={detail ? `${label}, ${detail}` : label} accessibilityHint={accessibilityHint}>
        {content}
      </View>
    );
  }

  return (
    <Pressable
      accessibilityRole={isChoice ? 'radio' : 'button'}
      accessibilityState={isChoice ? { checked } : undefined}
      accessibilityHint={accessibilityHint}
      onPress={onPress}
      style={({ pressed }) => [styles.row, pressed && { backgroundColor: theme.section }]}>
      {content}
    </Pressable>
  );
}

const TagDots: Record<StatusTag['tone'], 'success' | 'warning' | 'danger' | 'textQuaternary'> = {
  positive: 'success',
  attention: 'warning',
  danger: 'danger',
  muted: 'textQuaternary',
};

/** A small grey pill with a colored dot: "● Aktif", "● Offline". */
export function StatusTagPill({ tag }: { tag: StatusTag }) {
  const theme = useTheme();

  return (
    <View style={[styles.tag, { backgroundColor: theme.elevated }]}>
      <View style={[styles.dot, { backgroundColor: theme[TagDots[tag.tone]] }]} />
      <ThemedText type="caption" themeColor="textSecondary" style={styles.tagText}>
        {tag.label}
      </ThemedText>
    </View>
  );
}

/** A red count pill: unread notifications. */
export function CountBadge({ count }: { count: number }) {
  const theme = useTheme();

  return (
    <View style={[styles.badge, { backgroundColor: theme.badge }]}>
      <ThemedText type="caption" style={[styles.tagText, { color: theme.onColor }]}>
        {count > 99 ? '99+' : String(count)}
      </ThemedText>
    </View>
  );
}

const styles = StyleSheet.create({
  row: {
    minHeight: 56,
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.three - Spacing.half,
    paddingHorizontal: Spacing.three + Spacing.half,
    paddingVertical: Spacing.two + Spacing.half,
  },
  label: {
    flex: 1,
    fontSize: 17,
    lineHeight: 22,
  },
  detail: {
    flexShrink: 1,
    maxWidth: '45%',
    textAlign: 'right',
  },
  tag: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.one + Spacing.half,
    paddingHorizontal: Spacing.two + Spacing.half,
    paddingVertical: Spacing.one + Spacing.half,
    borderRadius: Radius.pill,
  },
  dot: {
    width: 7,
    height: 7,
    borderRadius: 4,
  },
  tagText: {
    fontSize: 13,
  },
  badge: {
    minWidth: 24,
    alignItems: 'center',
    paddingHorizontal: Spacing.two,
    paddingVertical: Spacing.half + 1,
    borderRadius: Radius.pill,
  },
});
