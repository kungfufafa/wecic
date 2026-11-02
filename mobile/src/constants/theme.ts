/**
 * MyCIC is dark only: a near-black canvas, sections on a lifted charcoal,
 * content on a deeper panel inside each section, light pills for the main
 * action. CIC royal blue and gold stay as accents (the class card, links,
 * the avatar), never as large surfaces.
 */

import { Platform } from 'react-native';

const palette = {
  background: '#050505',
  section: '#1C1C1F',
  panel: '#141416',
  elevated: '#2A2A2E',
  elevatedPressed: '#36363B',
  selected: '#2E3D52',
  hairline: 'rgba(255, 255, 255, 0.07)',
  text: '#F5F5F7',
  textSecondary: '#A1A1A6',
  textTertiary: '#8E8E93',
  textQuaternary: '#5C5C61',
  light: '#F5F5F7',
  lightPressed: '#D9D9DE',
  onLight: '#0A0A0A',
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
} as const;

export const Colors = { light: palette, dark: palette } as const;

export type ThemeColor = keyof typeof palette;

/** The class card on Beranda: CIC royal blue, deepening to the corner. */
export const BrandGradient = 'linear-gradient(135deg, #3A8BE0 0%, #0352A1 48%, #022B57 100%)';

/**
 * The tile colors a course can get (lib/format.ts picks one from its code),
 * so a course keeps its color on every screen.
 */
export const CourseColors = [
  { tint: 'rgba(110, 168, 238, 0.18)', ink: '#8AB8F2' },
  { tint: 'rgba(45, 212, 191, 0.16)', ink: '#5EEAD4' },
  { tint: 'rgba(167, 139, 250, 0.18)', ink: '#C4B5FD' },
  { tint: 'rgba(255, 195, 0, 0.16)', ink: '#FCD34D' },
  { tint: 'rgba(251, 113, 133, 0.16)', ink: '#FDA4AF' },
  { tint: 'rgba(52, 199, 89, 0.16)', ink: '#86EFAC' },
] as const;

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
