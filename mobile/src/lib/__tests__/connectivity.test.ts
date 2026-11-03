import { deviceOnlineFrom, isReachable, offlineReason, setDeviceOnline, setReachable, subscribeConnectivity } from '@/lib/connectivity';

describe('offlineReason', () => {
  it('blames the device first, then the server', () => {
    expect(offlineReason(false, true)).toBe('device');
    expect(offlineReason(false, false)).toBe('device');
    expect(offlineReason(true, false)).toBe('server');
    expect(offlineReason(null, false)).toBe('server');
    expect(offlineReason(true, true)).toBeNull();
    expect(offlineReason(null, true)).toBeNull();
  });
});

describe('deviceOnlineFrom', () => {
  it('is offline only when the system says so', () => {
    expect(deviceOnlineFrom({ isConnected: false, isInternetReachable: false })).toBe(false);
    expect(deviceOnlineFrom({ isConnected: true, isInternetReachable: false })).toBe(false);
    expect(deviceOnlineFrom({ isConnected: true, isInternetReachable: true })).toBe(true);
    expect(deviceOnlineFrom({ isConnected: true })).toBe(true);
    expect(deviceOnlineFrom({})).toBeNull();
    expect(deviceOnlineFrom(null)).toBeNull();
  });
});

describe('connectivity store', () => {
  it('counts the server reachable again when the device comes back online', () => {
    const changes: number[] = [];
    const unsubscribe = subscribeConnectivity(() => changes.push(1));

    setDeviceOnline(true);
    setReachable(false);
    setDeviceOnline(false);
    expect(isReachable()).toBe(false);

    setDeviceOnline(true);
    expect(isReachable()).toBe(true);
    expect(changes.length).toBe(4);

    unsubscribe();
  });

  it('does not notify when nothing changes', () => {
    setDeviceOnline(true);
    setReachable(true);
    const changes: number[] = [];
    const unsubscribe = subscribeConnectivity(() => changes.push(1));

    setDeviceOnline(true);
    setReachable(true);
    expect(changes).toHaveLength(0);

    unsubscribe();
  });
});
