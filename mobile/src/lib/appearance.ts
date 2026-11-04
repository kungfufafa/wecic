import type { ColorSchemeName } from '@/constants/theme';

/** What the student picked under Lainnya › Tampilan. */
export type AppearancePreference = 'system' | 'light' | 'dark';

export const AppearanceOptions: readonly { value: AppearancePreference; label: string; description: string }[] = [
  { value: 'system', label: 'Otomatis', description: 'Ikuti mode terang atau gelap perangkat' },
  { value: 'light', label: 'Terang', description: 'Latar abu-abu muda, panel putih' },
  { value: 'dark', label: 'Gelap', description: 'Latar hitam, panel arang' },
];

/** A stored preference, or `system` for anything unreadable. */
export function parseAppearancePreference(raw: unknown): AppearancePreference {
  return raw === 'light' || raw === 'dark' || raw === 'system' ? raw : 'system';
}

/** The mode to draw: the student's pick, else the device's, else light. */
export function resolveColorScheme(preference: AppearancePreference, device: string | null | undefined): ColorSchemeName {
  if (preference !== 'system') {
    return preference;
  }

  return device === 'dark' ? 'dark' : 'light';
}

export function appearanceLabel(preference: AppearancePreference): string {
  return AppearanceOptions.find((option) => option.value === preference)?.label ?? 'Otomatis';
}
