import { ApiError, createApiClient, type ApiClient } from '@/api/client';
import type { SessionResponse, TokenLoginResponse, UserInfo } from '@/api/types';

/**
 * Where the session lives between launches: the device's secure storage
 * (Keychain / Keystore) in the app, a map in tests.
 */
export type SecureStorage = {
  getItem(key: string): Promise<string | null>;
  setItem(key: string, value: string, options?: { sensitive?: boolean }): Promise<void>;
  deleteItem(key: string): Promise<void>;
};

export type SessionState =
  | { status: 'loading' }
  | { status: 'signedOut'; notice: string | null }
  | { status: 'signedIn'; user: UserInfo | null; offline: boolean };

export const STORAGE_KEYS = {
  token: 'mycic.token',
  user: 'mycic.user',
  credentials: 'mycic.credentials',
  pushToken: 'mycic.pushToken',
} as const;

export const SESSION_EXPIRED_NOTICE = 'Sesi kampus sudah berakhir. Silakan masuk lagi.';

type StoredCredentials = { username: string; password: string };

type SessionManagerOptions = {
  storage: SecureStorage;
  baseUrl: string;
  deviceName?: string | null;
  fetch?: typeof fetch;
};

/**
 * The student's login on this device. The bearer token from
 * `POST /auth/token` lives as long as the campus session, which ends after
 * two idle hours; with "Tetap masuk" the NIM and password are kept in secure
 * storage, and a request that meets the 401 logs in again on its own and is
 * sent once more. Without them, or when the portal refuses the password,
 * the student is sent to the login screen.
 */
export class SessionManager {
  readonly api: ApiClient;

  private token: string | null = null;
  private state: SessionState = { status: 'loading' };
  private readonly listeners = new Set<() => void>();
  private readonly storage: SecureStorage;
  private readonly deviceName: string | null;

  constructor(options: SessionManagerOptions) {
    this.storage = options.storage;
    this.deviceName = options.deviceName ?? null;
    this.api = createApiClient({
      baseUrl: options.baseUrl,
      fetch: options.fetch,
      getToken: () => this.token,
      reauthenticate: () => this.reauthenticate(),
      onSessionExpired: () => {
        void this.end(SESSION_EXPIRED_NOTICE);
      },
    });
  }

  getState = (): SessionState => this.state;

  subscribe = (listener: () => void): (() => void) => {
    this.listeners.add(listener);

    return () => {
      this.listeners.delete(listener);
    };
  };

  /**
   * At launch: a stored token signs the student in right away (the app also
   * opens without a network), then the server is asked whether the session
   * still holds; a 401 there re-logs in or signs out like any request. A
   * storage that cannot be read leaves the student signed out.
   */
  async restore(): Promise<void> {
    try {
      this.token = await this.storage.getItem(STORAGE_KEYS.token);
    } catch {
      this.token = null;
    }

    if (this.token === null) {
      this.setState({ status: 'signedOut', notice: null });

      return;
    }

    this.setState({ status: 'signedIn', user: await this.storedUser(), offline: false });

    try {
      const session = await this.api.get<SessionResponse>('auth/session');

      if (session.data !== null && this.state.status === 'signedIn') {
        await this.signedIn(session.data.user_info, session.data.offline);
      }
    } catch {
      // No network: stay signed in with what is stored. A 401 already ended the session.
    }
  }

  /**
   * @throws ApiError with the server's message (wrong password, portal down)
   */
  async signIn(username: string, password: string, remember: boolean): Promise<void> {
    const login = await this.login(username, password);

    if (remember) {
      await this.storage.setItem(STORAGE_KEYS.credentials, JSON.stringify({ username, password }), { sensitive: true });
    } else {
      await this.storage.deleteItem(STORAGE_KEYS.credentials);
    }

    await this.signedIn(login.data.user_info, login.data.offline);
  }

  /**
   * Logs out on the server (best effort: the device forgets the session
   * either way) and stops this install's pushes.
   */
  async signOut(): Promise<void> {
    const pushToken = await this.storage.getItem(STORAGE_KEYS.pushToken);
    await this.storage.deleteItem(STORAGE_KEYS.credentials);

    if (this.token !== null) {
      try {
        await this.api.post('auth/logout', pushToken !== null ? { push_token: pushToken } : {}, { reauthenticate: false });
      } catch {
        // Already ended on the server, or no network: the token is forgotten below.
      }
    }

    await this.storage.deleteItem(STORAGE_KEYS.pushToken);
    await this.end(null);
  }

  /** The Expo push token this install registered, sent along at logout. */
  async rememberPushToken(pushToken: string): Promise<void> {
    await this.storage.setItem(STORAGE_KEYS.pushToken, pushToken);
  }

  /** The NIM of the last "Tetap masuk" login, to prefill the login form. */
  async rememberedUsername(): Promise<string | null> {
    return (await this.storedCredentials())?.username ?? null;
  }

  private async reauthenticate(): Promise<boolean> {
    const credentials = await this.storedCredentials();

    if (credentials === null) {
      return false;
    }

    try {
      const login = await this.login(credentials.username, credentials.password);
      await this.signedIn(login.data.user_info, login.data.offline);

      return true;
    } catch (error) {
      // The portal refused the password (changed since): never try it again.
      if (error instanceof ApiError && (error.status === 401 || error.status === 422)) {
        await this.storage.deleteItem(STORAGE_KEYS.credentials);
      }

      return false;
    }
  }

  private async login(username: string, password: string): Promise<TokenLoginResponse> {
    const login = await this.api.post<TokenLoginResponse>(
      'auth/token',
      { username, password, ...(this.deviceName ? { device_name: this.deviceName } : {}) },
      { auth: false },
    );

    this.token = login.data.token;
    await this.storage.setItem(STORAGE_KEYS.token, login.data.token, { sensitive: true });

    return login;
  }

  private async signedIn(user: UserInfo | null, offline: boolean): Promise<void> {
    await this.storage.setItem(STORAGE_KEYS.user, JSON.stringify(user ?? null));
    this.setState({ status: 'signedIn', user, offline });
  }

  private async end(notice: string | null): Promise<void> {
    this.token = null;
    await this.storage.deleteItem(STORAGE_KEYS.token);
    await this.storage.deleteItem(STORAGE_KEYS.user);
    this.setState({ status: 'signedOut', notice });
  }

  private async storedUser(): Promise<UserInfo | null> {
    return parseJson<UserInfo>(await this.storage.getItem(STORAGE_KEYS.user));
  }

  private async storedCredentials(): Promise<StoredCredentials | null> {
    const credentials = parseJson<Partial<StoredCredentials>>(await this.storage.getItem(STORAGE_KEYS.credentials));

    return typeof credentials?.username === 'string' && typeof credentials.password === 'string'
      ? { username: credentials.username, password: credentials.password }
      : null;
  }

  private setState(state: SessionState): void {
    this.state = state;
    this.listeners.forEach((listener) => listener());
  }
}

function parseJson<T>(value: string | null): T | null {
  if (value === null) {
    return null;
  }

  try {
    return JSON.parse(value) as T;
  } catch {
    return null;
  }
}
