import type { Colors } from '@/constants/theme';
import type { AcademicTone } from '@/lib/academic';

type Palette = (typeof Colors)['dark'];

/** The ink and tint of a tone on the dark panels. */
export function toneColors(theme: Palette, tone: AcademicTone): { ink: string; tint: string } {
  switch (tone) {
    case 'success':
      return { ink: theme.success, tint: theme.successTint };
    case 'brand':
      return { ink: theme.brand, tint: theme.brandTint };
    case 'warning':
      return { ink: theme.warning, tint: theme.warningTint };
    case 'danger':
      return { ink: theme.danger, tint: theme.dangerTint };
    default:
      return { ink: theme.textSecondary, tint: theme.elevated };
  }
}
