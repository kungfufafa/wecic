import { DarkTheme, Stack, ThemeProvider, type Theme } from 'expo-router';
import * as SplashScreen from 'expo-splash-screen';
import { StatusBar } from 'expo-status-bar';
import { useEffect } from 'react';
import { Appearance, Platform } from 'react-native';

import { SessionProvider, useSession } from '@/auth/session-provider';
import { NetworkWatcher } from '@/components/network-watcher';
import { Colors } from '@/constants/theme';
import { configureNotificationPresentation } from '@/push/push-notifications';

SplashScreen.preventAutoHideAsync();
configureNotificationPresentation();

// MyCIC is dark only; native controls (switches, alerts, the tab bar) follow.
if (Platform.OS !== 'web') {
  Appearance.setColorScheme('dark');
}

const navigationTheme: Theme = {
  ...DarkTheme,
  colors: {
    ...DarkTheme.colors,
    primary: Colors.dark.text,
    background: Colors.dark.background,
    card: Colors.dark.section,
    text: Colors.dark.text,
    border: Colors.dark.hairline,
  },
};

export default function RootLayout() {
  return (
    <ThemeProvider value={navigationTheme}>
      <StatusBar style="light" />
      <NetworkWatcher />
      <SessionProvider>
        <SplashScreenController />
        <RootNavigator />
      </SessionProvider>
    </ThemeProvider>
  );
}

/** The splash stays until the stored session is read. */
function SplashScreenController() {
  const { state } = useSession();

  useEffect(() => {
    if (state.status !== 'loading') {
      SplashScreen.hide();
    }
  }, [state.status]);

  return null;
}

/** Signed in: the tabs. Otherwise only the login screen can be reached. */
function RootNavigator() {
  const { state } = useSession();
  const signedIn = state.status === 'signedIn';

  return (
    <Stack screenOptions={{ headerShown: false, contentStyle: { backgroundColor: Colors.dark.background } }}>
      <Stack.Protected guard={signedIn}>
        <Stack.Screen name="(app)" />
      </Stack.Protected>
      <Stack.Protected guard={!signedIn}>
        <Stack.Screen name="sign-in" />
      </Stack.Protected>
    </Stack>
  );
}
