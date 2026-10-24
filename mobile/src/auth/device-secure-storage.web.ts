import type { SecureStorage } from '@/auth/session-manager';

const values = new Map<string, string>();

/**
 * The web build (a preview while developing) has no secure storage, so
 * nothing is written to the browser: the login lasts until the tab closes.
 */
export const deviceSecureStorage: SecureStorage = {
  getItem: async (key) => values.get(key) ?? null,
  setItem: async (key, value) => {
    values.set(key, value);
  },
  deleteItem: async (key) => {
    values.delete(key);
  },
};
