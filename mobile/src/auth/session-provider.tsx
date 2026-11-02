import * as Device from 'expo-device';
import { createContext, use, useEffect, useState, useSyncExternalStore, type ReactNode } from 'react';

import { ApiError, type ApiClient } from '@/api/client';
import { apiBaseUrl, MISSING_API_URL_MESSAGE } from '@/api/config';
import { deviceSecureStorage } from '@/auth/device-secure-storage';
import { SessionManager, type SessionState } from '@/auth/session-manager';
import { registerForPushNotifications, type PushRegistration } from '@/push/push-notifications';

type SessionContextValue = {
  state: SessionState;
  api: ApiClient;
  push: PushRegistration | null;
  signIn(username: string, password: string, remember: boolean): Promise<void>;
  signOut(): Promise<void>;
  rememberedUsername(): Promise<string | null>;
};

const SessionContext = createContext<SessionContextValue | null>(null);

const baseUrl = apiBaseUrl();

/** One session for the whole app, so its token is shared by every screen. */
const manager = baseUrl === null
  ? null
  : new SessionManager({
      storage: deviceSecureStorage,
      baseUrl,
      deviceName: Device.deviceName ?? Device.modelName,
    });

const unconfiguredState: SessionState = { status: 'signedOut', notice: MISSING_API_URL_MESSAGE };

const unconfiguredApi: ApiClient = {
  request: () => Promise.reject(new ApiError(MISSING_API_URL_MESSAGE, 0)),
  get: () => Promise.reject(new ApiError(MISSING_API_URL_MESSAGE, 0)),
  post: () => Promise.reject(new ApiError(MISSING_API_URL_MESSAGE, 0)),
  delete: () => Promise.reject(new ApiError(MISSING_API_URL_MESSAGE, 0)),
  patch: () => Promise.reject(new ApiError(MISSING_API_URL_MESSAGE, 0)),
  url: (path) => path,
  authHeaders: () => ({}),
};

const noSubscription = () => () => {};

export function SessionProvider({ children }: { children: ReactNode }) {
  const state = useSyncExternalStore(
    manager?.subscribe ?? noSubscription,
    manager?.getState ?? (() => unconfiguredState),
  );
  const [push, setPush] = useState<PushRegistration | null>(null);
  const signedIn = state.status === 'signedIn';

  useEffect(() => {
    void manager?.restore();
  }, []);

  // Every launch while signed in, and after each login: the server keeps
  // only the push tokens an install keeps registering.
  useEffect(() => {
    if (!signedIn || manager === null) {
      return;
    }

    let current = true;

    void registerForPushNotifications(manager.api).then(async (registration) => {
      if (registration.status === 'registered') {
        await manager.rememberPushToken(registration.token);
      }

      if (current) {
        setPush(registration);
      }
    });

    return () => {
      current = false;
    };
  }, [signedIn]);

  const value: SessionContextValue = {
    state,
    api: manager?.api ?? unconfiguredApi,
    push: signedIn ? push : null,
    signIn: (username, password, remember) =>
      manager?.signIn(username, password, remember) ?? Promise.reject(new ApiError(MISSING_API_URL_MESSAGE, 0)),
    signOut: () => manager?.signOut() ?? Promise.resolve(),
    rememberedUsername: () => manager?.rememberedUsername() ?? Promise.resolve(null),
  };

  return <SessionContext value={value}>{children}</SessionContext>;
}

export function useSession(): SessionContextValue {
  const value = use(SessionContext);

  if (value === null) {
    throw new Error('useSession must be wrapped in a <SessionProvider />');
  }

  return value;
}
