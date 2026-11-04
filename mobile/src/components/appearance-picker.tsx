import { View } from 'react-native';

import type { IconName } from '@/components/icon';
import { Divider } from '@/components/section';
import { SettingsRow } from '@/components/settings-row';
import { setAppearancePreference, useAppearancePreference } from '@/hooks/use-appearance';
import { AppearanceOptions, type AppearancePreference } from '@/lib/appearance';

const OptionIcons: Record<AppearancePreference, IconName> = {
  system: 'appearance',
  light: 'sun',
  dark: 'moon',
};

/** Otomatis, Terang or Gelap, as rows in a panel with a check on the one in use. */
export function AppearancePicker() {
  const preference = useAppearancePreference();

  return (
    <View accessibilityRole="radiogroup" accessibilityLabel="Tampilan">
      {AppearanceOptions.map((option, index) => (
        <View key={option.value}>
          {index > 0 ? <Divider /> : null}
          <SettingsRow
            icon={OptionIcons[option.value]}
            label={option.label}
            checked={option.value === preference}
            accessibilityHint={option.description}
            onPress={() => setAppearancePreference(option.value)}
          />
        </View>
      ))}
    </View>
  );
}
