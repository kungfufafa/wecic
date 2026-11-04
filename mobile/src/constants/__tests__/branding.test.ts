/// <reference types="node" />

import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import appConfig from '../../../app.json';

function pngInfo(assetPath: string) {
  const image = readFileSync(resolve(__dirname, '../../..', assetPath));

  expect(image.subarray(0, 8).toString('hex')).toBe('89504e470d0a1a0a');
  expect(image.subarray(12, 16).toString()).toBe('IHDR');

  return {
    width: image.readUInt32BE(16),
    height: image.readUInt32BE(20),
    colorType: image.readUInt8(25),
  };
}

describe('WeCIC branding assets', () => {
  const config = appConfig.expo;

  it('uses the same approved launcher on iOS instead of the Expo Composer placeholder', () => {
    expect(config.ios.icon).toBe(config.icon);
    expect(pngInfo(config.ios.icon)).toEqual({ width: 1024, height: 1024, colorType: 2 });
  });

  it('provides matching Android adaptive layers with a solid brand background', () => {
    const adaptive = config.android.adaptiveIcon;

    expect(adaptive.backgroundColor).toBe('#111111');
    expect(pngInfo(adaptive.foregroundImage)).toEqual({ width: 1024, height: 1024, colorType: 6 });
    expect(pngInfo(adaptive.monochromeImage)).toEqual({ width: 1024, height: 1024, colorType: 6 });
    expect(pngInfo(adaptive.backgroundImage)).toEqual({ width: 1024, height: 1024, colorType: 2 });
  });

  it('has a valid favicon and a transparent splash on the existing dark canvas', () => {
    const splash = config.plugins.find((plugin) => Array.isArray(plugin) && plugin[0] === 'expo-splash-screen');

    expect(splash).toEqual([
      'expo-splash-screen',
      { backgroundColor: '#050505', image: './assets/images/splash-icon.png', imageWidth: 76 },
    ]);
    expect(pngInfo('./assets/images/splash-icon.png')).toEqual({ width: 1024, height: 1024, colorType: 6 });
    expect(pngInfo(config.web.favicon)).toEqual({ width: 48, height: 48, colorType: 6 });
    expect(pngInfo('./assets/images/wecic-logo-white.png')).toEqual({ width: 880, height: 256, colorType: 6 });
  });
});
