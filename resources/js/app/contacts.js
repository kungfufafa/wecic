import * as contactRules from '../lib/contacts.js';

/**
 * Contacting a lecturer or office: the contact sheet with WhatsApp, phone
 * and email actions (links in lib/contacts.js), and copying text.
 *
 * Methods of PortalApp (resources/js/app.js mixes them in): `this` is the
 * app, and every field they use is set in its constructor.
 */
export class ContactFeature {
    /**
     * Digits for wa.me: "0812-3456-7890", "+62 812…" and "812…" all become
     * "62812…". Only the first number of "0812… / 0813…" is used. Null when
     * no plausible number is left.
     */
    normalizeWhatsAppNumber(value) {
        return contactRules.normalizeWhatsAppNumber(value);
    }

    /**
     * Links for a contact: `{ whatsapp, tel, mailto, phoneLabel, email }`.
     * WhatsApp uses `contact.whatsapp`, else `contact.phone` when it is a
     * mobile number (62 8…); `contact.message` pre-fills the chat.
     */
    buildContactLinks(contact) {
        return contactRules.buildContactLinks(contact);
    }

    /**
     * The action list of a contact sheet (WhatsApp, Telepon, Email, Salin),
     * usable inline in a card too.
     */
    renderContactActions(contact = {}) {
        const links = this.buildContactLinks(contact);
        const icons = {
            whatsapp: '<svg xmlns="http://www.w3.org/2000/svg" class="h-[18px] w-[18px]" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 21l1.65 -3.8a9 9 0 1 1 3.4 2.9l-5.05 .9" /><path d="M9 10a.5 .5 0 0 0 1 0v-1a.5 .5 0 0 0 -1 0v1a5 5 0 0 0 5 5h1a.5 .5 0 0 0 0 -1h-1a.5 .5 0 0 0 0 1" /></svg>',
            tel: '<svg xmlns="http://www.w3.org/2000/svg" class="h-[18px] w-[18px]" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 4h4l2 5l-2.5 1.5a11 11 0 0 0 5 5l1.5 -2.5l5 2v4a2 2 0 0 1 -2 2a16 16 0 0 1 -15 -15a2 2 0 0 1 2 -2" /></svg>',
            mail: '<svg xmlns="http://www.w3.org/2000/svg" class="h-[18px] w-[18px]" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7a2 2 0 0 1 2 -2h14a2 2 0 0 1 2 2v10a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2v-10z" /><path d="M3 7l9 6l9 -6" /></svg>',
            copy: '<svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 7m0 2.667a2.667 2.667 0 0 1 2.667 -2.667h8.666a2.667 2.667 0 0 1 2.667 2.667v8.666a2.667 2.667 0 0 1 -2.667 2.667h-8.666a2.667 2.667 0 0 1 -2.667 -2.667z" /><path d="M4.012 16.737a2.005 2.005 0 0 1 -1.012 -1.737v-10c0 -1.1 .9 -2 2 -2h10c.75 0 1.158 .385 1.5 1" /></svg>',
        };
        const row = (href, icon, title, detail, external = false) => `
            <a href="${this.escapeHtml(href)}" class="mycic-sheet-action"${external ? ' target="_blank" rel="noopener noreferrer"' : ''}>
                <span class="mycic-sheet-action-icon">${icon}</span>
                <span class="min-w-0 flex-1">
                    <span class="block text-sm font-medium leading-snug text-ink">${this.escapeHtml(title)}</span>
                    ${detail ? `<span class="block truncate text-caption text-ink-subtle">${this.escapeHtml(detail)}</span>` : ''}
                </span>
            </a>
        `;
        const rows = [
            links.whatsapp ? row(links.whatsapp, icons.whatsapp, 'WhatsApp', contact.message ? 'Pesan sudah disiapkan, tinggal kirim' : links.phoneLabel, true) : '',
            links.tel ? row(links.tel, icons.tel, 'Telepon', links.phoneLabel) : '',
            links.mailto ? row(links.mailto, icons.mail, 'Email', links.email) : '',
        ].filter(Boolean);

        if (!rows.length) {
            return this.renderEmptyState('Kontak belum tersedia di portal.', 'secondary', 'Tanyakan lewat BAAK atau buka Portal kampus.');
        }

        const copies = [
            links.phoneDigits ? `<button type="button" class="${this.buttonClass('secondary', { small: true })}" data-copy-text="${this.escapeHtml(links.phoneLocal)}" data-copy-label="Nomor">${icons.copy}Salin nomor</button>` : '',
            links.email ? `<button type="button" class="${this.buttonClass('secondary', { small: true })}" data-copy-text="${this.escapeHtml(links.email)}" data-copy-label="Email">${icons.copy}Salin email</button>` : '',
        ].filter(Boolean).join('');

        return `
            <div class="flex flex-col gap-2">
                ${rows.join('')}
                ${copies ? `<div class="flex flex-wrap gap-2 pt-1">${copies}</div>` : ''}
            </div>
        `;
    }

    /**
     * Open the contact bottom sheet (K6) for a lecturer or DPA:
     * `openContactSheet({ name, role, email, phone, whatsapp, message })`.
     */
    openContactSheet(contact = {}) {
        return this.openSheet({
            pretitle: contact.role || 'Kontak',
            title: contact.name || 'Kontak',
            body: this.renderContactActions(contact),
        });
    }

    /**
     * Same as openContactSheet (the K6 name): opens the sheet and returns its panel.
     */
    renderContactSheet(contact = {}) {
        return this.openContactSheet(contact);
    }

    /**
     * A button that opens the contact sheet, safe to put in any rendered HTML.
     */
    renderContactButton(contact = {}, { label = 'Hubungi', variant = 'secondary', small = true, className = '' } = {}) {
        return `
            <button type="button" class="${this.buttonClass(variant, { small })}${className ? ` ${this.escapeHtml(className)}` : ''}" data-contact-sheet="${this.escapeHtml(JSON.stringify(contact || {}))}">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 21l1.65 -3.8a9 9 0 1 1 3.4 2.9l-5.05 .9" /></svg>
                ${this.escapeHtml(label)}
            </button>
        `;
    }

    bindContactSheetTriggers() {
        document.addEventListener('click', (event) => {
            const target = event.target instanceof Element ? event.target : null;
            const contactTrigger = target?.closest('[data-contact-sheet]');

            if (contactTrigger) {
                event.preventDefault();

                let contact = {};

                try {
                    contact = JSON.parse(contactTrigger.getAttribute('data-contact-sheet') || '{}') || {};
                } catch (error) {
                    contact = {};
                }

                this.openContactSheet(contact);

                return;
            }

            const copyTrigger = target?.closest('[data-copy-text]');

            if (copyTrigger) {
                event.preventDefault();
                void this.copyText(copyTrigger.getAttribute('data-copy-text'), copyTrigger.getAttribute('data-copy-label') || 'Teks');
            }
        });
    }

    /**
     * Copy text and say so in a toast ("Nomor disalin."). Resolves to true on success.
     */
    async copyText(text, label = 'Teks') {
        const value = String(text ?? '');

        if (value === '') {
            return false;
        }

        try {
            if (navigator.clipboard?.writeText && window.isSecureContext) {
                await navigator.clipboard.writeText(value);
            } else {
                const field = document.createElement('textarea');

                field.value = value;
                field.setAttribute('readonly', '');
                field.className = 'sr-only';
                document.body.appendChild(field);
                field.select();

                const copied = document.execCommand('copy');

                field.remove();

                if (!copied) {
                    throw new Error('Copy failed');
                }
            }

            this.showNotice(`${label} disalin.`, 'success');

            return true;
        } catch (error) {
            this.showNotice(`${label} gagal disalin. Salin manual: ${value}`, 'warning');

            return false;
        }
    }
}
