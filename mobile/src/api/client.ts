import type { ApiEnvelope } from './types';

/**
 * A failed request: an answer the server refused (`status` is its HTTP
 * status) or none at all (`status` 0: no network, timeout).
 */
export class ApiError extends Error {
  readonly status: number;
  readonly body: ApiEnvelope | null;

  constructor(message: string, status: number, body: ApiEnvelope | null = null) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.body = body;
  }

  /** The first message of each invalid field (a 422). */
  get fieldErrors(): Record<string, string> {
    const errors = this.body?.errors ?? {};

    return Object.fromEntries(
      Object.entries(errors)
        .filter(([, messages]) => messages.length > 0)
        .map(([field, messages]) => [field, messages[0]]),
    );
  }
}

export type RequestOptions = {
  body?: unknown;
  /** Send the bearer token (default true). */
  auth?: boolean;
  /** Log in again on a 401 and send once more (default true; the logout does not). */
  reauthenticate?: boolean;
  /** An automatic check, not the student's action: does not extend the session's idle time. */
  background?: boolean;
  signal?: AbortSignal;
  /** How long to wait for this request; see requestTimeoutMs for the defaults. */
  timeoutMs?: number;
};

export type ApiClientOptions = {
  /** Base of the versioned API, e.g. `https://mycic.example/api/v1`. */
  baseUrl: string;
  getToken: () => string | null;
  /**
   * Called on a 401 of an authenticated request (the campus session ended):
   * logs in again and resolves true once a new token is in place, so the
   * request is sent once more.
   */
  reauthenticate?: () => Promise<boolean>;
  /** A 401 that a new login could not cure. */
  onSessionExpired?: () => void;
  /** Told after every request whether the server could be reached (a timeout tells nothing). */
  onReachability?: (reachable: boolean) => void;
  fetch?: typeof fetch;
  timeoutMs?: number;
};

export type ApiClient = {
  request<T extends ApiEnvelope>(method: string, path: string, options?: RequestOptions): Promise<T>;
  get<T extends ApiEnvelope>(path: string, options?: RequestOptions): Promise<T>;
  post<T extends ApiEnvelope>(path: string, body?: unknown, options?: RequestOptions): Promise<T>;
  delete<T extends ApiEnvelope>(path: string, body?: unknown, options?: RequestOptions): Promise<T>;
  patch<T extends ApiEnvelope>(path: string, body?: unknown, options?: RequestOptions): Promise<T>;
  /** The full URL of an API path, for a request the client does not send itself (a file download). */
  url(path: string): string;
  /** The headers that sign a request as the student. */
  authHeaders(): Record<string, string>;
};

export const NETWORK_ERROR_MESSAGE = 'Tidak bisa terhubung ke MyCIC. Periksa koneksi internet lalu coba lagi.';

export const TIMEOUT_ERROR_MESSAGE = 'Portal kampus terlalu lama menjawab. Coba lagi sebentar lagi.';

/**
 * How long a request may wait, as the web app waits (resources/js/lib/http.js):
 * a read 30 s, a write 60 s, an upload 3 minutes. A request's own
 * `timeoutMs`, then the client's, win.
 */
export function requestTimeoutMs(method: string, body: unknown, ownTimeoutMs?: number, clientTimeoutMs?: number): number {
  if (ownTimeoutMs !== undefined) {
    return ownTimeoutMs;
  }

  if (clientTimeoutMs !== undefined) {
    return clientTimeoutMs;
  }

  if (method === 'GET') {
    return 30_000;
  }

  return typeof FormData !== 'undefined' && body instanceof FormData ? 180_000 : 60_000;
}

/**
 * The words for a failed answer that carries no message of its own (a
 * proxy's HTML page): the same the web app shows (resources/js/lib/http.js).
 */
export function statusMessage(status: number): string {
  if (status === 401) {
    return 'Sesi kampus sudah berakhir. Silakan masuk lagi.';
  }

  if (status === 403) {
    return 'Kamu tidak punya akses ke data ini.';
  }

  if (status === 404) {
    return 'Data yang diminta tidak ditemukan.';
  }

  if (status === 429) {
    return 'Terlalu sering mencoba. Tunggu sebentar lalu coba lagi.';
  }

  if (status >= 500) {
    return 'Server sedang bermasalah. Coba lagi sebentar lagi.';
  }

  return 'Permintaan tidak bisa diproses. Coba lagi sebentar lagi.';
}

export function createApiClient(options: ApiClientOptions): ApiClient {
  const baseUrl = options.baseUrl.replace(/\/+$/, '');
  let reauthentication: Promise<boolean> | null = null;

  /** One login for every request that met the same 401. */
  function reauthenticateOnce(): Promise<boolean> {
    if (!options.reauthenticate) {
      return Promise.resolve(false);
    }

    reauthentication ??= options.reauthenticate()
      .catch(() => false)
      .finally(() => {
        reauthentication = null;
      });

    return reauthentication;
  }

  async function send(method: string, path: string, requestOptions: RequestOptions): Promise<Response> {
    const headers: Record<string, string> = { Accept: 'application/json' };
    const token = requestOptions.auth === false ? null : options.getToken();

    if (token) {
      headers.Authorization = `Bearer ${token}`;
    }

    if (requestOptions.background) {
      headers['X-MyCIC-Background'] = '1';
    }

    let body: string | FormData | undefined;

    // A file upload goes as multipart; fetch sets its boundary itself.
    if (typeof FormData !== 'undefined' && requestOptions.body instanceof FormData) {
      body = requestOptions.body;
    } else if (requestOptions.body !== undefined) {
      headers['Content-Type'] = 'application/json';
      body = JSON.stringify(requestOptions.body);
    }

    const controller = new AbortController();
    let timedOut = false;
    const timeout = setTimeout(() => {
      timedOut = true;
      controller.abort();
    }, requestTimeoutMs(method, requestOptions.body, requestOptions.timeoutMs, options.timeoutMs));
    const abortFromCaller = () => controller.abort();
    requestOptions.signal?.addEventListener('abort', abortFromCaller);

    try {
      // Read at each request, so a fetch a debugger installs later is used too.
      const response = await (options.fetch ?? globalThis.fetch)(`${baseUrl}/${path.replace(/^\/+/, '')}`, {
        method,
        headers,
        body,
        signal: controller.signal,
      });
      options.onReachability?.(true);

      return response;
    } catch {
      if (timedOut) {
        throw new ApiError(TIMEOUT_ERROR_MESSAGE, 0);
      }

      if (!requestOptions.signal?.aborted) {
        options.onReachability?.(false);
      }

      throw new ApiError(NETWORK_ERROR_MESSAGE, 0);
    } finally {
      clearTimeout(timeout);
      requestOptions.signal?.removeEventListener('abort', abortFromCaller);
    }
  }

  async function request<T extends ApiEnvelope>(method: string, path: string, requestOptions: RequestOptions = {}): Promise<T> {
    const recovers = requestOptions.auth !== false && requestOptions.reauthenticate !== false;
    let response = await send(method, path, requestOptions);

    if (response.status === 401 && recovers && options.getToken() !== null) {
      if (await reauthenticateOnce()) {
        response = await send(method, path, requestOptions);
      }

      if (response.status === 401) {
        options.onSessionExpired?.();
      }
    }

    const payload = await readJson(response);

    if (!response.ok || payload?.status === 'error') {
      const message = typeof payload?.message === 'string' && payload.message.trim() !== ''
        ? payload.message.trim()
        : statusMessage(response.status);

      throw new ApiError(message, response.status, payload);
    }

    if (payload === null) {
      throw new ApiError('Server mengirim data yang tidak bisa dibaca. Coba lagi sebentar lagi.', response.status);
    }

    return payload as T;
  }

  return {
    request,
    get: (path, requestOptions) => request('GET', path, requestOptions),
    post: (path, body, requestOptions) => request('POST', path, { ...requestOptions, body }),
    delete: (path, body, requestOptions) => request('DELETE', path, { ...requestOptions, body }),
    patch: (path, body, requestOptions) => request('PATCH', path, { ...requestOptions, body }),
    url: (path) => `${baseUrl}/${path.replace(/^\/+/, '')}`,
    authHeaders: () => {
      const headers: Record<string, string> = { Accept: 'application/json' };
      const token = options.getToken();

      if (token) {
        headers.Authorization = `Bearer ${token}`;
      }

      return headers;
    },
  };
}

async function readJson(response: Response): Promise<ApiEnvelope | null> {
  try {
    const payload: unknown = await response.json();

    return payload !== null && typeof payload === 'object' && !Array.isArray(payload) ? (payload as ApiEnvelope) : null;
  } catch {
    return null;
  }
}
