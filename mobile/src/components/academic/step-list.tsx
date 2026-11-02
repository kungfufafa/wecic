import { StyleSheet, View } from 'react-native';

import { Icon } from '@/components/icon';
import { ThemedText } from '@/components/themed-text';
import { Spacing } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';
import { stepState } from '@/lib/academic';
import type { TrackerStep } from '@/api/types/academic';

const DOT = 24;

/** Steps down a rail: done ones checked, the current one lit, the rest hollow. */
export function StepList({ steps }: { steps: TrackerStep[] }) {
  const theme = useTheme();

  return (
    <View style={styles.list}>
      {steps.map((step, index) => {
        const state = stepState(step.state);
        const isLast = index === steps.length - 1;
        const stateLabel = state === 'done' ? 'selesai' : state === 'current' ? 'sedang berjalan' : 'belum';

        return (
          <View key={step.key ?? index} style={styles.step} accessible accessibilityLabel={`${step.label ?? 'Langkah'}, ${stateLabel}`}>
            <View style={styles.rail}>
              {state === 'done' ? (
                <View style={[styles.dot, { backgroundColor: theme.successTint }]}>
                  <Icon name="check" size={16} color={theme.success} />
                </View>
              ) : state === 'current' ? (
                <View style={[styles.dot, { backgroundColor: theme.accentTint }]}>
                  <View style={[styles.core, { backgroundColor: theme.accent }]} />
                </View>
              ) : (
                <View style={[styles.dot, styles.hollow, { borderColor: theme.textQuaternary }]} />
              )}
              {!isLast ? <View style={[styles.line, { backgroundColor: state === 'done' ? theme.success : theme.hairline }]} /> : null}
            </View>
            <ThemedText
              type={state === 'current' ? 'smallBold' : 'small'}
              themeColor={state === 'todo' ? 'textTertiary' : 'text'}
              style={styles.label}>
              {step.label ?? 'Langkah'}
            </ThemedText>
          </View>
        );
      })}
    </View>
  );
}

const styles = StyleSheet.create({
  list: {
    paddingVertical: Spacing.one,
  },
  step: {
    flexDirection: 'row',
    gap: Spacing.three - Spacing.one,
    minHeight: DOT + Spacing.four,
  },
  rail: {
    width: DOT,
    alignItems: 'center',
  },
  dot: {
    width: DOT,
    height: DOT,
    borderRadius: DOT / 2,
    alignItems: 'center',
    justifyContent: 'center',
  },
  hollow: {
    borderWidth: 2,
  },
  core: {
    width: 8,
    height: 8,
    borderRadius: 4,
  },
  line: {
    flex: 1,
    width: 2,
    marginVertical: Spacing.one,
    borderRadius: 1,
  },
  label: {
    flex: 1,
    paddingTop: 2,
  },
});
