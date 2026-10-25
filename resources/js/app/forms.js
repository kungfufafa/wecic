/**
 * Form helpers: submitting a form the way a click would, a button that
 * shows it is busy, and running a submit handler once at a time.
 *
 * Methods of PortalApp (resources/js/app.js mixes them in): `this` is the
 * app, and every field they use is set in its constructor.
 */
export class FormFeature {
    requestFormSubmit(form) {
        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();

            return;
        }

        form.dispatchEvent(new Event('submit', {
            bubbles: true,
            cancelable: true,
        }));
    }

    async withSubmitLoading(button, callback) {
        if (!button) {
            return await callback();
        }

        const originalHtml = button.innerHTML;
        const originalWidth = button.offsetWidth;
        
        button.disabled = true;
        if (originalWidth > 0) {
            button.style.minWidth = `${originalWidth}px`;
        }
        button.innerHTML = '<span class="mycic-spinner me-2" role="status" aria-hidden="true"></span>Memproses...';
        
        try {
            await callback();
        } finally {
            button.disabled = false;
            button.innerHTML = originalHtml;
            button.style.minWidth = '';
        }
    }

    /**
     * Run a form's submit handler once at a time. The guard is always
     * released, so a failed attempt (wrong code, timeout) never locks the form.
     */
    async guardFormSubmit(form, callback) {
        if (!form || form.dataset.submitting === 'true') {
            return undefined;
        }

        form.dataset.submitting = 'true';

        try {
            return await callback();
        } finally {
            delete form.dataset.submitting;
        }
    }
}
