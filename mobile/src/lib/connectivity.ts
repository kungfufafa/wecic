/**
 * Whether the student is online, from two signals:
 *
 * - the device: Wi-Fi or mobile data, as the system reports it
 *   (components/network-watcher.tsx, through expo-network); null until
 *   known;
 * - WeCIC's server: set by the API client from every request (an answer
 *   means reachable, a failed connection means not). A request that only
 *   took too long says nothing about the connection.
 */

export type OfflineReason = 'device' | 'server' | null;

let reachable = true;
let deviceOnline: boolean | null = null;
const listeners = new Set<() => void>();

function notify(): void {
  listeners.forEach((listener) => listener());
}

export function setReachable(value: boolean): void {
  if (value === reachable) {
    return;
  }

  reachable = value;
  notify();
}

/**
 * What the system says about Wi-Fi and mobile data. Coming back online
 * counts the server as reachable again, so screens showing kept data ask
 * it once more; the next request corrects that if the server is down.
 */
export function setDeviceOnline(value: boolean | null): void {
  if (value === deviceOnline) {
    return;
  }

  const cameBack = value === true && deviceOnline === false;
  deviceOnline = value;

  if (cameBack) {
    reachable = true;
  }

  notify();
}

export function isReachable(): boolean {
  return reachable;
}

export function isDeviceOnline(): boolean | null {
  return deviceOnline;
}

/** Why the student is offline: no Wi-Fi or data first, then an unreachable server; null when online. */
export function offlineReason(device: boolean | null, server: boolean): OfflineReason {
  if (device === false) {
    return 'device';
  }

  return server ? null : 'server';
}

/** The device's state from expo-network's: offline only when it says so, unknown counts as online. */
export function deviceOnlineFrom(state: { isConnected?: boolean; isInternetReachable?: boolean } | null | undefined): boolean | null {
  if (!state || (state.isConnected === undefined && state.isInternetReachable === undefined)) {
    return null;
  }

  return state.isConnected !== false && state.isInternetReachable !== false;
}

export function subscribeConnectivity(listener: () => void): () => void {
  listeners.add(listener);

  return () => {
    listeners.delete(listener);
  };
}
