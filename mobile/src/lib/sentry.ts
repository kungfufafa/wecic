import * as Sentry from '@sentry/react-native';
import { isRunningInExpoGo } from 'expo';

/**
 * Error and performance reporting to Sentry. The project's DSN comes from
 * `EXPO_PUBLIC_SENTRY_DSN`, inlined at build time; without one, nothing is
 * sent. Release and dist are filled in by the SDK from the native build.
 */
export function sentryOptions(
  dsn: string | undefined = process.env.EXPO_PUBLIC_SENTRY_DSN,
  isDevelopment: boolean = __DEV__,
): Sentry.ReactNativeOptions | null {
  const trimmed = dsn?.trim() ?? '';

  if (trimmed === '') {
    return null;
  }

  const nativeBuild = !isRunningInExpoGo();

  return {
    dsn: trimmed,
    environment: isDevelopment ? 'development' : 'production',
    sendDefaultPii: false,
    tracesSampleRate: isDevelopment ? 1.0 : 0.2,
    enableNativeFramesTracking: nativeBuild,
    integrations: [Sentry.expoRouterIntegration({ enableTimeToInitialDisplay: nativeBuild })],
  };
}

/** Starts Sentry once, before the first screen renders. */
export function initSentry(): void {
  const options = sentryOptions();

  if (options !== null) {
    Sentry.init(options);
  }
}
