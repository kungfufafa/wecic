/**
 * Whether MyCIC answers from this device: set by the API client from every
 * request (an answer means reachable, a failed connection means not), so a
 * screen can say it shows kept data. A request that only took too long
 * says nothing about the connection.
 */

let reachable = true;
let lastChange = 0;
const listeners = new Set<() => void>();

export function setReachable(value: boolean): void {
  if (value === reachable) {
    return;
  }

  reachable = value;
  lastChange = Date.now();
  listeners.forEach((listener) => listener());
}

export function isReachable(): boolean {
  return reachable;
}

/** When the connection last changed, for a "since" line; 0 before any change. */
export function lastConnectivityChange(): number {
  return lastChange;
}

export function subscribeConnectivity(listener: () => void): () => void {
  listeners.add(listener);

  return () => {
    listeners.delete(listener);
  };
}
