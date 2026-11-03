import { useSyncExternalStore } from 'react';

import { isDeviceOnline, isReachable, offlineReason, subscribeConnectivity, type OfflineReason } from '@/lib/connectivity';

/** False while WeCIC cannot be reached: no Wi-Fi or data, or the server does not answer. */
export function useReachable(): boolean {
  return useOfflineReason() === null;
}

/** Why the student is offline right now, or null. */
export function useOfflineReason(): OfflineReason {
  const server = useSyncExternalStore(subscribeConnectivity, isReachable);
  const device = useSyncExternalStore(subscribeConnectivity, isDeviceOnline);

  return offlineReason(device, server);
}
