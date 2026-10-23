import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import {
    actionItemBadgeKey,
    countActionItemBadges,
    extractActionItems,
    severityTone,
    strongerTone,
} from '../../resources/js/lib/action-items.js';

const app = 'https://app.invalid';

describe('severityTone and strongerTone', () => {
    it('maps the portal\'s severities to tones', () => {
        assert.equal(severityTone('CRITICAL'), 'danger');
        assert.equal(severityTone('overdue'), 'danger');
        assert.equal(severityTone('medium'), 'warning');
        assert.equal(severityTone('paid'), 'success');
        assert.equal(severityTone('low'), 'info');
        assert.equal(severityTone(undefined), 'neutral');
        assert.equal(severityTone('apa saja'), 'neutral');
    });

    it('keeps the more urgent tone', () => {
        assert.equal(strongerTone(undefined, 'info'), 'info');
        assert.equal(strongerTone('warning', 'danger'), 'danger');
        assert.equal(strongerTone('danger', 'warning'), 'danger');
        assert.equal(strongerTone('warning', 'warning'), 'warning');
    });
});

describe('extractActionItems', () => {
    it('reads every payload shape the API has used', () => {
        assert.deepEqual(extractActionItems({ data: [1] }), [1]);
        assert.deepEqual(extractActionItems({ data: { items: [2] } }), [2]);
        assert.deepEqual(extractActionItems({ data: { action_items: [3] } }), [3]);
        assert.deepEqual(extractActionItems({ data: { items: 'x' } }), []);
        assert.deepEqual(extractActionItems(null), []);
    });
});

describe('actionItemBadgeKey', () => {
    it('places an item by its own link first', () => {
        assert.equal(actionItemBadgeKey({ cta_url: '/krs/12', source: 'payment' }, app), 'krs');
        assert.equal(actionItemBadgeKey({ cta_url: 'https://app.invalid/finance' }, app), 'finance');
        assert.equal(actionItemBadgeKey({ cta_url: '/meeting/3?courses_id=1' }, app), 'class');
        assert.equal(actionItemBadgeKey({ cta_url: '/exam' }, app), 'exam');
    });

    it('does not take a look-alike path for a nav slot', () => {
        assert.equal(actionItemBadgeKey({ cta_url: '/examples' }, app), null);
        assert.equal(actionItemBadgeKey({ cta_url: '/classroom' }, app), null);
    });

    it('falls back to the source and type for links elsewhere or none', () => {
        assert.equal(actionItemBadgeKey({ cta_url: 'https://portal.invalid/krs', source: 'Tagihan UKT' }, app), 'finance');
        assert.equal(actionItemBadgeKey({ source: 'perwalian' }, app), 'krs');
        assert.equal(actionItemBadgeKey({ type: 'Ujian' }, app), 'exam');
        assert.equal(actionItemBadgeKey({ source: 'Assignment', type: 'deadline' }, app), 'class');
        assert.equal(actionItemBadgeKey({ source: 'umum' }, app), null);
        assert.equal(actionItemBadgeKey(null, app), null);
    });
});

describe('countActionItemBadges', () => {
    const count = (items) => {
        const { counts, tones } = countActionItemBadges(items, app);

        return { counts: Object.fromEntries(counts), tones: Object.fromEntries(tones) };
    };

    it('lights only items that need action', () => {
        assert.deepEqual(count([
            { cta_url: '/krs', severity: 'info' },
            { cta_url: '/grades', severity: 'paid' },
            { source: 'umum', severity: 'critical' },
        ]), { counts: {}, tones: {} });
    });

    it('rolls tiles up into their nav slots with the strongest tone', () => {
        const { counts, tones } = count([
            { cta_url: '/krs', severity: 'warning' },
            { cta_url: '/grades', severity: 'critical' },
            { cta_url: '/finance', severity: 'warning' },
            { cta_url: '/skkm', severity: 'high' },
        ]);

        assert.deepEqual(counts, { krs: 1, akademik: 2, grades: 1, finance: 1, keuangan: 1, lainnya: 2, skkm: 1, 'lainnya-desktop': 1 });
        assert.equal(tones.akademik, 'danger');
        assert.equal(tones.keuangan, 'warning');
        assert.equal(tones.lainnya, 'danger');
    });

    it('keeps finance out of the desktop "Lainnya" menu, where it is a top-level link', () => {
        assert.equal(count([{ cta_url: '/finance', severity: 'critical' }]).counts['lainnya-desktop'], undefined);
    });

    it('puts exams and only critical class items under Kuliah', () => {
        assert.deepEqual(count([
            { cta_url: '/exam/5', severity: 'warning' },
            { cta_url: '/class/1', severity: 'warning' },
            { cta_url: '/meeting/2', severity: 'critical' },
        ]).counts, { exam: 1, kuliah: 2, class: 1 });
    });

    it('accepts anything that is not a list', () => {
        assert.deepEqual(count(null).counts, {});
        assert.deepEqual(count({ items: [] }).counts, {});
    });
});
