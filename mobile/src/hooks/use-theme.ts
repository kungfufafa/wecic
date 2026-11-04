/**
 * Learn more about light and dark modes:
 * https://docs.expo.dev/guides/color-schemes/
 */

import { Colors, CourseColors, Elevation, type ColorSchemeName } from '@/constants/theme';
import { useAppearancePreference } from '@/hooks/use-appearance';
import { useColorScheme } from '@/hooks/use-color-scheme';
import { resolveColorScheme } from '@/lib/appearance';

/** The mode being drawn: the student's pick, else the device's. */
export function useResolvedColorScheme(): ColorSchemeName {
  const preference = useAppearancePreference();
  const device = useColorScheme();

  return resolveColorScheme(preference, device);
}

export function useTheme() {
  return Colors[useResolvedColorScheme()];
}

/** The shadows (light) or rims (dark) that lift a surface, as `boxShadow` values. */
export function useElevation() {
  return Elevation[useResolvedColorScheme()];
}

/** The course tile colors for the mode being drawn. */
export function useCourseColors() {
  return CourseColors[useResolvedColorScheme()];
}
