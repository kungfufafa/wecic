import * as Network from 'expo-network';
import { useEffect } from 'react';
import { Platform } from 'react-native';

import { deviceOnlineFrom, setDeviceOnline } from '@/lib/connectivity';

/**
 * Follows the device's Wi-Fi and mobile data for the whole app, so the
 * offline strip shows the moment the student has no internet, even while
 * screens still show kept data. Renders nothing.
 */
export function NetworkWatcher() {
  useEffect(() => {
    if (Platform.OS === 'web') {
      return;
    }

    let active = true;

    void Network.getNetworkStateAsync()
      .then((state) => {
        if (active) {
          setDeviceOnline(deviceOnlineFrom(state));
        }
      })
      .catch(() => {});

    const subscription = Network.addNetworkStateListener((state) => setDeviceOnline(deviceOnlineFrom(state)));

    return () => {
      active = false;
      subscription.remove();
    };
  }, []);

  return null;
}
