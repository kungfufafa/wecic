import { Image } from 'expo-image';

/** The white WeCIC logo, tinted to `color` (the text color) when the canvas is light. */
export function BrandLogo({ width = 176, color }: { width?: number; color?: string }) {
  return (
    <Image
      source={require('@/assets/images/wecic-logo-white.png')}
      accessible
      accessibilityLabel="WeCIC"
      contentFit="contain"
      tintColor={color}
      style={{ width, height: (width * 256) / 880 }}
    />
  );
}
