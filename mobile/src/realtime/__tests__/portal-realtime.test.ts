import type { RealtimePayload, RealtimeStatus } from '@/lib/realtime';
import {
  connectRealtime,
  disconnectRealtime,
  getRealtimeStatus,
  onRealtime,
  resumeRealtime,
  type ChannelAuthorizer,
  type RealtimeConnection,
} from '@/realtime/portal-realtime';

// The Echo wiring needs the native socket; these tests drive a fake connection.
jest.mock('laravel-echo', () => ({ __esModule: true, default: class {} }));
jest.mock('pusher-js/react-native', () => ({ __esModule: true, default: class {} }));

const target = { channel: 'portal.session.abc', config: { key: 'k', host: 'h', port: 443, scheme: 'https' as const } };

/** A connection whose events and states the test fires itself. */
function fakeConnection() {
  const listeners = new Map<string, (payload: RealtimePayload) => void>();
  const statuses: ((status: RealtimeStatus) => void)[] = [];
  const calls = { reconnect: 0, disconnect: 0 };
  const connection: RealtimeConnection = {
    listen: (event, handler) => {
      listeners.set(event, handler);
    },
    onStatus: (handler) => {
      statuses.push(handler);
    },
    reconnect: () => {
      calls.reconnect += 1;
    },
    disconnect: () => {
      calls.disconnect += 1;
    },
  };

  return {
    connection,
    calls,
    fire: (event: string, payload: RealtimePayload) => listeners.get(event)?.(payload),
    report: (status: RealtimeStatus) => statuses.forEach((handler) => handler(status)),
    events: () => Array.from(listeners.keys()),
  };
}

const authorize: ChannelAuthorizer = async () => ({ auth: 'k:sig' });

afterEach(() => {
  disconnectRealtime();
});

describe('portal realtime', () => {
  it('listens to every event of the channel and hands each to its topic', () => {
    const fake = fakeConnection();
    const seen: RealtimePayload[] = [];
    onRealtime('dataInvalidated', (payload) => seen.push(payload));

    connectRealtime(target, authorize, () => fake.connection);
    fake.fire('.portal.data.invalidated', { cache_keys: ['/api/dashboard'] });
    fake.fire('.portal.notification', { title: 'x' });

    expect(fake.events().sort()).toEqual(['.portal.data.invalidated', '.portal.notification', '.portal.session.notice', '.portal.sync.status', '.portal.upload.progress']);
    expect(seen).toEqual([{ cache_keys: ['/api/dashboard'] }]);
  });

  it('is connected only once the channel is joined, and says when the server is unreachable', () => {
    const fake = fakeConnection();

    expect(getRealtimeStatus()).toBe('off');
    connectRealtime(target, authorize, () => fake.connection);
    expect(getRealtimeStatus()).toBe('connecting');

    fake.report('connected');
    expect(getRealtimeStatus()).toBe('connected');

    fake.report('unavailable');
    expect(getRealtimeStatus()).toBe('unavailable');

    disconnectRealtime();
    expect(getRealtimeStatus()).toBe('off');
    expect(fake.calls.disconnect).toBe(1);
  });

  it('fires "gap" once the socket is back after a drop, not on the first join', () => {
    const fake = fakeConnection();
    const gaps: RealtimePayload[] = [];
    onRealtime('gap', (payload) => gaps.push(payload));

    connectRealtime(target, authorize, () => fake.connection);
    fake.report('connected');
    expect(gaps).toHaveLength(0);

    fake.report('connecting');
    fake.report('connected');
    expect(gaps).toHaveLength(1);

    fake.report('unavailable');
    fake.report('connecting');
    fake.report('connected');
    expect(gaps).toHaveLength(2);
  });

  it('opens the socket again from the foreground only while it is not connected', () => {
    const fake = fakeConnection();

    connectRealtime(target, authorize, () => fake.connection);
    fake.report('connected');
    resumeRealtime();
    expect(fake.calls.reconnect).toBe(0);

    fake.report('unavailable');
    resumeRealtime();
    expect(fake.calls.reconnect).toBe(1);
  });

  it('replaces an earlier connection and ignores what it still reports', () => {
    const first = fakeConnection();
    const second = fakeConnection();
    const seen: string[] = [];
    onRealtime('sessionNotice', (payload) => seen.push(String(payload.status)));

    connectRealtime(target, authorize, () => first.connection);
    connectRealtime({ ...target, channel: 'portal.session.def' }, authorize, () => second.connection);
    first.fire('.portal.session.notice', { status: 'old' });
    first.report('connected');
    second.fire('.portal.session.notice', { status: 'new' });

    expect(first.calls.disconnect).toBe(1);
    expect(seen).toEqual(['new']);
    expect(getRealtimeStatus()).toBe('connecting');
  });

  it('stops calling a handler once unsubscribed, and a throwing handler stops no other', () => {
    const fake = fakeConnection();
    const seen: string[] = [];
    const off = onRealtime('notification', () => seen.push('first'));
    onRealtime('notification', () => {
      throw new Error('boom');
    });
    onRealtime('notification', () => seen.push('third'));

    connectRealtime(target, authorize, () => fake.connection);
    fake.fire('.portal.notification', {});
    off();
    fake.fire('.portal.notification', {});

    expect(seen).toEqual(['first', 'third', 'third']);
  });
});
