// Sentry's Metro config: Expo's defaults plus Debug IDs on bundles and source maps.
const { getSentryExpoConfig } = require('@sentry/react-native/metro');

module.exports = getSentryExpoConfig(__dirname);
