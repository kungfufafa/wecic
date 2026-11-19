<?php

/**
 * The accents (the PHP twin of app.ui.accents in resources/js/app/ui.js;
 * tests/Feature/BladeComponentsTest.php keeps the two equal). There are two:
 * `brand`, the one colour of the app, for selection and data highlights,
 * and `neutral`, gray. Each is a set of complete class strings: `tile` an
 * icon square, `soft` a light background, `text` text, `ring` a ring
 * colour, `dot` a solid dot or bar, `border` a border colour, `navIcon` the
 * sidebar icon of the current page and `tabMarker` the underline of an
 * active underlined tab. `glow` is always empty (there are no gradients).
 *
 * The old section accents (violet, emerald, amber, rose, indigo, teal,
 * orange, sky, fuchsia, slate, zinc) are deprecated names for `neutral`, so a
 * page that still names one renders gray instead of failing. Delete those
 * names at the call sites; they will be dropped here.
 *
 * Components no longer read this file: their `accent` props are ignored.
 *
 * @return array<string, array{tile: string, soft: string, text: string, ring: string, dot: string, glow: string, border: string, navIcon: string, tabMarker: string}>
 */
$neutralAccent = [
    'tile' => 'bg-surface text-ink-muted',
    'soft' => 'bg-surface',
    'text' => 'text-ink',
    'ring' => 'ring-border',
    'dot' => 'bg-ink-subtle',
    'glow' => '',
    'border' => 'border-border',
    'navIcon' => 'group-aria-[current=page]:text-ink',
    'tabMarker' => 'aria-selected:border-ink aria-pressed:border-ink aria-[current=page]:border-ink',
];

return [
    'brand' => [
        'tile' => 'bg-surface-tint text-brand-text',
        'soft' => 'bg-surface-tint',
        'text' => 'text-brand-text',
        'ring' => 'ring-brand/20 dark:ring-brand/30',
        'dot' => 'bg-brand',
        'glow' => '',
        'border' => 'border-brand/30',
        'navIcon' => 'group-aria-[current=page]:text-brand-text',
        'tabMarker' => 'aria-selected:border-brand aria-pressed:border-brand aria-[current=page]:border-brand',
    ],
    'neutral' => $neutralAccent,
    'violet' => $neutralAccent,
    'emerald' => $neutralAccent,
    'amber' => $neutralAccent,
    'rose' => $neutralAccent,
    'indigo' => $neutralAccent,
    'teal' => $neutralAccent,
    'orange' => $neutralAccent,
    'sky' => $neutralAccent,
    'fuchsia' => $neutralAccent,
    'slate' => $neutralAccent,
    'zinc' => $neutralAccent,
];
