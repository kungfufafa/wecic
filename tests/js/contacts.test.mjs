import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { buildContactLinks, normalizeWhatsAppNumber } from '../../resources/js/lib/contacts.js';

describe('normalizeWhatsAppNumber', () => {
    it('turns every local way of writing a mobile number into 62…', () => {
        for (const value of ['0812-3456-7890', '+62 812 3456 7890', '812 3456 7890', '0062 812 3456 7890', '(0812) 3456.7890']) {
            assert.equal(normalizeWhatsAppNumber(value), '6281234567890', value);
        }
    });

    it('uses only the first of several numbers', () => {
        assert.equal(normalizeWhatsAppNumber('0812-1111-2222 / 0813-3333-4444'), '6281211112222');
        assert.equal(normalizeWhatsAppNumber('0812-1111-2222 atau 0813-3333-4444'), '6281211112222');
    });

    it('gives null when no plausible number is left', () => {
        for (const value of [null, '', '12345', 'hubungi BAAK', '0812345678901234567']) {
            assert.equal(normalizeWhatsAppNumber(value), null, String(value));
        }
    });
});

describe('buildContactLinks', () => {
    it('links a mobile number to WhatsApp and the phone, with a pre-filled chat', () => {
        const links = buildContactLinks({ phone: '0812-3456-7890', email: 'dosen@kampus.invalid', message: 'Halo Pak, saya mahasiswa & ingin bertanya' });

        assert.equal(links.whatsapp, 'https://wa.me/6281234567890?text=Halo%20Pak%2C%20saya%20mahasiswa%20%26%20ingin%20bertanya');
        assert.equal(links.tel, 'tel:+6281234567890');
        assert.equal(links.mailto, 'mailto:dosen@kampus.invalid');
        assert.equal(links.phoneLabel, '0812-3456-7890');
        assert.equal(links.phoneLocal, '081234567890');
    });

    it('does not offer WhatsApp for a landline unless a WhatsApp number is given', () => {
        assert.equal(buildContactLinks({ phone: '021 555 1234' }).whatsapp, null);
        assert.equal(buildContactLinks({ phone: '021 555 1234' }).tel, 'tel:+62215551234');
        assert.equal(buildContactLinks({ phone: '021 555 1234', whatsapp: '0813 1111 2222' }).whatsapp, 'https://wa.me/6281311112222');
    });

    it('drops an invalid email and an empty contact', () => {
        assert.equal(buildContactLinks({ email: 'dosen@' }).mailto, null);
        assert.deepEqual(buildContactLinks(), { whatsapp: null, tel: null, mailto: null, phoneLabel: null, phoneLocal: null, phoneDigits: null, email: null });
    });
});
