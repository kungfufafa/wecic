import { SESSION_EXPIRED_NOTICE, SessionManager, STORAGE_KEYS, type SecureStorage } from '@/auth/session-manager';

type Route = { method: string; path: string; status: number; body: unknown };

/** Secure storage in memory, remembering which values were stored as sensitive. */
function memoryStorage(initial: Record<string, string> = {}) {
  const values = new Map(Object.entries(initial));
  const sensitive = new Set<string>();
  const storage: SecureStorage = {
    getItem: async (key) => values.get(key) ?? null,
    setItem: async (key, value, options) => {
      values.set(key, value);

      if (options?.sensitive) {
        sensitive.add(key);
      }
    },
    deleteItem: async (key) => {
      values.delete(key);
    },
  };

  return { storage, values, sensitive };
}

/** A server that answers each request with the next route queued for it. */
function fakeServer(routes: Route[]) {
  const requests: { method: string; path: string; headers: Record<string, string>; body: unknown }[] = [];
  const fetch = jest.fn(async (url: string, init: RequestInit) => {
    const path = url.replace('https://mycic.example/api/v1/', '');
    const method = init.method ?? 'GET';
    requests.push({ method, path, headers: init.headers as Record<string, string>, body: init.body ? JSON.parse(String(init.body)) : undefined });
    const index = routes.findIndex((route) => route.method === method && route.path === path);

    if (index === -1) {
      throw new Error(`Unexpected ${method} ${path}`);
    }

    const [route] = routes.splice(index, 1);

    return { status: route.status, ok: route.status < 300, json: async () => route.body } as Response;
  });

  return { fetch: fetch as unknown as typeof globalThis.fetch, requests, routes };
}

const success = (fields: Record<string, unknown>) => ({ status: 'success', message: 'OK', ...fields });
const REVERB = { key: 'app-key', host: 'reverb.example', port: 443, scheme: 'https' as const };
const realtimeOf = (channel: string) => ({ channel, config: REVERB });
const tokenLogin = (token: string, offline = false, channel = 'portal.session.x'): Route => ({
  method: 'POST',
  path: 'auth/token',
  status: 200,
  body: success({
    data: { user_info: { name: 'Siti Aminah', nim: '20990000501' }, expires_at: null, offline, offline_reconnect: null, token, token_type: 'Bearer', channel, realtime: REVERB },
  }),
});
const expired: Route = { method: 'GET', path: 'dashboard', status: 401, body: { status: 'error', message: 'Sesi kampus sudah berakhir. Silakan masuk lagi.' } };

function manager(routes: Route[], initial: Record<string, string> = {}) {
  const { storage, values, sensitive } = memoryStorage(initial);
  const server = fakeServer(routes);
  const owners: string[] = [];
  const ends: number[] = [];
  const session = new SessionManager({
    storage,
    baseUrl: 'https://mycic.example/api/v1',
    deviceName: 'Pixel 8',
    fetch: server.fetch,
    onOwner: (owner) => owners.push(owner),
    onEnd: () => ends.push(1),
  });

  return { session, values, sensitive, server, owners, ends };
}

describe('SessionManager', () => {
  it('signs in with a bearer token and keeps the password only with "Tetap masuk"', async () => {
    const { session, values, sensitive, server } = manager([tokenLogin('token-1')]);

    await session.signIn('20990000501', 'rahasia', true);

    expect(session.getState()).toEqual({
      status: 'signedIn',
      user: { name: 'Siti Aminah', nim: '20990000501' },
      offline: false,
      owner: '20990000501',
      realtime: realtimeOf('portal.session.x'),
    });
    expect(server.requests[0].body).toEqual({ username: '20990000501', password: 'rahasia', device_name: 'Pixel 8' });
    expect(values.get(STORAGE_KEYS.token)).toBe('token-1');
    expect(JSON.parse(values.get(STORAGE_KEYS.credentials) ?? '')).toEqual({ username: '20990000501', password: 'rahasia' });
    expect(sensitive).toEqual(new Set([STORAGE_KEYS.token, STORAGE_KEYS.credentials]));
  });

  it('forgets a kept password when the student signs in without "Tetap masuk"', async () => {
    const { session, values } = manager([tokenLogin('token-1')], {
      [STORAGE_KEYS.credentials]: JSON.stringify({ username: 'lama', password: 'lama' }),
    });

    await session.signIn('20990000501', 'rahasia', false);

    expect(values.has(STORAGE_KEYS.credentials)).toBe(false);
  });

  it('reports an offline login (portal down) as an offline session', async () => {
    const { session } = manager([tokenLogin('token-1', true)]);

    await session.signIn('20990000501', 'rahasia', true);

    expect(session.getState()).toMatchObject({ status: 'signedIn', offline: true });
  });

  it('starts signed out without a stored token', async () => {
    const { session } = manager([]);

    await session.restore();

    expect(session.getState()).toEqual({ status: 'signedOut', notice: null });
  });

  it('starts signed out when the secure storage cannot be read', async () => {
    const lockedStorage: SecureStorage = {
      ...memoryStorage().storage,
      getItem: async () => {
        throw new Error('Keychain locked');
      },
    };
    const session = new SessionManager({ storage: lockedStorage, baseUrl: 'https://mycic.example/api/v1', fetch: fakeServer([]).fetch });

    await session.restore();

    expect(session.getState()).toEqual({ status: 'signedOut', notice: null });
  });

  it('restores a stored login and refreshes it from the server', async () => {
    const { session, server } = manager(
      [{ method: 'GET', path: 'auth/session', status: 200, body: success({ data: { user_info: { name: 'Siti A.', nim: '1' }, offline: false, offline_reconnect: null } }) }],
      { [STORAGE_KEYS.token]: 'token-1', [STORAGE_KEYS.user]: JSON.stringify({ name: 'Siti Aminah' }), [STORAGE_KEYS.owner]: '20990000501' },
    );
    const states: string[] = [];
    session.subscribe(() => states.push(JSON.stringify(session.getState())));

    await session.restore();

    expect(JSON.parse(states[0])).toEqual({ status: 'signedIn', user: { name: 'Siti Aminah' }, offline: false, owner: '20990000501', realtime: null });
    expect(session.getState()).toEqual({ status: 'signedIn', user: { name: 'Siti A.', nim: '1' }, offline: false, owner: '20990000501', realtime: null });
    expect(server.requests[0].headers.Authorization).toBe('Bearer token-1');
  });

  it('stays signed in with what is stored when the server cannot be reached', async () => {
    const { session } = manager([], { [STORAGE_KEYS.token]: 'token-1', [STORAGE_KEYS.user]: JSON.stringify({ name: 'Siti' }) });

    await session.restore();

    expect(session.getState()).toMatchObject({ status: 'signedIn', user: { name: 'Siti' } });
  });

  it('logs in again with the kept password when the campus session ended, then retries', async () => {
    const { session, values, server } = manager([
      tokenLogin('token-1'),
      expired,
      tokenLogin('token-2'),
      { method: 'GET', path: 'dashboard', status: 200, body: success({ data: {} }) },
    ]);
    await session.signIn('20990000501', 'rahasia', true);

    await expect(session.api.get('dashboard')).resolves.toMatchObject({ status: 'success' });

    expect(server.requests.map((request) => `${request.method} ${request.path}`)).toEqual([
      'POST auth/token',
      'GET dashboard',
      'POST auth/token',
      'GET dashboard',
    ]);
    expect(server.requests[2].body).toMatchObject({ username: '20990000501', password: 'rahasia' });
    expect(server.requests[3].headers.Authorization).toBe('Bearer token-2');
    expect(values.get(STORAGE_KEYS.token)).toBe('token-2');
    expect(session.getState()).toMatchObject({ status: 'signedIn' });
  });

  it('signs out with a notice and forgets the password when the portal refuses it', async () => {
    const { session, values } = manager([
      tokenLogin('token-1'),
      expired,
      { method: 'POST', path: 'auth/token', status: 401, body: { status: 'error', message: 'Login gagal.' } },
    ]);
    await session.signIn('20990000501', 'rahasia', true);

    await expect(session.api.get('dashboard')).rejects.toMatchObject({ status: 401 });
    await flushPromises();

    expect(session.getState()).toEqual({ status: 'signedOut', notice: SESSION_EXPIRED_NOTICE });
    expect(values.has(STORAGE_KEYS.credentials)).toBe(false);
    expect(values.has(STORAGE_KEYS.token)).toBe(false);
  });

  it('signs out without a kept password as soon as the session ends', async () => {
    const { session, server } = manager([tokenLogin('token-1'), expired]);
    await session.signIn('20990000501', 'rahasia', false);

    await expect(session.api.get('dashboard')).rejects.toMatchObject({ status: 401 });
    await flushPromises();

    expect(session.getState()).toEqual({ status: 'signedOut', notice: SESSION_EXPIRED_NOTICE });
    expect(server.requests).toHaveLength(2);
  });

  it('logs out with the push token, without logging in again, and forgets everything', async () => {
    const { session, values, server } = manager([
      tokenLogin('token-1'),
      { method: 'POST', path: 'auth/logout', status: 401, body: { status: 'error', message: 'Sesi kampus sudah berakhir.' } },
    ]);
    await session.signIn('20990000501', 'rahasia', true);
    await session.rememberPushToken('ExponentPushToken[abc]');

    await session.signOut();

    expect(server.requests.map((request) => `${request.method} ${request.path}`)).toEqual(['POST auth/token', 'POST auth/logout']);
    expect(server.requests[1].body).toEqual({ push_token: 'ExponentPushToken[abc]' });
    expect(server.requests[1].headers.Authorization).toBe('Bearer token-1');
    expect(session.getState()).toEqual({ status: 'signedOut', notice: null });
    expect([...values.keys()]).toEqual([]);
  });

  it('prefills the NIM of a kept login', async () => {
    const { session } = manager([], { [STORAGE_KEYS.credentials]: JSON.stringify({ username: '20990000501', password: 'x' }) });

    await expect(session.rememberedUsername()).resolves.toBe('20990000501');
  });
});

function flushPromises(): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, 0));
}

describe('SessionManager session notices', () => {
  it('learns its channel from the session check at launch', async () => {
    const { session } = manager(
      [{ method: 'GET', path: 'auth/session', status: 200, body: success({ data: { user_info: { name: 'Siti' }, offline: false, offline_reconnect: null, channel: 'portal.session.y', realtime: REVERB } }) }],
      { [STORAGE_KEYS.token]: 'token-1', [STORAGE_KEYS.owner]: '20990000501' },
    );

    await session.restore();

    expect(session.getState()).toMatchObject({ status: 'signedIn', realtime: realtimeOf('portal.session.y') });
  });

  it('logs in again at once, on a new channel, when the server says the session ended', async () => {
    const { session, server } = manager([tokenLogin('token-1'), tokenLogin('token-2', false, 'portal.session.z')]);
    await session.signIn('20990000501', 'rahasia', true);

    await session.applySessionNotice('manual_relogin_required');

    expect(server.requests.map((request) => `${request.method} ${request.path}`)).toEqual(['POST auth/token', 'POST auth/token']);
    expect(session.getState()).toMatchObject({ status: 'signedIn', realtime: realtimeOf('portal.session.z') });
  });

  it('signs out with the notice when the session ended and no password is kept', async () => {
    const { session, server } = manager([tokenLogin('token-1')]);
    await session.signIn('20990000501', 'rahasia', false);

    await session.applySessionNotice('logged_out');

    expect(session.getState()).toEqual({ status: 'signedOut', notice: SESSION_EXPIRED_NOTICE });
    expect(server.requests).toHaveLength(1);
  });

  it('ignores the notice its own logout broadcasts', async () => {
    const { session, server } = manager([tokenLogin('token-1'), { method: 'POST', path: 'auth/logout', status: 200, body: success({}) }]);
    await session.signIn('20990000501', 'rahasia', true);

    const signingOut = session.signOut();
    await session.applySessionNotice('logged_out');
    await signingOut;

    expect(session.getState()).toEqual({ status: 'signedOut', notice: null });
    expect(server.requests.map((request) => `${request.method} ${request.path}`)).toEqual(['POST auth/token', 'POST auth/logout']);
  });

  it('follows an offline session back online, and takes the rest as news', async () => {
    const { session } = manager([tokenLogin('token-1', true)]);
    await session.signIn('20990000501', 'rahasia', true);

    await session.applySessionNotice('offline_resume_unavailable');
    expect(session.getState()).toMatchObject({ status: 'signedIn', offline: true });

    await session.applySessionNotice('session_refreshed');
    expect(session.getState()).toMatchObject({ status: 'signedIn', offline: true });

    await session.applySessionNotice('offline_session_resumed');
    expect(session.getState()).toMatchObject({ status: 'signedIn', offline: false });
  });
});

describe('SessionManager device data', () => {
  it('names the student who owns the kept data, by the NIM typed at login', async () => {
    const { session, values, owners } = manager([tokenLogin('token-1')]);

    await session.signIn(' 20990000501 ', 'rahasia', false);

    expect(owners).toEqual(['20990000501']);
    expect(values.get(STORAGE_KEYS.owner)).toBe('20990000501');
    expect(session.getState()).toMatchObject({ owner: '20990000501' });
  });

  it('finds the owner of a login kept from before, from the remembered NIM', async () => {
    const { session, owners } = manager([], {
      [STORAGE_KEYS.token]: 'token-1',
      [STORAGE_KEYS.credentials]: JSON.stringify({ username: '20990000501', password: 'rahasia' }),
    });

    await session.restore();

    expect(owners).toEqual(['20990000501']);
    expect(session.getState()).toMatchObject({ status: 'signedIn', owner: '20990000501' });
  });

  it('lets the kept data go when the student signs out', async () => {
    const { session, values, ends } = manager([tokenLogin('token-1'), { method: 'POST', path: 'auth/logout', status: 200, body: success({}) }]);

    await session.signIn('20990000501', 'rahasia', false);
    await session.signOut();

    expect(ends).toHaveLength(1);
    expect(values.has(STORAGE_KEYS.owner)).toBe(false);
  });
});
