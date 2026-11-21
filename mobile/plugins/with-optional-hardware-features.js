const { withAndroidManifest } = require('expo/config-plugins');

/**
 * Declares hardware features the app can live without as optional.
 *
 * Android infers required features from the manifest: `ACCESS_WIFI_STATE`
 * (expo-network, netinfo) implies `android.hardware.wifi`, and the portrait
 * lock implies `android.hardware.screen.portrait`. Google Play then hides the
 * app from tablets, desktops and XR devices that lack them. An explicit
 * `<uses-feature android:required="false">` overrides the inferred one.
 */
const OPTIONAL_FEATURES = ['android.hardware.wifi', 'android.hardware.screen.portrait'];

module.exports = function withOptionalHardwareFeatures(config) {
  return withAndroidManifest(config, (config) => {
    const manifest = config.modResults.manifest;
    const features = (manifest['uses-feature'] ??= []);

    for (const name of OPTIONAL_FEATURES) {
      const existing = features.find((feature) => feature.$?.['android:name'] === name);

      if (existing) {
        existing.$['android:required'] = 'false';
      } else {
        features.push({ $: { 'android:name': name, 'android:required': 'false' } });
      }
    }

    return config;
  });
};
