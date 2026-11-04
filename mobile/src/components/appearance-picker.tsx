import { Pressable, StyleSheet, View } from 'react-native';

import { Icon, type IconName } from '@/components/icon';
import { ListRowTextInset } from '@/components/list-row';
import { Divider } from '@/components/section';
import { ThemedText } from '@/components/themed-text';
import { Spacing } from '@/constants/theme';
import { setAppearancePreference, useAppearancePreference } from '@/hooks/use-appearance';
import { useTheme } from '@/hooks/use-theme';
import { AppearanceOptions, type AppearancePreference } from '@/lib/appearance';

const OptionIcons: Record<AppearancePreference, IconName> = {
  system: 'appearance',
  light: 'sun',
  dark: 'moon',
};

/** Otomatis, Terang or Gelap, as rows in a panel with a check on the one in use. */
export function AppearancePicker() {
  const theme = useTheme();
  const preference = useAppearancePreference();

  return (
    <View accessibilityRole="radiogroup" accessibilityLabel="Tampilan">
      {AppearanceOptions.map((option, index) => {
        const selected = option.value === preference;

        return (
          <View key={option.value}>
            {index > 0 ? <Divider inset={ListRowTextInset} /> : null}
            <Pressable
              accessibilityRole="radio"
              accessibilityState={{ checked: selected }}
              accessibilityHint={option.description}
              onPress={() => setAppearancePreference(option.value)}
              style={({ pressed }) => [styles.row, pressed && { backgroundColor: theme.section }]}>
              <View style={[styles.iconCircle, { backgroundColor: selected ? theme.selected : theme.elevated }]}>
                <Icon name={OptionIcons[option.value]} size={18} color={selected ? theme.brand : theme.text} />
              </View>
              <View style={styles.text}>
                <ThemedText type="bodyMedium">{option.label}</ThemedText>
                <ThemedText type="small" themeColor="textTertiary">
                  {option.description}
                </ThemedText>
              </View>
              {selected ? <Icon name="checkmark" size={18} color={theme.brand} /> : null}
            </Pressable>
          </View>
        );
      })}
    </View>
  );
}

const styles = StyleSheet.create({
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.three,
    paddingHorizontal: Spacing.three,
    paddingVertical: Spacing.three - Spacing.one,
    minHeight: 64,
  },
  iconCircle: {
    width: 40,
    height: 40,
    borderRadius: 20,
    alignItems: 'center',
    justifyContent: 'center',
  },
  text: {
    flex: 1,
    gap: Spacing.half,
  },
});
