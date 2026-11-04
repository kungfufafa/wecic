import { StyleSheet, View } from 'react-native';

import { useTheme } from '@/hooks/use-theme';

type ProgressBarProps = {
  /** 0 to 100; anything outside is clamped. */
  percent: number | null | undefined;
  color?: string;
  height?: number;
};

export function ProgressBar({ percent, color, height = 6 }: ProgressBarProps) {
  const theme = useTheme();
  const value = Math.max(0, Math.min(100, Number.isFinite(percent) ? Number(percent) : 0));

  return (
    <View
      accessibilityRole="progressbar"
      accessibilityValue={{ min: 0, max: 100, now: Math.round(value) }}
      style={[styles.track, { height, borderRadius: height / 2, backgroundColor: theme.elevated }]}>
      <View style={{ width: `${value}%`, height, borderRadius: height / 2, backgroundColor: color ?? theme.brand }} />
    </View>
  );
}

const styles = StyleSheet.create({
  track: {
    width: '100%',
    overflow: 'hidden',
  },
});
