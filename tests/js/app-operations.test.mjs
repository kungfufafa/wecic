import { afterEach, beforeEach, describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { createPortalApp } from './support/browser.mjs';

/**
 * Background operations (resources/js/app/operations.js): progress arrives
 * from realtime events and from polling alike, so a stale update never moves
 * the bar back, and an outcome is announced once.
 */
let browser;
let app;
let notices;

beforeEach(async () => {
    ({ app, browser } = await createPortalApp());
    notices = [];
    app.showNotice = (message, type) => notices.push([message, type]);
    browser.element('div', { 'data-operation-status': '' });
});

afterEach(() => browser?.dispose());

const status = () => browser.document.querySelector('[data-operation-status]').innerHTML;

describe('percentForStage', () => {
    it('places every stage the server reports, and an unknown one at the start', () => {
        assert.equal(app.percentForStage('file_received'), 20);
        assert.equal(app.percentForStage('portal_uploading'), 55);
        assert.equal(app.percentForStage('unverified'), 100);
        assert.equal(app.percentForStage('something_new'), 10);
    });
});

describe('handleUploadProgress', () => {
    it('never moves the bar back on a late update', () => {
        app.handleUploadProgress({ operation_id: 'op-1', status: 'portal_uploading', message: 'Mengunggah…' });
        app.handleUploadProgress({ operation_id: 'op-1', status: 'file_received', message: 'File diterima' });

        assert.equal(app.activeOperations.get('op-1').percent, 55);
        assert.match(status(), /Mengunggah…/);
        assert.match(status(), /width: 55%/);
    });

    it('announces a completed upload once, even when the poll reports it again', async () => {
        const outcome = app.trackOperation('op-2', 'Upload tugas', { announce: false });

        app.handleUploadProgress({ operation_id: 'op-2', status: 'completed', message: 'Tugas terkirim.' });
        app.handleUploadProgress({ operation_id: 'op-2', status: 'completed', message: 'Tugas terkirim.' });

        assert.equal(await outcome, 'completed');
        assert.deepEqual(notices, [['Tugas terkirim.', 'success']]);
        assert.equal(await app.trackOperation('op-2', 'Upload tugas'), 'completed');
    });

    it('tells the student what to do next when an upload fails or stays unverified', async () => {
        app.handleUploadProgress({ operation_id: 'op-3', status: 'failed', message: 'Upload gagal.', next_step: 'Coba lagi.' });
        app.handleUploadProgress({ operation_id: 'op-4', status: 'unverified', message: '', next_step: 'Cek portal.' });

        assert.deepEqual(notices, [['Upload gagal. Coba lagi.', 'error'], ['Status upload belum pasti. Cek portal.', 'warning']]);
        assert.equal(app.finishedOperations.get('op-3'), 'failed');
        assert.equal(app.finishedOperations.get('op-4'), 'unknown');
    });

    it('ignores progress without an operation id', () => {
        app.handleUploadProgress({ status: 'completed', message: 'x' });

        assert.equal(app.activeOperations.size, 0);
        assert.deepEqual(notices, []);
    });
});

describe('toneFromUrgency', () => {
    it('reads the server\'s urgency as a toast tone', () => {
        assert.equal(app.toneFromUrgency('critical'), 'error');
        assert.equal(app.toneFromUrgency('success'), 'success');
        assert.equal(app.toneFromUrgency(undefined), 'info');
        assert.equal(app.toneFromUrgency('loud'), 'info');
    });
});
