import * as Sentry from '@sentry/react-native';
import { isRunningInExpoGo } from 'expo';

/** The wecic-mobile project's DSN. Not a secret: every build of the app carries it. */
export const WECIC_SENTRY_DSN =
  'https://38e0b28a12c7c13c0a943d5c2c86ef55@o4510944541016064.ingest.de.sentry.io/4512372150239312';

/**
 * Error and performance reporting to Sentry, to the wecic-mobile project
 * unless `EXPO_PUBLIC_SENTRY_DSN` (inlined at build time) names another;
 * set it empty and nothing is sent. Release and dist are filled in by the
 * SDK from the native build.
 */
export function sentryOptions(
  dsn: string | undefined = process.env.EXPO_PUBLIC_SENTRY_DSN ?? WECIC_SENTRY_DSN,
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
