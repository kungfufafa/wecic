import Constants from 'expo-constants';
import * as Device from 'expo-device';
import * as Notifications from 'expo-notifications';
import { AndroidImportance } from 'expo-notifications';
import { Platform } from 'react-native';

import type { ApiClient } from '@/api/client';

export type PushRegistration =
  | { status: 'registered'; token: string }
  | { status: 'denied' }
  | { status: 'unavailable'; reason: string };

/** The channel MyCIC's pushes arrive on (Android). */
const ANDROID_CHANNEL_ID = 'default';

/** Pushes that arrive while the app is open are shown too. */
export function configureNotificationPresentation(): void {
  Notifications.setNotificationHandler({
    handleNotification: async () => ({
      shouldShowBanner: true,
      shouldShowList: true,
      shouldPlaySound: true,
      shouldSetBadge: true,
    }),
  });
}

/**
 * Asks for permission, gets this install's Expo push token and registers
 * it with MyCIC (`POST /devices/push-token`), which then pushes every new
 * notification to it. Needs a physical device and a build with an EAS
 * project id (`npx eas-cli@latest init`); remote push does not work in Expo
 * Go on Android.
 */
export async function registerForPushNotifications(api: ApiClient): Promise<PushRegistration> {
  if (Platform.OS === 'web') {
    return { status: 'unavailable', reason: 'Push tidak tersedia di web.' };
  }

  if (Platform.OS === 'android') {
    // Android 13+ asks for permission only once a channel exists.
    await Notifications.setNotificationChannelAsync(ANDROID_CHANNEL_ID, {
      name: 'Notifikasi MyCIC',
      importance: AndroidImportance.HIGH,
      lightColor: '#0352A1',
    });
  }

  if (!Device.isDevice) {
    return { status: 'unavailable', reason: 'Push hanya bisa di perangkat fisik, bukan simulator.' };
  }

  const projectId = Constants.expoConfig?.extra?.eas?.projectId ?? Constants.easConfig?.projectId;

  if (typeof projectId !== 'string' || projectId === '') {
    return { status: 'unavailable', reason: 'Proyek EAS belum diatur (npx eas-cli@latest init).' };
  }

  let { status } = await Notifications.getPermissionsAsync();

  if (status !== 'granted') {
    ({ status } = await Notifications.requestPermissionsAsync());
  }

  if (status !== 'granted') {
    return { status: 'denied' };
  }

  try {
    const { data: token } = await Notifications.getExpoPushTokenAsync({ projectId });
    await api.post('devices/push-token', { token, platform: Platform.OS });

    return { status: 'registered', token };
  } catch (error) {
    return {
      status: 'unavailable',
      reason: error instanceof Error ? error.message : 'Token push tidak bisa didaftarkan.',
    };
  }
}
