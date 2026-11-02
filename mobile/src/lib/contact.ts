/** The digits (and a leading +) of a phone number; null when there are too few to dial. */
export function phoneDigits(value: string | null | undefined): string | null {
  const cleaned = value?.replace(/[^\d+]/g, '') ?? '';

  return cleaned.replace(/\D/g, '').length >= 6 ? cleaned : null;
}

/** An Indonesian number in the form wa.me wants: 0812… becomes 62812…; null when it is not a number. */
export function whatsappNumber(value: string | null | undefined): string | null {
  const digits = value?.replace(/\D/g, '') ?? '';

  if (digits.length < 6) {
    return null;
  }

  if (digits.startsWith('0')) {
    return `62${digits.slice(1)}`;
  }

  return digits;
}
