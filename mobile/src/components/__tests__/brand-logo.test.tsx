import { BrandLogo } from '@/components/brand-logo';

describe('BrandLogo', () => {
  it('gives the outlined image a readable brand name and preserves its proportions', () => {
    const logo = BrandLogo({ width: 176 });

    expect(logo.props.accessible).toBe(true);
    expect(logo.props.accessibilityLabel).toBe('WeCIC');
    expect(logo.props.contentFit).toBe('contain');
    expect(logo.props.style).toEqual({ width: 176, height: 51.2 });
    expect(logo.props.source).toBeDefined();
  });

  it('scales the footer logo without cropping the symbol or wordmark', () => {
    expect(BrandLogo({ width: 132 }).props.style).toEqual({ width: 132, height: 38.4 });
    expect(BrandLogo({}).props.style.width).toBe(176);
  });
});
