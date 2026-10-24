import * as SecureStore from 'expo-secure-store';

import type { SecureStorage } from '@/auth/session-manager';

/**
 * The Keychain (iOS) and Keystore (Android). The token and the password
 * stay on this device only and are readable while it is unlocked; the rest
 * (name, push token) after the first unlock.
 */
export const deviceSecureStorage: SecureStorage = {
  getItem: (key) => SecureStore.getItemAsync(key),
  setItem: (key, value, options) =>
    SecureStore.setItemAsync(key, value, {
      keychainAccessible: options?.sensitive
        ? SecureStore.WHEN_UNLOCKED_THIS_DEVICE_ONLY
        : SecureStore.AFTER_FIRST_UNLOCK_THIS_DEVICE_ONLY,
    }),
  deleteItem: (key) => SecureStore.deleteItemAsync(key),
};
