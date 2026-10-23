/**
 * WhatsApp, phone and email links for a lecturer or office contact.
 * Pure, so tests/js runs them under Node.
 */

/**
 * Digits for wa.me: "0812-3456-7890", "+62 812…" and "812…" all become
 * "62812…". Only the first number of "0812… / 0813…" is used. Null when
 * no plausible number is left.
 *
 * @param {unknown} value
 * @returns {string|null}
 */
export function normalizeWhatsAppNumber(value) {
    const first = String(value ?? '').split(/[/,;|]|\batau\b/i)[0] || '';
    let digits = first.replace(/\D/g, '');

    if (digits.startsWith('00')) {
        digits = digits.slice(2);
    }

    if (digits.startsWith('0')) {
        digits = `62${digits.slice(1)}`;
    } else if (digits.startsWith('8')) {
        digits = `62${digits}`;
    }

    return /^\d{9,15}$/.test(digits) ? digits : null;
}

/**
 * Links for a contact: `{ whatsapp, tel, mailto, phoneLabel, email }`.
 * WhatsApp uses `contact.whatsapp`, else `contact.phone` when it is a
 * mobile number (62 8…); `contact.message` pre-fills the chat.
 *
 * @param {{ phone?: unknown, whatsapp?: unknown, email?: unknown, message?: unknown }} contact
 * @returns {{ whatsapp: string|null, tel: string|null, mailto: string|null, phoneLabel: string|null, phoneLocal: string|null, phoneDigits: string|null, email: string|null }}
 */
export function buildContactLinks(contact = {}) {
    const phoneDigits = normalizeWhatsAppNumber(contact.phone) || normalizeWhatsAppNumber(contact.whatsapp);
    const explicitWhatsApp = normalizeWhatsAppNumber(contact.whatsapp);
    const whatsappDigits = explicitWhatsApp || (phoneDigits?.startsWith('628') ? phoneDigits : null);
    const email = String(contact.email ?? '').trim();
    const validEmail = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email) ? email : null;
    const message = String(contact.message ?? '').trim();

    return {
        whatsapp: whatsappDigits ? `https://wa.me/${whatsappDigits}${message ? `?text=${encodeURIComponent(message)}` : ''}` : null,
        tel: phoneDigits ? `tel:+${phoneDigits}` : null,
        mailto: validEmail ? `mailto:${validEmail}` : null,
        phoneLabel: phoneDigits ? String(contact.phone || contact.whatsapp || '').trim() || `+${phoneDigits}` : null,
        phoneLocal: phoneDigits ? (phoneDigits.startsWith('62') ? `0${phoneDigits.slice(2)}` : phoneDigits) : null,
        phoneDigits,
        email: validEmail,
    };
}
