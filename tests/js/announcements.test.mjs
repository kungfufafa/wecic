import { afterEach, describe, it } from 'node:test';
import assert from 'node:assert/strict';
import {
    isAnnouncementRead,
    legacyAnnouncementId,
    migrateAnnouncementReadIds,
    migrateAnnouncementRecord,
} from '../../resources/js/lib/announcements.js';
import { createPortalApp } from './support/browser.mjs';

/**
 * Announcement read marks and ticked steps kept on the device
 * (resources/js/lib/announcements.js). Before stable ids, an announcement's
 * id came from its wording; that id is now `legacy_id`, and what the device
 * saved under it must still count and move to the stable id.
 */
const stable = 'a'.repeat(40);
const legacy = 'b'.repeat(40);
const other = 'c'.repeat(40);
const item = { id: stable, legacy_id: legacy, title: 'Jadwal UTS' };

describe('legacyAnnouncementId', () => {
    it('is the old id only when it differs from the id', () => {
        assert.equal(legacyAnnouncementId(item), legacy);
        assert.equal(legacyAnnouncementId({ id: legacy, legacy_id: legacy }), null, 'two posts shared a stable id, so both kept their old one');
        assert.equal(legacyAnnouncementId({ id: stable }), null);
        assert.equal(legacyAnnouncementId({ id: stable, legacy_id: '' }), null);
        assert.equal(legacyAnnouncementId(null), null);
    });
});

describe('isAnnouncementRead', () => {
    it('counts a mark under the stable id or under the old one', () => {
        assert.equal(isAnnouncementRead(new Set([stable]), item), true);
        assert.equal(isAnnouncementRead(new Set([legacy]), item), true, 'read before the deploy');
        assert.equal(isAnnouncementRead(new Set([other]), item), false);
        assert.equal(isAnnouncementRead(new Set([legacy]), { id: stable }), false, 'no old id, no old mark');
    });

    it('is unread for anything that is not an announcement', () => {
        assert.equal(isAnnouncementRead(new Set([stable]), null), false);
        assert.equal(isAnnouncementRead([stable], item), false);
    });
});

describe('migrateAnnouncementReadIds', () => {
    it('moves a mark saved under the old id to the stable id, in the same place', () => {
        assert.deepEqual(migrateAnnouncementReadIds([other, legacy, 'x'], [item]), { ids: [other, stable, 'x'], changed: true });
    });

    it('keeps one mark, at its latest place, when both ids were saved', () => {
        assert.deepEqual(migrateAnnouncementReadIds([stable, other, legacy], [item]), { ids: [other, stable], changed: true });
        assert.deepEqual(migrateAnnouncementReadIds([legacy, other, stable], [item]), { ids: [other, stable], changed: true });
    });

    it('leaves marks alone when nothing was saved under an old id', () => {
        assert.deepEqual(migrateAnnouncementReadIds([other, stable], [item]), { ids: [other, stable], changed: false });
        assert.deepEqual(migrateAnnouncementReadIds([legacy], [{ id: legacy, legacy_id: legacy }]), { ids: [legacy], changed: false });
        assert.deepEqual(migrateAnnouncementReadIds([legacy], [{ id: stable }]), { ids: [legacy], changed: false });
        assert.deepEqual(migrateAnnouncementReadIds([legacy], null), { ids: [legacy], changed: false });
    });

    it('reads whatever the device holds without failing', () => {
        assert.deepEqual(migrateAnnouncementReadIds(undefined, [item]), { ids: [], changed: false });
        assert.deepEqual(migrateAnnouncementReadIds('rusak', [item]), { ids: [], changed: false });
        assert.deepEqual(migrateAnnouncementReadIds([legacy, 7, null, ''], [item, null, 'x']), { ids: [stable], changed: true });
    });
});

describe('migrateAnnouncementRecord', () => {
    it('moves ticked steps saved under the old id to the stable id, in the same place', () => {
        const { record, changed } = migrateAnnouncementRecord({ [other]: [1], [legacy]: [1, 2], z: [3] }, item);

        assert.equal(changed, true);
        assert.deepEqual(Object.keys(record), [other, stable, 'z']);
        assert.deepEqual(record[stable], [1, 2]);
    });

    it('keeps the steps already under the stable id', () => {
        const { record, changed } = migrateAnnouncementRecord({ [stable]: [3], [legacy]: [1, 2] }, item);

        assert.equal(changed, true);
        assert.deepEqual(record, { [stable]: [3] });
    });

    it('changes nothing without steps under an old id', () => {
        const stored = { [stable]: [1] };

        assert.deepEqual(migrateAnnouncementRecord(stored, item), { record: stored, changed: false });
        assert.deepEqual(migrateAnnouncementRecord(stored, { id: legacy, legacy_id: legacy }), { record: stored, changed: false });
        assert.deepEqual(migrateAnnouncementRecord(null, item), { record: {}, changed: false });
        assert.deepEqual(migrateAnnouncementRecord([1], item), { record: {}, changed: false });
    });
});

describe('PortalApp', () => {
    let browser;

    afterEach(() => browser?.dispose());

    it('gives the pages the same rules', async () => {
        const created = await createPortalApp();

        browser = created.browser;

        assert.equal(created.app.isAnnouncementRead(new Set([legacy]), item), true);
        assert.deepEqual(created.app.migrateAnnouncementReadIds([legacy], [item]), { ids: [stable], changed: true });
        assert.deepEqual(created.app.migrateAnnouncementRecord({ [legacy]: [2] }, item), { record: { [stable]: [2] }, changed: true });
    });
});
