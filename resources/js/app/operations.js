/**
 * Background operations (uploads the server forwards to the portal):
 * progress from realtime events or polling, the status panel, and the
 * operations picked up again after a reload.
 *
 * Methods of PortalApp (resources/js/app.js mixes them in): `this` is the
 * app, and every field they use is set in its constructor.
 */
export class OperationFeature {
    /**
     * Progress of a background operation, from a realtime event or a poll.
     * Both may deliver the same stage, so stale or repeated updates are
     * ignored and the outcome is announced once.
     */
    handleUploadProgress(payload) {
        const operationId = payload?.operation_id;

        if (!operationId || this.finishedOperations.has(operationId)) {
            return;
        }

        const current = this.activeOperations.get(operationId);
        const percent = payload.percent || this.percentForStage(payload.status);
        const isFinal = ['completed', 'failed', 'unverified'].includes(payload.status);

        if (!isFinal && current && percent < current.percent) {
            return;
        }

        this.activeOperations.set(operationId, {
            label: current?.label || 'Upload',
            status: payload.status,
            message: payload.message,
            detail: payload.detail,
            nextStep: payload.next_step,
            percent,
        });

        this.renderOperationStatus(operationId);

        if (payload.status === 'completed') {
            this.showNotice(payload.message || 'Upload selesai.', 'success');
            this.handleRealtimePayload(payload);
            this.finishOperation(operationId, 'completed');
        }

        if (payload.status === 'failed') {
            const failureMessage = payload.next_step
                ? `${payload.message} ${payload.next_step}`
                : payload.message;
            this.showNotice(failureMessage, 'error');
            this.finishOperation(operationId, 'failed');
        }

        // The portal neither confirmed nor refused the file: the student
        // checks the page (re-read below) before uploading again.
        if (payload.status === 'unverified') {
            const message = payload.message || 'Status upload belum pasti.';

            this.showNotice(payload.next_step ? `${message} ${payload.next_step}` : message, 'warning');
            this.handleRealtimePayload({ ...payload, urgency: payload.urgency || 'warning' });
            this.finishOperation(operationId, 'unknown');
        }
    }

    /**
     * Follow a background operation until it completes or fails. Resolves
     * with 'completed', 'failed' or 'unknown' (status could not be read, or
     * the portal left the upload unverified).
     */
    trackOperation(operationId, label, { startedAt = Date.now(), announce = true } = {}) {
        // A realtime event can report the outcome before the upload request
        // itself returns; the operation is then already settled.
        if (this.finishedOperations.has(operationId)) {
            return Promise.resolve(this.finishedOperations.get(operationId));
        }

        const existing = this.operationWaiters.get(operationId);

        if (existing) {
            return existing.promise;
        }

        if (announce) {
            this.showNotice(`${label} diterima. Mengirim ke portal...`, 'info');
        }

        if (!this.activeOperations.has(operationId)) {
            this.activeOperations.set(operationId, {
                label,
                status: 'connecting',
                message: `${label} diterima. Menyiapkan pengiriman...`,
                percent: 5,
            });
        }

        this.renderOperationStatus(operationId);

        let resolve;
        const promise = new Promise((resolver) => {
            resolve = resolver;
        });

        this.operationWaiters.set(operationId, { label, startedAt, resolve, promise, timer: null });
        this.persistActiveOperations();
        void this.pollOperation(operationId);

        return promise;
    }

    async pollOperation(operationId) {
        const waiter = this.operationWaiters.get(operationId);

        if (!waiter) {
            return;
        }

        if (Date.now() - waiter.startedAt > this.operationPollLimitMs) {
            this.showNotice(`Status ${waiter.label.toLowerCase()} belum diketahui. Muat ulang halaman untuk memeriksa.`, 'warning');
            this.finishOperation(operationId, 'unknown');

            return;
        }

        try {
            // A timer polls this, not the student: the upload itself counted.
            const payload = await this.request(`/api/operations/${encodeURIComponent(operationId)}`, {
                timeout: 10000,
                redirectOnAuth: false,
                background: true,
            });

            if (payload?.data) {
                this.handleUploadProgress({ ...payload.data, operation_id: operationId });
            }
        } catch (error) {
            const label = waiter.label.toLowerCase();

            // The campus session ended (often the upload itself hit the
            // portal's login page): the outcome can only be checked after
            // signing in again.
            if (error.status === 401) {
                this.finishOperation(operationId, 'unknown');
                this.sendToLogin(`Sesi kampus berakhir sebelum status ${label} terbaca. Masuk lagi lalu cek apakah file sudah terkirim.`, 'warning');

                return;
            }

            if (error.status === 404) {
                this.finishOperation(operationId, 'unknown');
                this.showNotice(`Status ${label} tidak ditemukan lagi. Cek halaman tugas atau ujian untuk memastikan file sudah terkirim.`, 'warning');

                return;
            }
            // Connection hiccups are retried on the next tick.
        }

        const next = this.operationWaiters.get(operationId);

        if (next) {
            next.timer = window.setTimeout(() => this.pollOperation(operationId), this.operationPollIntervalMs);
        }
    }

    finishOperation(operationId, outcome) {
        const waiter = this.operationWaiters.get(operationId);

        this.finishedOperations.set(operationId, outcome);

        if (waiter) {
            window.clearTimeout(waiter.timer);
            this.operationWaiters.delete(operationId);
            waiter.resolve(outcome);
        }

        this.persistActiveOperations();

        window.setTimeout(() => {
            this.activeOperations.delete(operationId);
            const host = document.querySelector('[data-operation-status]');

            if (host && this.activeOperations.size === 0) {
                host.innerHTML = '';
            }
        }, outcome === 'completed' ? 6000 : 12000);
    }

    persistActiveOperations() {
        const operations = {};

        this.operationWaiters.forEach((waiter, operationId) => {
            operations[operationId] = { label: waiter.label, startedAt: waiter.startedAt };
        });

        try {
            if (Object.keys(operations).length === 0) {
                sessionStorage.removeItem(this.operationStorageKey);
            } else {
                sessionStorage.setItem(this.operationStorageKey, JSON.stringify(operations));
            }
        } catch (error) {
            // Storage can be unavailable (private mode); tracking still works in memory.
        }
    }

    /**
     * Pick up operations started before a reload, so their outcome is not lost.
     */
    resumeActiveOperations() {
        let operations = {};

        try {
            operations = JSON.parse(sessionStorage.getItem(this.operationStorageKey) || '{}') || {};
        } catch (error) {
            operations = {};
        }

        Object.entries(operations).forEach(([operationId, operation]) => {
            void this.trackOperation(operationId, operation?.label || 'Upload', {
                startedAt: Number(operation?.startedAt) || Date.now(),
                announce: false,
            });
        });
    }

    percentForStage(stage) {
        return {
            connecting: 10,
            file_received: 20,
            portal_uploading: 55,
            validation_succeeded: 80,
            submission_saved: 100,
            completed: 100,
            failed: 100,
            unverified: 100,
        }[stage] || 10;
    }

    renderOperationStatus(operationId) {
        const operation = this.activeOperations.get(operationId);
        const host = document.querySelector('[data-operation-status]');

        if (!operation || !host) {
            return;
        }

        const tone = { failed: 'danger', unverified: 'warning' }[operation.status] || 'info';

        host.innerHTML = `
            <div class="${this.alertTone(tone)}">
                <div class="flex items-center justify-between gap-3">
                    <span>${this.escapeHtml(operation.message)}</span>
                    <span>${operation.percent}%</span>
                </div>
                <div class="mt-2 h-2 overflow-hidden rounded-full bg-surface-muted">
                    <div class="h-full rounded-full bg-brand" style="width: ${operation.percent}%"></div>
                </div>
                ${operation.detail ? `<p class="mt-2 text-caption text-ink-subtle">${this.escapeHtml(operation.detail)}</p>` : ''}
                ${operation.nextStep ? `<p class="mt-2 text-caption font-medium text-ink">${this.escapeHtml(operation.nextStep)}</p>` : ''}
            </div>
        `;
    }
}
