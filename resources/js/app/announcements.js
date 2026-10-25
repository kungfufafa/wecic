import * as announcementRules from '../lib/announcements.js';

/**
 * Announcement read marks kept on this device (rules in lib/announcements.js).
 *
 * Methods of PortalApp (resources/js/app.js mixes them in): `this` is the
 * app, and every field they use is set in its constructor.
 */
export class AnnouncementFeature {
    /**
     * Whether this device marked an announcement read, under its id or the
     * wording-based id (`legacy_id`) marks were saved with before stable ids.
     */
    isAnnouncementRead(readIds, item) {
        return announcementRules.isAnnouncementRead(readIds, item);
    }

    /**
     * Announcement read marks with those saved under an old wording-based id
     * moved to the id `items` carry now: `{ ids, changed }`.
     */
    migrateAnnouncementReadIds(ids, items) {
        return announcementRules.migrateAnnouncementReadIds(ids, items);
    }

    /**
     * A record keyed by announcement id (ticked steps) with the entry saved
     * under `item`'s old wording-based id moved to its id: `{ record, changed }`.
     */
    migrateAnnouncementRecord(record, item) {
        return announcementRules.migrateAnnouncementRecord(record, item);
    }
}
