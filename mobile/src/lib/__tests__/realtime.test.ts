import {
  invalidatedPaths,
  operationFromProgress,
  pathIsInvalidated,
  realtimeStatusLabel,
  realtimeTargetFrom,
  sessionNoticeAction,
} from '@/lib/realtime';

describe('realtimeTargetFrom', () => {
  const realtime = { key: 'app-key', host: 'reverb.example.ac.id', port: 443, scheme: 'https' };

  it('reads the channel and server a login names', () => {
    expect(realtimeTargetFrom({ channel: 'portal.session.abc', realtime })).toEqual({ channel: 'portal.session.abc', config: realtime });
  });

  it('has nothing while the server broadcasts nothing, or does not say', () => {
    expect(realtimeTargetFrom({ channel: 'portal.session.abc', realtime: null })).toBeNull();
    expect(realtimeTargetFrom({ channel: 'portal.session.abc' })).toBeNull();
    expect(realtimeTargetFrom({ realtime })).toBeNull();
    expect(realtimeTargetFrom({ channel: 'portal.session.abc', realtime: { ...realtime, key: '' } })).toBeNull();
    expect(realtimeTargetFrom(null)).toBeNull();
  });

  it('fills the port from the scheme and never takes an unknown scheme', () => {
    expect(realtimeTargetFrom({ channel: 'c', realtime: { key: 'k', host: 'h', scheme: 'http' } })?.config).toEqual({ key: 'k', host: 'h', port: 80, scheme: 'http' });
    expect(realtimeTargetFrom({ channel: 'c', realtime: { key: 'k', host: 'h', port: '8080', scheme: 'ws' } })?.config).toEqual({ key: 'k', host: 'h', port: 8080, scheme: 'https' });
  });
});

describe('invalidatedPaths', () => {
  it('turns the web cache keys into this client paths', () => {
    expect(invalidatedPaths({ cache_keys: ['/api/dashboard', '/api/attendance/today', '/api/v1/class', 'api/profile'] })).toEqual([
      'dashboard',
      'attendance/today',
      'class',
      'profile',
    ]);
  });

  it('adds the paths the sync keys stand for, once each', () => {
    expect(invalidatedPaths({ cache_keys: ['/api/class'], sync_keys: ['class-index', 'class-show', 'meeting-show', 'portal-context'] })).toEqual([
      'class',
      'class/',
      'meeting/',
    ]);
  });

  it('ignores a payload without keys', () => {
    expect(invalidatedPaths({ cache_keys: 'nope', sync_keys: [42] })).toEqual([]);
    expect(invalidatedPaths(null)).toEqual([]);
  });
});

describe('pathIsInvalidated', () => {
  it('matches the same path, its query aside, and paths under a prefix', () => {
    expect(pathIsInvalidated('dashboard', ['dashboard'])).toBe(true);
    expect(pathIsInvalidated('dashboard?fresh=1', ['dashboard'])).toBe(true);
    expect(pathIsInvalidated('exam/12', ['exam'])).toBe(true);
    expect(pathIsInvalidated('class/9520', ['class/'])).toBe(true);
    expect(pathIsInvalidated('thesis/readiness', ['thesis'])).toBe(true);
  });

  it('leaves other screens alone', () => {
    expect(pathIsInvalidated('class', ['class/'])).toBe(false);
    expect(pathIsInvalidated('classes', ['class'])).toBe(false);
    expect(pathIsInvalidated('attendance/today', ['dashboard'])).toBe(false);
    expect(pathIsInvalidated('dashboard', [])).toBe(false);
  });
});

describe('operationFromProgress', () => {
  it('reads the step of the followed upload', () => {
    const payload = { operation_id: 'op-1', status: 'portal_uploading', percent: 60, message: 'Mengunggah…', detail: null, next_step: null, occurred_at: '2026-11-04T07:00:00Z' };

    expect(operationFromProgress(payload, 'op-1')).toEqual({
      operation_id: 'op-1',
      status: 'portal_uploading',
      percent: 60,
      message: 'Mengunggah…',
      detail: null,
      next_step: null,
      updated_at: '2026-11-04T07:00:00Z',
    });
  });

  it('ignores another upload, or an event without a status', () => {
    expect(operationFromProgress({ operation_id: 'op-2', status: 'completed' }, 'op-1')).toBeNull();
    expect(operationFromProgress({ operation_id: 'op-1' }, 'op-1')).toBeNull();
    expect(operationFromProgress(null, 'op-1')).toBeNull();
  });
});

describe('sessionNoticeAction', () => {
  it('ends the session when the server did', () => {
    expect(sessionNoticeAction('logged_out')).toBe('end');
    expect(sessionNoticeAction('manual_relogin_required')).toBe('end');
  });

  it('follows an offline session back online, or not', () => {
    expect(sessionNoticeAction('offline_session_resumed')).toBe('back_online');
    expect(sessionNoticeAction('offline_resume_unavailable')).toBe('stay_offline');
  });

  it('takes the rest as news', () => {
    expect(sessionNoticeAction('session_refreshed')).toBe('notice');
    expect(sessionNoticeAction('auto_relogin_succeeded')).toBe('notice');
    expect(sessionNoticeAction(undefined)).toBe('notice');
  });
});

describe('realtimeStatusLabel', () => {
  it('has words for every state', () => {
    expect(realtimeStatusLabel('connected')).toMatch(/^Aktif/);
    expect(realtimeStatusLabel('connecting')).toMatch(/^Menyambung/);
    expect(realtimeStatusLabel('unavailable')).toMatch(/^Tidak tersedia/);
    expect(realtimeStatusLabel('off')).toMatch(/^Belum aktif/);
  });
});
