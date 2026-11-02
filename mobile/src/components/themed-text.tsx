import { Platform, StyleSheet, Text, type TextProps } from 'react-native';

import { Fonts, ThemeColor } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';

export type ThemedTextProps = TextProps & {
  type?:
    | 'display'
    | 'title'
    | 'heading'
    | 'subtitle'
    | 'default'
    | 'bodyMedium'
    | 'small'
    | 'smallBold'
    | 'caption'
    | 'label'
    | 'link'
    | 'code';
  themeColor?: ThemeColor;
};

/**
 * The app's type scale: three weights (400 reads, 500 emphasizes, 600
 * announces) and letter-spacing that tightens as the size grows.
 */
export function ThemedText({ style, type = 'default', themeColor, ...rest }: ThemedTextProps) {
  const theme = useTheme();
  const defaultColor: ThemeColor = themeColor ?? (type === 'link' ? 'brand' : type === 'label' ? 'textTertiary' : 'text');

  return <Text style={[{ color: theme[defaultColor] }, styles[type], style]} {...rest} />;
}

const styles = StyleSheet.create({
  display: {
    fontSize: 34,
    lineHeight: 40,
    fontWeight: 600,
    letterSpacing: -0.8,
  },
  title: {
    fontSize: 28,
    lineHeight: 34,
    fontWeight: 600,
    letterSpacing: -0.5,
  },
  heading: {
    fontSize: 22,
    lineHeight: 28,
    fontWeight: 600,
    letterSpacing: -0.4,
  },
  subtitle: {
    fontSize: 17,
    lineHeight: 22,
    fontWeight: 600,
    letterSpacing: -0.2,
  },
  default: {
    fontSize: 16,
    lineHeight: 22,
    fontWeight: 400,
  },
  bodyMedium: {
    fontSize: 16,
    lineHeight: 22,
    fontWeight: 500,
  },
  small: {
    fontSize: 14,
    lineHeight: 19,
    fontWeight: 400,
  },
  smallBold: {
    fontSize: 14,
    lineHeight: 19,
    fontWeight: 600,
  },
  caption: {
    fontSize: 12,
    lineHeight: 16,
    fontWeight: 500,
  },
  label: {
    fontSize: 15,
    lineHeight: 20,
    fontWeight: 500,
  },
  link: {
    fontSize: 14,
    lineHeight: 19,
    fontWeight: 600,
  },
  code: {
    fontFamily: Fonts.mono,
    fontWeight: Platform.select({ android: 700 }) ?? 500,
    fontSize: 12,
  },
});
