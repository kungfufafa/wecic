import { StyleSheet, View } from 'react-native';

import { toneColors } from '@/components/academic/tone';
import { ThemedText } from '@/components/themed-text';
import { Radius, Spacing } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';
import { letterTone, type AcademicTone } from '@/lib/academic';

/** A letter grade on a tile colored by how good it is. */
export function LetterBadge({ letter, size = 32 }: { letter: string | null | undefined; size?: number }) {
  const theme = useTheme();
  const colors = toneColors(theme, letterTone(letter));

  return (
    <View
      accessible
      accessibilityLabel={`Nilai ${letter ?? 'belum ada'}`}
      style={[styles.letter, { width: size, height: size, borderRadius: Radius.small, backgroundColor: colors.tint }]}>
      <ThemedText style={{ color: colors.ink, fontSize: size * 0.42, lineHeight: size * 0.52, fontWeight: 600 }}>
        {letter?.trim() || '–'}
      </ThemedText>
    </View>
  );
}

/** A status pill with a tone the shared pill has no room for (danger). */
export function ToneBadge({ label, tone }: { label: string; tone: AcademicTone }) {
  const theme = useTheme();
  const colors = toneColors(theme, tone);

  return (
    <View style={[styles.pill, { backgroundColor: colors.tint }]}>
      <View style={[styles.dot, { backgroundColor: colors.ink }]} />
      <ThemedText type="caption" style={{ color: colors.ink }}>
        {label}
      </ThemedText>
    </View>
  );
}

const styles = StyleSheet.create({
  letter: {
    alignItems: 'center',
    justifyContent: 'center',
  },
  pill: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.two - Spacing.half,
    paddingLeft: Spacing.two + Spacing.half,
    paddingRight: Spacing.two + Spacing.one,
    paddingVertical: Spacing.one + Spacing.half,
    borderRadius: Radius.pill,
  },
  dot: {
    width: 6,
    height: 6,
    borderRadius: 3,
  },
});
