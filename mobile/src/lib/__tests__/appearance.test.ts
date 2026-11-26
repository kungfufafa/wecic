import { parseAppearancePreference, resolveColorScheme } from '@/lib/appearance';

describe('parseAppearancePreference', () => {
  it('keeps the three known picks', () => {
    expect(parseAppearancePreference('system')).toBe('system');
    expect(parseAppearancePreference('light')).toBe('light');
    expect(parseAppearancePreference('dark')).toBe('dark');
  });

  it('falls back to following the device for anything else', () => {
    expect(parseAppearancePreference(undefined)).toBe('system');
    expect(parseAppearancePreference(null)).toBe('system');
    expect(parseAppearancePreference('Dark')).toBe('system');
    expect(parseAppearancePreference(1)).toBe('system');
  });
});

describe('resolveColorScheme', () => {
  it('uses the pick over the device', () => {
    expect(resolveColorScheme('light', 'dark')).toBe('light');
    expect(resolveColorScheme('dark', 'light')).toBe('dark');
  });

  it('follows the device on Otomatis', () => {
    expect(resolveColorScheme('system', 'dark')).toBe('dark');
    expect(resolveColorScheme('system', 'light')).toBe('light');
  });

  it('draws light when the device says nothing', () => {
    expect(resolveColorScheme('system', null)).toBe('light');
    expect(resolveColorScheme('system', undefined)).toBe('light');
    expect(resolveColorScheme('system', 'unspecified')).toBe('light');
  });
});
