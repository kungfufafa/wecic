import { ActivityIndicator, Pressable, StyleSheet, View } from 'react-native';

import { Icon, type IconName } from '@/components/icon';
import { ThemedText } from '@/components/themed-text';
import { Spacing } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';

type FileRowProps = {
  icon?: IconName;
  label: string;
  detail?: string | null;
  busy?: boolean;
  onPress: () => void;
};

/** A portal file in a panel: what it is, and a download mark that turns into a spinner while it opens. */
export function FileRow({ icon = 'document', label, detail, busy = false, onPress }: FileRowProps) {
  const theme = useTheme();

  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={`Buka ${label}`}
      accessibilityState={{ busy }}
      disabled={busy}
      onPress={onPress}
      style={({ pressed }) => [styles.row, pressed && { backgroundColor: theme.section }]}>
      <Icon name={icon} size={22} color={theme.text} />
      <View style={styles.text}>
        <ThemedText type="bodyMedium" numberOfLines={2}>
          {label}
        </ThemedText>
        {detail ? (
          <ThemedText type="small" themeColor="textTertiary" numberOfLines={1}>
            {detail}
          </ThemedText>
        ) : null}
      </View>
      {busy ? <ActivityIndicator color={theme.textSecondary} /> : <Icon name="download" size={18} color={theme.textTertiary} />}
    </Pressable>
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
  text: {
    flex: 1,
    gap: Spacing.half,
  },
});
