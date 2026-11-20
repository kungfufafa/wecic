import * as Device from 'expo-device';
import { createContext, use, useEffect, useState, useSyncExternalStore, type ReactNode } from 'react';
import { AppState } from 'react-native';

import { ApiError, type ApiClient } from '@/api/client';
import { apiBaseUrl, MISSING_API_URL_MESSAGE } from '@/api/config';
import { deviceSecureStorage } from '@/auth/device-secure-storage';
import { SessionManager, type SessionState } from '@/auth/session-manager';
import { clearAiConsent } from '@/lib/ai-consent';
import { setReachable } from '@/lib/connectivity';
import { clearDeviceCache, keepOnlyOwner } from '@/lib/device-cache';
import { registerForPushNotifications, type PushRegistration } from '@/push/push-notifications';
import { connectRealtime, disconnectRealtime, onRealtime, resumeRealtime, type ChannelAuth } from '@/realtime/portal-realtime';

type SessionContextValue = {
  state: SessionState;
  api: ApiClient;
  push: PushRegistration | null;
  signIn(username: string, password: string, remember: boolean, backgroundSync?: boolean): Promise<void>;
  signOut(): Promise<boolean>;
  /** Erases the WeCIC account and its data on the server, then signs out. */
  deleteAccount(): Promise<void>;
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
      onReachability: setReachable,
      onOwner: keepOnlyOwner,
      onEnd: () => {
        clearDeviceCache();
        clearAiConsent();
      },
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
  const realtime = state.status === 'signedIn' ? state.realtime : null;
  const realtimeKey = realtime === null ? null : JSON.stringify(realtime);

  useEffect(() => {
    void manager?.restore();
  }, []);

  // The session's realtime channel, as long as the server names one: data
  // changes, upload progress and session notices arrive at once instead of
  // on the next poll. A new login (a new channel) connects again.
  useEffect(() => {
    if (manager === null || realtimeKey === null) {
      disconnectRealtime();

      return;
    }

    const api = manager.api;
    connectRealtime(JSON.parse(realtimeKey), (socketId, channelName) =>
      api.post<ChannelAuth & { status: 'success'; message: string }>('broadcasting/auth', { socket_id: socketId, channel_name: channelName }, { background: true }),
    );
    const notices = onRealtime('sessionNotice', (payload) => {
      void manager.applySessionNotice(payload.status);
    });
    const appState = AppState.addEventListener('change', (status) => {
      if (status === 'active') {
        resumeRealtime();
      }
    });

    return () => {
      notices();
      appState.remove();
      disconnectRealtime();
    };
  }, [realtimeKey]);

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
    signIn: (username, password, remember, backgroundSync) =>
      manager?.signIn(username, password, remember, backgroundSync) ?? Promise.reject(new ApiError(MISSING_API_URL_MESSAGE, 0)),
    signOut: () => manager?.signOut() ?? Promise.resolve(false),
    deleteAccount: () => manager?.deleteAccount() ?? Promise.reject(new ApiError(MISSING_API_URL_MESSAGE, 0)),
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
