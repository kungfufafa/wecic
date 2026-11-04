import { File, Paths } from 'expo-file-system';
import { useSyncExternalStore } from 'react';
import { Appearance, Platform } from 'react-native';

import { parseAppearancePreference, type AppearancePreference } from '@/lib/appearance';

/**
 * The student's light/dark pick, kept in a small file so the app opens in
 * the right mode, and handed to the OS so native controls (alerts, the
 * keyboard, switches, the tab bar) follow it. The web preview keeps it in
 * memory only.
 */

const available = Platform.OS !== 'web';
const listeners = new Set<() => void>();
let preference: AppearancePreference = readStored();

applyToNative(preference);

function preferenceFile(): File {
  return new File(Paths.document, 'appearance.json');
}

function readStored(): AppearancePreference {
  if (!available) {
    return 'system';
  }

  try {
    const file = preferenceFile();

    return file.exists ? parseAppearancePreference(JSON.parse(file.textSync())?.preference) : 'system';
  } catch {
    return 'system';
  }
}

function applyToNative(value: AppearancePreference): void {
  if (available) {
    Appearance.setColorScheme(value === 'system' ? 'auto' : value);
  }
}

export function setAppearancePreference(value: AppearancePreference): void {
  preference = value;
  applyToNative(value);
  listeners.forEach((listener) => listener());

  if (!available) {
    return;
  }

  try {
    const file = preferenceFile();

    if (!file.exists) {
      file.create();
    }

    file.writeSync(JSON.stringify({ preference: value }));
  } catch {
    // Kept for this run only; the next launch follows the device.
  }
}

function subscribe(listener: () => void): () => void {
  listeners.add(listener);

  return () => {
    listeners.delete(listener);
  };
}

export function useAppearancePreference(): AppearancePreference {
  return useSyncExternalStore(subscribe, () => preference);
}
