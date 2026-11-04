import * as Sentry from '@sentry/react-native';

import { initSentry, sentryOptions, WECIC_SENTRY_DSN } from '@/lib/sentry';

jest.mock('@sentry/react-native', () => ({
  init: jest.fn(),
  expoRouterIntegration: jest.fn(() => ({ name: 'ExpoRouter' })),
}));

const DSN = 'https://public@o1.ingest.sentry.io/2';

describe('sentryOptions', () => {
  it('is off with an empty DSN', () => {
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

  it('reports to the wecic-mobile project when no DSN is configured', () => {
    delete process.env.EXPO_PUBLIC_SENTRY_DSN;

    initSentry();

    expect(WECIC_SENTRY_DSN).toMatch(/^https:\/\/[0-9a-f]+@o\d+\.ingest\.de\.sentry\.io\/\d+$/);
    expect(Sentry.init).toHaveBeenCalledWith(expect.objectContaining({ dsn: WECIC_SENTRY_DSN }));
  });

  it('does not start Sentry when the DSN is set empty', () => {
    process.env.EXPO_PUBLIC_SENTRY_DSN = '';

    initSentry();

    expect(Sentry.init).not.toHaveBeenCalled();
  });

  it('starts Sentry with a configured DSN instead', () => {
    process.env.EXPO_PUBLIC_SENTRY_DSN = DSN;

    initSentry();

    expect(Sentry.init).toHaveBeenCalledWith(expect.objectContaining({ dsn: DSN }));
  });
});
