import { ApiError, createApiClient, NETWORK_ERROR_MESSAGE, type ApiClientOptions } from '@/api/client';
import { apiBaseUrl, webUrl } from '@/api/config';
import { requestTimeoutMs } from '@/api/client';

type FakeAnswer = { status: number; body?: unknown };

/** A fetch that answers in order and records each request. */
function fakeFetch(answers: (FakeAnswer | Error)[]) {
  const calls: { url: string; init: RequestInit }[] = [];
  const fetch = jest.fn(async (url: string, init: RequestInit) => {
    calls.push({ url, init });
    const answer = answers.shift();

    if (answer === undefined) {
      throw new Error(`No answer left for ${url}`);
    }

    if (answer instanceof Error) {
      throw answer;
    }

    return {
      status: answer.status,
      ok: answer.status >= 200 && answer.status < 300,
      json: async () => {
        if (typeof answer.body === 'string') {
          throw new SyntaxError('Unexpected token <');
        }

        return answer.body;
      },
    } as Response;
  });

  return { fetch: fetch as unknown as typeof globalThis.fetch, calls };
}

function client(answers: (FakeAnswer | Error)[], options: Partial<ApiClientOptions> = {}) {
  const fake = fakeFetch(answers);
  let token: string | null = 'token-1';
  const api = createApiClient({
    baseUrl: 'https://mycic.example/api/v1/',
    getToken: () => token,
    fetch: fake.fetch,
    ...options,
  });

  return { api, calls: fake.calls, setToken: (value: string | null) => (token = value) };
}

const ok = (fields: Record<string, unknown> = {}): FakeAnswer => ({ status: 200, body: { status: 'success', message: 'OK', ...fields } });
const expired: FakeAnswer = {
  status: 401,
  body: { status: 'error', message: 'Sesi kampus sudah berakhir. Silakan masuk lagi.', meta: { campus_session_valid: false } },
};

describe('apiBaseUrl', () => {
  it('points at version 1 under the configured origin', () => {
    expect(apiBaseUrl('https://mycic.example/')).toBe('https://mycic.example/api/v1');
    expect(apiBaseUrl('  ')).toBeNull();
    expect(apiBaseUrl(undefined)).toBeNull();
  });

  it('gives the website at the origin itself', () => {
    expect(webUrl('https://mycic.example//')).toBe('https://mycic.example');
    expect(webUrl(' ')).toBeNull();
    expect(webUrl(undefined)).toBeNull();
  });
});

describe('createApiClient', () => {
  it('sends a file upload as multipart, leaving the boundary to fetch', async () => {
    const { api, calls } = client([ok({ data: { queued: false } })]);
    const form = new FormData();
    form.append('courses_id', '8810');

    await api.post('assignment', form);

    expect(calls[0].init.body).toBe(form);
    expect((calls[0].init.headers as Record<string, string>)['Content-Type']).toBeUndefined();
  });

  it('gives full URLs and the signing headers for requests it does not send', () => {
    const { api } = client([]);

    expect(api.url('/support/download?path=x')).toBe('https://mycic.example/api/v1/support/download?path=x');
    expect(api.authHeaders().Authorization).toMatch(/^Bearer /);
    expect(api.authHeaders().Accept).toBe('application/json');
  });

  it('sends a PATCH with a JSON body', async () => {
    const { api, calls } = client([ok({ data: { enabled: false } })]);

    await api.patch('archive/background-sync', { enabled: false });

    expect(calls[0].init.method).toBe('PATCH');
    expect(calls[0].init.body).toBe(JSON.stringify({ enabled: false }));
  });

  it('sends the bearer token and a JSON body to the versioned path', async () => {
    const { api, calls } = client([ok({ data: { updated: 1 } })]);

    const response = await api.post('/notifications/read', { ids: [3] });

    expect(response).toMatchObject({ status: 'success', data: { updated: 1 } });
    expect(calls[0].url).toBe('https://mycic.example/api/v1/notifications/read');
    expect(calls[0].init.method).toBe('POST');
    expect(calls[0].init.body).toBe('{"ids":[3]}');
    expect(calls[0].init.headers).toMatchObject({
      Accept: 'application/json',
      Authorization: 'Bearer token-1',
      'Content-Type': 'application/json',
    });
  });

  it('marks automatic checks so they do not extend the session', async () => {
    const { api, calls } = client([ok()]);

    await api.get('notifications/unread-count', { background: true });

    expect(calls[0].init.headers).toMatchObject({ 'X-MyCIC-Background': '1' });
  });

  it('throws the server message and the first error of each field', async () => {
    const { api } = client([
      {
        status: 422,
        body: { status: 'error', message: 'NIM wajib diisi.', errors: { username: ['NIM wajib diisi.', 'lain'], password: [] } },
      },
    ]);

    const error = await api.post('auth/token', {}, { auth: false }).catch((caught: unknown) => caught);

    expect(error).toBeInstanceOf(ApiError);
    expect(error).toMatchObject({ message: 'NIM wajib diisi.', status: 422 });
    expect((error as ApiError).fieldErrors).toEqual({ username: 'NIM wajib diisi.' });
  });

  it('words an answer without a message by its status', async () => {
    const { api } = client([{ status: 502, body: '<html>Bad Gateway</html>' }]);

    await expect(api.get('dashboard')).rejects.toMatchObject({
      status: 502,
      message: 'Server sedang bermasalah. Coba lagi sebentar lagi.',
    });
  });

  it('turns a failed connection into a status 0 error', async () => {
    const { api } = client([new TypeError('Network request failed')]);

    await expect(api.get('dashboard')).rejects.toMatchObject({ status: 0, message: NETWORK_ERROR_MESSAGE });
  });

  it('logs in again on a 401 and sends the request once more with the new token', async () => {
    const onSessionExpired = jest.fn();
    let setToken: (value: string | null) => void = () => {};
    const reauthenticate = jest.fn(async () => {
      setToken('token-2');

      return true;
    });
    const harness = client([expired, ok({ data: { classes_summary: {} } })], { reauthenticate, onSessionExpired });
    setToken = harness.setToken;

    await expect(harness.api.get('dashboard')).resolves.toMatchObject({ status: 'success' });

    expect(reauthenticate).toHaveBeenCalledTimes(1);
    expect(onSessionExpired).not.toHaveBeenCalled();
    expect(harness.calls[1].init.headers).toMatchObject({ Authorization: 'Bearer token-2' });
  });

  it('shares one login between requests that met the same 401', async () => {
    let finishLogin: (value: boolean) => void = () => {};
    const reauthenticate = jest.fn(() => new Promise<boolean>((resolve) => (finishLogin = resolve)));
    const { api } = client([expired, expired, ok(), ok()], { reauthenticate });

    const first = api.get('dashboard');
    const second = api.get('class');
    await new Promise((resolve) => setTimeout(resolve, 0));
    finishLogin(true);

    await expect(Promise.all([first, second])).resolves.toHaveLength(2);
    expect(reauthenticate).toHaveBeenCalledTimes(1);
  });

  it('ends the session when a new login cannot cure the 401', async () => {
    const onSessionExpired = jest.fn();
    const { api } = client([expired], { reauthenticate: async () => false, onSessionExpired });

    await expect(api.get('dashboard')).rejects.toMatchObject({ status: 401 });
    expect(onSessionExpired).toHaveBeenCalledTimes(1);
  });

  it('never re-logs in for a request without the token or one that opts out', async () => {
    const reauthenticate = jest.fn(async () => true);
    const onSessionExpired = jest.fn();
    const { api, calls } = client([{ status: 401, body: { status: 'error', message: 'Login gagal.' } }, expired], {
      reauthenticate,
      onSessionExpired,
    });

    await expect(api.post('auth/token', {}, { auth: false })).rejects.toMatchObject({ message: 'Login gagal.' });
    await expect(api.post('auth/logout', {}, { reauthenticate: false })).rejects.toMatchObject({ status: 401 });

    expect(reauthenticate).not.toHaveBeenCalled();
    expect(onSessionExpired).not.toHaveBeenCalled();
    expect(calls[0].init.headers).not.toHaveProperty('Authorization');
  });
});

describe('requestTimeoutMs', () => {
  it('waits as the web app does: reads, writes, uploads', () => {
    expect(requestTimeoutMs('GET', undefined)).toBe(30_000);
    expect(requestTimeoutMs('POST', { kode: 'a' })).toBe(60_000);
    expect(requestTimeoutMs('POST', new FormData())).toBe(180_000);
  });

  it('lets the request, then the client, choose', () => {
    expect(requestTimeoutMs('GET', undefined, 90_000, 5_000)).toBe(90_000);
    expect(requestTimeoutMs('GET', undefined, undefined, 5_000)).toBe(5_000);
  });
});
