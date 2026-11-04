import { useEffect, useState } from 'react';
import { Animated, StyleSheet, View, type DimensionValue } from 'react-native';

import { Divider } from '@/components/section';
import { Radius, Spacing } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';

type SkeletonProps = {
  width?: DimensionValue;
  height?: number;
  radius?: number;
};

/** A placeholder that breathes while the data it stands for loads. */
export function Skeleton({ width = '100%', height = 14, radius = Radius.small }: SkeletonProps) {
  const theme = useTheme();
  const [opacity] = useState(() => new Animated.Value(0.45));

  useEffect(() => {
    const loop = Animated.loop(
      Animated.sequence([
        Animated.timing(opacity, { toValue: 0.9, duration: 700, useNativeDriver: true }),
        Animated.timing(opacity, { toValue: 0.45, duration: 700, useNativeDriver: true }),
      ]),
    );
    loop.start();

    return () => loop.stop();
  }, [opacity]);

  return <Animated.View style={{ width, height, borderRadius: radius, backgroundColor: theme.elevated, opacity }} />;
}

/** The shape of panel rows while their content loads. */
export function SkeletonRows({ count = 2 }: { count?: number }) {
  return (
    <View>
      {Array.from({ length: count }, (_, index) => (
        <View key={index}>
          {index > 0 ? <Divider /> : null}
          <View style={styles.row}>
            <Skeleton width={22} height={22} radius={Radius.small / 2} />
            <View style={styles.lines}>
              <Skeleton width="68%" height={14} />
              <Skeleton width="42%" height={11} />
            </View>
          </View>
        </View>
      ))}
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
    paddingVertical: Spacing.two + Spacing.one,
  },
  lines: {
    flex: 1,
    gap: Spacing.one + Spacing.half,
  },
});
