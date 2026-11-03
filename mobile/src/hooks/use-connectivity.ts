import { useSyncExternalStore } from 'react';

import { isReachable, subscribeConnectivity } from '@/lib/connectivity';

/** False while MyCIC cannot be reached from this device. */
export function useReachable(): boolean {
  return useSyncExternalStore(subscribeConnectivity, isReachable);
}
