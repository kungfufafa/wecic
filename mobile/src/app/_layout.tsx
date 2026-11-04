import * as Sentry from '@sentry/react-native';
import { DarkTheme, DefaultTheme, Stack, ThemeProvider, type Theme } from 'expo-router';
import * as SplashScreen from 'expo-splash-screen';
import { StatusBar } from 'expo-status-bar';
import { useEffect } from 'react';

import { SessionProvider, useSession } from '@/auth/session-provider';
import { NetworkWatcher } from '@/components/network-watcher';
import { Colors, type ColorSchemeName } from '@/constants/theme';
import { useResolvedColorScheme, useTheme } from '@/hooks/use-theme';
import { initSentry } from '@/lib/sentry';
import { configureNotificationPresentation } from '@/push/push-notifications';

initSentry();
SplashScreen.preventAutoHideAsync();
configureNotificationPresentation();

/** React Navigation's colors for each mode, from the app's palette. */
function navigationTheme(scheme: ColorSchemeName): Theme {
  const base = scheme === 'dark' ? DarkTheme : DefaultTheme;
  const palette = Colors[scheme];

  return {
    ...base,
    colors: {
      ...base.colors,
      primary: palette.text,
      background: palette.background,
      card: palette.section,
      text: palette.text,
      border: palette.hairline,
    },
  };
}

export default Sentry.wrap(RootLayout);

function RootLayout() {
  const scheme = useResolvedColorScheme();

  return (
    <ThemeProvider value={navigationTheme(scheme)}>
      <StatusBar style={scheme === 'dark' ? 'light' : 'dark'} />
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
  const theme = useTheme();

  return (
    <Stack screenOptions={{ headerShown: false, contentStyle: { backgroundColor: theme.background } }}>
      <Stack.Protected guard={signedIn}>
        <Stack.Screen name="(app)" />
      </Stack.Protected>
      <Stack.Protected guard={!signedIn}>
        <Stack.Screen name="sign-in" />
      </Stack.Protected>
    </Stack>
  );
}
