import { Stack } from 'expo-router';

import { useTheme } from '@/hooks/use-theme';

/** The tabs, with every other screen pushed over them. */
export default function AppStack() {
  const theme = useTheme();

  return <Stack screenOptions={{ headerShown: false, contentStyle: { backgroundColor: theme.background } }} />;
}
