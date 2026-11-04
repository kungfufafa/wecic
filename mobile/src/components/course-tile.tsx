import { StyleSheet, View } from 'react-native';

import { ThemedText } from '@/components/themed-text';
import { Radius } from '@/constants/theme';
import { useCourseColors } from '@/hooks/use-theme';
import { courseColorIndex, courseInitials } from '@/lib/format';

type CourseTileProps = {
  name: string | null | undefined;
  code: string | null | undefined;
  size?: number;
};

/** A course's monogram on its own color, the same wherever the course appears. */
export function CourseTile({ name, code, size = 44 }: CourseTileProps) {
  const courseColors = useCourseColors();
  const color = courseColors[courseColorIndex(code ?? name, courseColors.length)];

  return (
    <View
      accessibilityElementsHidden
      style={[styles.tile, { width: size, height: size, borderRadius: Radius.medium, backgroundColor: color.tint }]}>
      <ThemedText style={{ color: color.ink, fontSize: size * 0.3, lineHeight: size * 0.4, fontWeight: 600, letterSpacing: 0.4 }}>
        {courseInitials(name, code)}
      </ThemedText>
    </View>
  );
}

const styles = StyleSheet.create({
  tile: {
    alignItems: 'center',
    justifyContent: 'center',
  },
});
