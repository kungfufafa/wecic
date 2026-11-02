import { phoneDigits, whatsappNumber } from '@/lib/contact';

describe('contact numbers', () => {
  it('keeps the dialable digits of a phone number', () => {
    expect(phoneDigits('0812-0000-0000')).toBe('081200000000');
    expect(phoneDigits('+62 812 0000 0000')).toBe('+6281200000000');
    expect(phoneDigits('123')).toBeNull();
    expect(phoneDigits(null)).toBeNull();
  });

  it('turns a local number into the international form for WhatsApp', () => {
    expect(whatsappNumber('081200000000')).toBe('6281200000000');
    expect(whatsappNumber('6281200000000')).toBe('6281200000000');
    expect(whatsappNumber('+62 812-0000-0000')).toBe('6281200000000');
    expect(whatsappNumber('')).toBeNull();
    expect(whatsappNumber(undefined)).toBeNull();
  });
});
