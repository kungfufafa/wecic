import type { ReactNode } from 'react';
import { StyleSheet, View, type StyleProp, type ViewStyle } from 'react-native';

import { ThemedText } from '@/components/themed-text';
import { Radius, SectionInset, Spacing } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';

type SectionProps = {
  /** The quiet label in the section's top corner. */
  label?: string;
  /** Drawn opposite the label: a chip, a count, an icon button. */
  accessory?: ReactNode;
  children: ReactNode;
  style?: StyleProp<ViewStyle>;
};

/**
 * A charcoal section with its label on top and its content on the deeper
 * panels inside it, the app's basic grouping.
 */
export function Section({ label, accessory, children, style }: SectionProps) {
  const theme = useTheme();

  return (
    <View style={[styles.section, { backgroundColor: theme.section }, style]}>
      {label || accessory ? (
        <View style={styles.header}>
          {label ? (
            <ThemedText type="label" accessibilityRole="header" style={styles.label} numberOfLines={1}>
              {label}
            </ThemedText>
          ) : (
            <View style={styles.label} />
          )}
          {accessory}
        </View>
      ) : null}
      {children}
    </View>
  );
}

type PanelProps = {
  children: ReactNode;
  /** Without padding, for rows that draw their own. */
  padded?: boolean;
  style?: StyleProp<ViewStyle>;
};

/** The deeper surface inside a section that holds its content. */
export function Panel({ children, padded = true, style }: PanelProps) {
  const theme = useTheme();

  return <View style={[styles.panel, padded && styles.padded, { backgroundColor: theme.panel }, style]}>{children}</View>;
}

/** A hairline between rows of a panel, starting at `inset`. */
export function Divider({ inset = 0 }: { inset?: number }) {
  const theme = useTheme();

  return <View style={[styles.divider, { backgroundColor: theme.hairline, marginLeft: inset }]} />;
}

const styles = StyleSheet.create({
  section: {
    borderRadius: Radius.section,
    padding: SectionInset,
    gap: SectionInset,
  },
  header: {
    minHeight: 40,
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.two,
    paddingLeft: Spacing.three - SectionInset + Spacing.one,
    paddingRight: Spacing.one,
    paddingTop: Spacing.one,
  },
  label: {
    flex: 1,
  },
  panel: {
    borderRadius: Radius.panel,
    overflow: 'hidden',
  },
  padded: {
    padding: Spacing.three,
  },
  divider: {
    height: StyleSheet.hairlineWidth,
  },
});
