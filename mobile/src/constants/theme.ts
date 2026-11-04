/**
 * WeCIC comes in two modes that share one layout: a canvas, sections on a
 * lifted tone with their label on top, and content on a panel inside each
 * section. Light is a pale-grey canvas with white panels and a charcoal
 * pill for the main action; dark is a near-black canvas with charcoal
 * panels and a light pill. CIC royal blue and gold stay as accents (the
 * class card, links, the avatar), never as large surfaces.
 */

import { Platform } from 'react-native';

const dark = {
  background: '#060606',
  section: '#272729',
  panel: '#18181A',
  panelBorder: 'rgba(255, 255, 255, 0.05)',
  elevated: '#2A2A2D',
  elevatedPressed: '#37373B',
  selected: '#2E3D52',
  hairline: 'rgba(255, 255, 255, 0.07)',
  text: '#F5F5F7',
  textSecondary: '#A1A1A6',
  textTertiary: '#8E8E93',
  textQuaternary: '#5C5C61',
  primary: '#F8F8F8',
  primaryPressed: '#D9D9DE',
  onPrimary: '#0A0A0A',
  onColor: '#FFFFFF',
  brand: '#6EA8EE',
  brandStrong: '#0352A1',
  brandTint: 'rgba(110, 168, 238, 0.16)',
  accent: '#FFC300',
  accentTint: 'rgba(255, 195, 0, 0.16)',
  success: '#34C759',
  successTint: 'rgba(52, 199, 89, 0.16)',
  warning: '#FFB020',
  warningTint: 'rgba(255, 176, 32, 0.16)',
  danger: '#FF5A4F',
  dangerTint: 'rgba(255, 90, 79, 0.16)',
  badge: '#FF5A4F',
  avatar: '#DCE7F7',
  onAvatar: '#0352A1',
};

const light: typeof dark = {
  background: '#F3F3F3',
  section: '#E9E9EB',
  panel: '#FFFFFF',
  panelBorder: 'rgba(0, 0, 0, 0.05)',
  elevated: '#EDEDEF',
  elevatedPressed: '#DEDEE1',
  selected: '#DCE7F7',
  hairline: 'rgba(0, 0, 0, 0.08)',
  text: '#111113',
  textSecondary: '#5C5C61',
  textTertiary: '#828186',
  textQuaternary: '#AEAEB2',
  primary: '#313131',
  primaryPressed: '#1E1E1E',
  onPrimary: '#F8F8F8',
  onColor: '#FFFFFF',
  brand: '#0352A1',
  brandStrong: '#0352A1',
  brandTint: 'rgba(3, 82, 161, 0.10)',
  accent: '#9A6B00',
  accentTint: 'rgba(255, 184, 0, 0.18)',
  success: '#1E8A3C',
  successTint: 'rgba(52, 199, 89, 0.14)',
  warning: '#B25E00',
  warningTint: 'rgba(255, 149, 0, 0.14)',
  danger: '#D93025',
  dangerTint: 'rgba(255, 59, 48, 0.12)',
  badge: '#FF3B30',
  avatar: '#DCE7F7',
  onAvatar: '#0352A1',
};

export const Colors = { light, dark } as const;

export type ColorSchemeName = keyof typeof Colors;
export type Palette = typeof dark;
export type ThemeColor = keyof Palette;

/**
 * How surfaces lift off what is under them. Light mode leans on soft
 * shadows; dark mode on a faint rim, since a shadow vanishes on black.
 */
export const Elevation = {
  light: {
    /** A white panel inside its grey section. */
    panel: '0px 1px 2px rgba(0, 0, 0, 0.05)',
    /** A round control or pill sitting on the canvas or a section. */
    raised: '0px 2px 8px rgba(0, 0, 0, 0.10)',
    /** The charcoal main action: a lit top edge and a shadow under it. */
    primary: 'inset 0px 1px 0px rgba(255, 255, 255, 0.16), inset 0px -2px 0px rgba(0, 0, 0, 0.25), 0px 6px 14px rgba(0, 0, 0, 0.22)',
  },
  dark: {
    panel: 'inset 0px 1px 0px rgba(255, 255, 255, 0.03)',
    raised: 'inset 0px 1px 0px rgba(255, 255, 255, 0.05)',
    primary: 'inset 0px 1px 0px rgba(255, 255, 255, 0.9), inset 0px -2px 0px rgba(0, 0, 0, 0.08), 0px 4px 12px rgba(0, 0, 0, 0.45)',
  },
} as const satisfies Record<ColorSchemeName, Record<string, string>>;

export type ElevationLevel = keyof (typeof Elevation)['light'];

/** The class card on Beranda: CIC royal blue, deepening to the corner. */
export const BrandGradient = 'linear-gradient(135deg, #3A8BE0 0%, #0352A1 48%, #022B57 100%)';

/**
 * The tile colors a course can get (lib/format.ts picks one from its code),
 * so a course keeps its color on every screen, in either mode.
 */
export const CourseColors = {
  dark: [
    { tint: 'rgba(110, 168, 238, 0.18)', ink: '#8AB8F2' },
    { tint: 'rgba(45, 212, 191, 0.16)', ink: '#5EEAD4' },
    { tint: 'rgba(167, 139, 250, 0.18)', ink: '#C4B5FD' },
    { tint: 'rgba(255, 195, 0, 0.16)', ink: '#FCD34D' },
    { tint: 'rgba(251, 113, 133, 0.16)', ink: '#FDA4AF' },
    { tint: 'rgba(52, 199, 89, 0.16)', ink: '#86EFAC' },
  ],
  light: [
    { tint: 'rgba(3, 82, 161, 0.10)', ink: '#0352A1' },
    { tint: 'rgba(13, 148, 136, 0.12)', ink: '#0F766E' },
    { tint: 'rgba(124, 58, 237, 0.10)', ink: '#6D28D9' },
    { tint: 'rgba(234, 179, 8, 0.16)', ink: '#92400E' },
    { tint: 'rgba(225, 29, 72, 0.10)', ink: '#BE123C' },
    { tint: 'rgba(22, 163, 74, 0.12)', ink: '#15803D' },
  ],
} as const satisfies Record<ColorSchemeName, readonly { tint: string; ink: string }[]>;

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
  small: 10,
  medium: 14,
  large: 18,
  panel: 22,
  section: 28,
  pill: 999,
} as const;

/** How far a section's panel sits inside it. */
export const SectionInset = 8;

export const BottomTabInset = Platform.select({ ios: 50, android: 80 }) ?? 0;
export const MaxContentWidth = 640;
