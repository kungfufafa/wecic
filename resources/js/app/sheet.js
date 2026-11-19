import { ui } from './ui.js';

/**
 * The bottom sheet (a dialog from md up): one host for the whole page,
 * focus kept inside, Escape and the backdrop close it.
 *
 * Methods of PortalApp (resources/js/app.js mixes them in): `this` is the
 * app, and every field they use is set in its constructor.
 */
export class SheetFeature {
    ensureSheetHost() {
        if (this.sheetState.host?.isConnected) {
            return this.sheetState.host;
        }

        const host = document.createElement('div');

        host.className = `${ui.sheet} hidden`;
        host.setAttribute('data-app-sheet', '');
        host.setAttribute('aria-hidden', 'true');
        host.innerHTML = `
            <div class="${ui.sheetBackdrop}" data-app-sheet-close></div>
            <div class="${ui.sheetPanel}" role="dialog" aria-modal="true" aria-labelledby="app-sheet-title" tabindex="-1" data-app-sheet-panel>
                <div class="${ui.sheetHandle}" aria-hidden="true"></div>
                <div class="${ui.modalHeader}">
                    <div class="min-w-0">
                        <div class="text-xs font-medium text-ink-subtle" data-app-sheet-pretitle></div>
                        <h2 class="${ui.modalTitle}" id="app-sheet-title" data-app-sheet-title></h2>
                    </div>
                    <button type="button" class="${ui.modalClose}" aria-label="Tutup" data-app-sheet-close>
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-[18px] w-[18px]" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6l-12 12" /><path d="M6 6l12 12" /></svg>
                    </button>
                </div>
                <div class="${ui.modalBody}" data-app-sheet-body></div>
            </div>
        `;

        host.addEventListener('click', (event) => {
            if (event.target instanceof Element && event.target.closest('[data-app-sheet-close]')) {
                this.closeSheet();
            }
        });

        host.addEventListener('keydown', (event) => this.handleSheetKeydown(event));
        document.body.appendChild(host);
        this.sheetState.host = host;

        return host;
    }

    /**
     * Generic bottom sheet (dialog on md+): `openSheet({ title, pretitle, body, onClose })`.
     * `body` is an HTML string or a Node. Focus is trapped inside, Escape and
     * the backdrop close it, and focus returns to where it was. Returns the panel.
     */
    openSheet({ title = '', pretitle = '', body = '', onClose = null } = {}) {
        const host = this.ensureSheetHost();
        const panel = host.querySelector('[data-app-sheet-panel]');
        const pretitleNode = host.querySelector('[data-app-sheet-pretitle]');
        const bodyHost = host.querySelector('[data-app-sheet-body]');
        const alreadyOpen = !host.classList.contains('hidden');

        if (alreadyOpen) {
            this.sheetState.onClose?.();
        } else {
            this.sheetState.returnFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        }

        host.querySelector('[data-app-sheet-title]').textContent = title;
        pretitleNode.textContent = pretitle;
        pretitleNode.classList.toggle('hidden', !pretitle);

        if (body instanceof Node) {
            bodyHost.replaceChildren(body);
        } else {
            bodyHost.innerHTML = body;
        }

        this.sheetState.onClose = typeof onClose === 'function' ? onClose : null;
        host.classList.remove('hidden');
        host.setAttribute('aria-hidden', 'false');
        document.documentElement.classList.add('overflow-hidden');
        panel.focus();

        return panel;
    }

    closeSheet({ restoreFocus = true } = {}) {
        const host = this.sheetState.host;

        if (!host || host.classList.contains('hidden')) {
            return;
        }

        host.classList.add('hidden');
        host.setAttribute('aria-hidden', 'true');
        document.documentElement.classList.remove('overflow-hidden');
        host.querySelector('[data-app-sheet-body]').innerHTML = '';

        const onClose = this.sheetState.onClose;
        const returnFocus = this.sheetState.returnFocus;

        this.sheetState.onClose = null;
        this.sheetState.returnFocus = null;

        if (restoreFocus && returnFocus?.isConnected) {
            returnFocus.focus();
        }

        onClose?.();
    }

    focusableElements(root) {
        if (!root) {
            return [];
        }

        return Array.from(root.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'))
            .filter((element) => element.getClientRects().length > 0);
    }

    handleSheetKeydown(event) {
        if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            this.closeSheet();

            return;
        }

        if (event.key !== 'Tab') {
            return;
        }

        this.trapTabFocus(event, this.sheetState.host?.querySelector('[data-app-sheet-panel]'));
    }

    /**
     * Keep Tab and Shift+Tab inside an open dialog panel.
     */
    trapTabFocus(event, panel) {
        const focusable = this.focusableElements(panel);

        if (!focusable.length) {
            event.preventDefault();
            panel?.focus();

            return;
        }

        const first = focusable[0];
        const last = focusable[focusable.length - 1];

        if (event.shiftKey && (document.activeElement === first || document.activeElement === panel)) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }
}
