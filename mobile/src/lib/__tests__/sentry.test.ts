import * as Sentry from '@sentry/react-native';

import { initSentry, sentryOptions } from '@/lib/sentry';

jest.mock('@sentry/react-native', () => ({
  init: jest.fn(),
  expoRouterIntegration: jest.fn(() => ({ name: 'ExpoRouter' })),
}));

const DSN = 'https://public@o1.ingest.sentry.io/2';

describe('sentryOptions', () => {
  it('is off without a DSN', () => {
    expect(sentryOptions(undefined)).toBeNull();
    expect(sentryOptions('')).toBeNull();
    expect(sentryOptions('   ')).toBeNull();
  });

  it('trims the DSN', () => {
    expect(sentryOptions(`  ${DSN}\n`)?.dsn).toBe(DSN);
  });

  it('samples every trace in development', () => {
    const options = sentryOptions(DSN, true);

    expect(options?.environment).toBe('development');
    expect(options?.tracesSampleRate).toBe(1.0);
  });

  it('samples a fifth of traces in production', () => {
    const options = sentryOptions(DSN, false);

    expect(options?.environment).toBe('production');
    expect(options?.tracesSampleRate).toBe(0.2);
  });

  it('sends no personal data by default and traces Expo Router navigation', () => {
    const options = sentryOptions(DSN, false);

    expect(options?.sendDefaultPii).toBe(false);
    expect(options?.integrations).toEqual([{ name: 'ExpoRouter' }]);
  });
});

describe('initSentry', () => {
  const original = process.env.EXPO_PUBLIC_SENTRY_DSN;

  afterEach(() => {
    process.env.EXPO_PUBLIC_SENTRY_DSN = original;
    jest.mocked(Sentry.init).mockClear();
  });

  it('does not start Sentry without a DSN', () => {
    delete process.env.EXPO_PUBLIC_SENTRY_DSN;

    initSentry();

    expect(Sentry.init).not.toHaveBeenCalled();
  });

  it('starts Sentry with the configured DSN', () => {
    process.env.EXPO_PUBLIC_SENTRY_DSN = DSN;

    initSentry();

    expect(Sentry.init).toHaveBeenCalledWith(expect.objectContaining({ dsn: DSN }));
  });
});
