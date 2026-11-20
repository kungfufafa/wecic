import { createApiClient } from '@/api/client';
import { backgroundSyncNeedsPassword, deleteAccount, legalPageUrl, setBackgroundSync } from '@/lib/privacy';

function fakeApi(answers: { status: number; body: unknown }[]) {
  const requests: { url: string; method: string; body: unknown }[] = [];
  const fetch = jest.fn(async (url: string, init: RequestInit) => {
    requests.push({ url, method: init.method ?? 'GET', body: init.body ? JSON.parse(String(init.body)) : undefined });
    const answer = answers.shift();

    if (answer === undefined) {
      throw new Error(`No answer left for ${url}`);
    }

    return { status: answer.status, ok: answer.status < 300, json: async () => answer.body } as Response;
  });
  const api = createApiClient({ baseUrl: 'https://wecic.example/api/v1', getToken: () => 'token-1', fetch: fetch as unknown as typeof globalThis.fetch });

  return { api, requests };
}

const STATUS = { available: true, enabled: true, active: true, interval_minutes: 180, credential_days: 30 };

describe('legal pages', () => {
  it('points at the public pages of the configured server', () => {
    expect(legalPageUrl('privacy', 'https://wecic.rizqis.com/')).toBe('https://wecic.rizqis.com/privasi');
    expect(legalPageUrl('support', 'https://wecic.rizqis.com')).toBe('https://wecic.rizqis.com/bantuan');
    expect(legalPageUrl('dataDeletion', 'https://wecic.rizqis.com')).toBe('https://wecic.rizqis.com/hapus-data');
    expect(legalPageUrl('privacy', ' ')).toBeNull();
  });
});

describe('background sync', () => {
  it('asks for the password only while none is stored', () => {
    expect(backgroundSyncNeedsPassword({ available: true, enabled: false, active: false })).toBe(true);
    expect(backgroundSyncNeedsPassword({ available: true, enabled: true, active: false, pending_login: true })).toBe(true);
    expect(backgroundSyncNeedsPassword({ available: true, enabled: true, active: true })).toBe(false);
    expect(backgroundSyncNeedsPassword(null)).toBe(true);
  });

  it('switches on with the password and off without it', async () => {
    const { api, requests } = fakeApi([
      { status: 200, body: { status: 'success', message: 'Sinkronisasi background dinyalakan.', data: STATUS } },
      { status: 200, body: { status: 'success', message: 'Sinkronisasi background dimatikan.', data: { ...STATUS, enabled: false, active: false } } },
      { status: 200, body: { status: 'success', message: 'OK', data: STATUS } },
    ]);

    await expect(setBackgroundSync(api, true, 'rahasia')).resolves.toMatchObject({ data: { enabled: true } });
    await setBackgroundSync(api, false, 'rahasia');
    await setBackgroundSync(api, true, '');

    expect(requests.map((request) => `${request.method} ${request.url}`)).toEqual([
      'PATCH https://wecic.example/api/v1/archive/background-sync',
      'PATCH https://wecic.example/api/v1/archive/background-sync',
      'PATCH https://wecic.example/api/v1/archive/background-sync',
    ]);
    expect(requests.map((request) => request.body)).toEqual([{ enabled: true, password: 'rahasia' }, { enabled: false }, { enabled: true }]);
  });

  it('hands the password error to the form', async () => {
    const { api } = fakeApi([
      {
        status: 422,
        body: { status: 'error', message: 'Data tidak valid.', errors: { password: ['Password tidak cocok dengan password login terakhirmu.'] } },
      },
    ]);

    await expect(setBackgroundSync(api, true, 'salah')).rejects.toMatchObject({
      status: 422,
      fieldErrors: { password: 'Password tidak cocok dengan password login terakhirmu.' },
    });
  });
});

describe('account deletion', () => {
  it('sends the confirmation to the account endpoint', async () => {
    const { api, requests } = fakeApi([{ status: 200, body: { status: 'success', message: 'Akun WeCIC dan semua datamu sudah dihapus.', data: { notebooks: 1 } } }]);

    await expect(deleteAccount(api)).resolves.toMatchObject({ data: { notebooks: 1 } });

    expect(requests).toEqual([{ url: 'https://wecic.example/api/v1/account', method: 'DELETE', body: { confirm: true } }]);
  });
});
