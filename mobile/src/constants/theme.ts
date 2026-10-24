/**
 * MyCIC colors (DESIGN.md at the repository root): CIC royal blue for
 * actions and selection, academic gold for small highlights, cool neutrals
 * everywhere else. Light and dark mode.
 */

import { Platform } from 'react-native';

export const Colors = {
  light: {
    text: '#101828',
    textSecondary: '#475467',
    textTertiary: '#667085',
    background: '#F6F8FB',
    backgroundElement: '#FFFFFF',
    backgroundSelected: '#EDF4FB',
    border: '#D6DEE8',
    primary: '#0352A1',
    primaryPressed: '#024384',
    onPrimary: '#FFFFFF',
    accent: '#FFC300',
    accentTint: '#FFF2BF',
    success: '#16A34A',
    warning: '#B54708',
    danger: '#D92D20',
    dangerTint: '#FEF3F2',
  },
  dark: {
    text: '#F2F4F7',
    textSecondary: '#C3CAD5',
    textTertiary: '#98A2B3',
    background: '#0B1220',
    backgroundElement: '#131C2E',
    backgroundSelected: '#1A2A44',
    border: '#26334A',
    primary: '#4C8DDB',
    primaryPressed: '#2167AC',
    onPrimary: '#FFFFFF',
    accent: '#FFCA1F',
    accentTint: '#3A3110',
    success: '#32D583',
    warning: '#FDB022',
    danger: '#F97066',
    dangerTint: '#3B1714',
  },
} as const;

export type ThemeColor = keyof typeof Colors.light & keyof typeof Colors.dark;

export const Fonts = Platform.select({
  ios: {
    /** iOS `UIFontDescriptorSystemDesignDefault` */
    sans: 'system-ui',
    /** iOS `UIFontDescriptorSystemDesignSerif` */
    serif: 'ui-serif',
    /** iOS `UIFontDescriptorSystemDesignRounded` */
    rounded: 'ui-rounded',
    /** iOS `UIFontDescriptorSystemDesignMonospaced` */
    mono: 'ui-monospace',
  },
  default: {
    sans: 'normal',
    serif: 'serif',
    rounded: 'normal',
    mono: 'monospace',
  },
  web: {
    sans: 'Inter, ui-sans-serif, system-ui, sans-serif',
    serif: "Georgia, 'Times New Roman', serif",
    rounded: "'SF Pro Rounded', ui-rounded, sans-serif",
    mono: 'ui-monospace, SFMono-Regular, Menlo, monospace',
  },
});

export const Spacing = {
  half: 2,
  one: 4,
  two: 8,
  three: 16,
  four: 24,
  five: 32,
  six: 64,
} as const;

export const Radius = {
  small: 8,
  medium: 12,
  large: 16,
} as const;

export const BottomTabInset = Platform.select({ ios: 50, android: 80 }) ?? 0;
export const MaxContentWidth = 800;
