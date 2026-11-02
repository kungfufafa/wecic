import { StyleSheet, View } from 'react-native';

import { ThemedText } from '@/components/themed-text';
import { useTheme } from '@/hooks/use-theme';

/** The student's initials on a pale CIC-blue circle. */
export function Avatar({ initials, size = 48 }: { initials: string; size?: number }) {
  const theme = useTheme();

  return (
    <View
      accessibilityElementsHidden
      style={[styles.circle, { width: size, height: size, borderRadius: size / 2, backgroundColor: theme.avatar }]}>
      <ThemedText style={{ color: theme.onAvatar, fontSize: size * 0.34, lineHeight: size * 0.44, fontWeight: 600, letterSpacing: 0.4 }}>
        {initials}
      </ThemedText>
    </View>
  );
}

const styles = StyleSheet.create({
  circle: {
    alignItems: 'center',
    justifyContent: 'center',
  },
});
