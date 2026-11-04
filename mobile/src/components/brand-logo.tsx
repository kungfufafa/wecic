import { Image } from 'expo-image';

export function BrandLogo({ width = 176 }: { width?: number }) {
  return (
    <Image
      source={require('@/assets/images/wecic-logo-white.png')}
      accessible
      accessibilityLabel="WeCIC"
      contentFit="contain"
      style={{ width, height: (width * 256) / 880 }}
    />
  );
}
