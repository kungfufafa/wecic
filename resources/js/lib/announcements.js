/**
 * Device state for announcements (read marks, ticked steps). Announcements
 * carry a stable `id` and, since that change, the wording-based id they had
 * before as `legacy_id`; state saved under the old id is still found and
 * moved to the new one. Pure, so tests/js runs it under Node.
 */

/**
 * The legacy id an announcement used to go by, when it differs from its id.
 *
 * @param {unknown} item
 * @returns {string|null}
 */
export function legacyAnnouncementId(item) {
    if (!item || typeof item !== 'object') {
        return null;
    }

    const { id, legacy_id: legacyId } = item;

    return typeof legacyId === 'string' && legacyId !== '' && legacyId !== id ? legacyId : null;
}

/**
 * Whether the device marked an announcement read, under its id or under the
 * wording-based id read marks were saved with before stable ids.
 *
 * @param {Set<string>} readIds
 * @param {unknown} item
 * @returns {boolean}
 */
export function isAnnouncementRead(readIds, item) {
    if (!item || typeof item !== 'object' || !(readIds instanceof Set)) {
        return false;
    }

    const legacyId = legacyAnnouncementId(item);

    return readIds.has(item.id) || (legacyId !== null && readIds.has(legacyId));
}

/**
 * Read marks with every mark saved under the legacy id of one of `items`
 * moved to that item's id. A mark keeps its place (the oldest marks are the
 * first dropped when the list is trimmed), and a mark that ends up twice is
 * kept at its latest place.
 *
 * @param {unknown} ids
 * @param {unknown} items
 * @returns {{ ids: string[], changed: boolean }}
 */
export function migrateAnnouncementReadIds(ids, items) {
    const stored = Array.isArray(ids) ? ids : [];
    const marks = stored.filter((id) => typeof id === 'string' && id !== '');
    const renamed = new Map();

    (Array.isArray(items) ? items : []).forEach((item) => {
        const legacyId = legacyAnnouncementId(item);

        if (legacyId !== null && typeof item.id === 'string' && item.id !== '') {
            renamed.set(legacyId, item.id);
        }
    });

    const moved = marks.map((id) => renamed.get(id) ?? id);
    const lastPlace = new Map(moved.map((id, index) => [id, index]));
    const migrated = moved.filter((id, index) => lastPlace.get(id) === index);
    const changed = migrated.length !== stored.length || migrated.some((id, index) => id !== stored[index]);

    return { ids: migrated, changed };
}

/**
 * A record keyed by announcement id (ticked steps per announcement) with the
 * entry saved under the item's legacy id moved to its id, in the same place.
 * An entry already under the id wins over the legacy one.
 *
 * @param {unknown} record
 * @param {unknown} item
 * @returns {{ record: Record<string, unknown>, changed: boolean }}
 */
export function migrateAnnouncementRecord(record, item) {
    const entries = record && typeof record === 'object' && !Array.isArray(record) ? record : {};
    const legacyId = legacyAnnouncementId(item);

    if (legacyId === null || typeof item.id !== 'string' || item.id === '' || !Object.hasOwn(entries, legacyId)) {
        return { record: entries, changed: false };
    }

    const migrated = {};

    Object.entries(entries).forEach(([key, value]) => {
        if (key === legacyId) {
            if (!Object.hasOwn(entries, item.id)) {
                migrated[item.id] = value;
            }

            return;
        }

        migrated[key] = value;
    });

    return { record: migrated, changed: true };
}
