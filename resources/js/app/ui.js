/**
 * The class strings for HTML a script builds: the one place scripts read a
 * component's look from (`app.ui.card`, `app.ui.badge.success`…). Every
 * value is a complete, literal Tailwind class list, so Tailwind (which scans
 * resources/**\/*.js) generates each class, and none is built by joining
 * pieces. The Blade components in resources/views/components write the same
 * strings; tests/Feature/BladeComponentsTest.php checks that they match.
 *
 * Rules worth knowing before adding to a string here:
 * - An element a script shows and hides with the `hidden` class must not
 *   carry `inline-flex`, `inline-block`, `inline` or `table`: those sort after
 *   `hidden` and win. `flex`, `block` and `grid` lose to `hidden`, as wanted.
 * - Two classes that set the same property (`text-ink` and `text-ink-muted`)
 *   do not override by their order in the attribute. Each string here is
 *   therefore complete on its own; pick another key instead of appending a
 *   conflicting class.
 */
const neutralAccent = Object.freeze({
    tile: 'bg-surface text-ink-muted',
    soft: 'bg-surface',
    text: 'text-ink',
    ring: 'ring-border',
    dot: 'bg-ink-subtle',
    glow: '',
    border: 'border-border',
    navIcon: 'group-aria-[current=page]:text-ink',
    tabMarker: 'aria-selected:border-ink aria-pressed:border-ink aria-[current=page]:border-ink',
});

export const ui = Object.freeze({
    container: 'mx-auto w-full max-w-6xl px-4 md:px-6 lg:px-8',

    // The accents (resources/views/components/data/accents.php): `brand`, the
    // one colour, for selection and data highlights, and `neutral`, gray. Each
    // has tile, soft, text, ring, dot, glow (always empty: no gradients),
    // border, navIcon and tabMarker. The old section names (violet, emerald,
    // amber…) are deprecated aliases of neutral, so an unconverted script
    // renders gray; delete them at the call sites. app.accentClass(accent, key)
    // reads one with a fallback to neutral.
    accents: Object.freeze({
        brand: Object.freeze({
            tile: 'bg-surface-tint text-brand-text',
            soft: 'bg-surface-tint',
            text: 'text-brand-text',
            ring: 'ring-brand/20 dark:ring-brand/30',
            dot: 'bg-brand',
            glow: '',
            border: 'border-brand/30',
            navIcon: 'group-aria-[current=page]:text-brand-text',
            tabMarker: 'aria-selected:border-brand aria-pressed:border-brand aria-[current=page]:border-brand',
        }),
        neutral: neutralAccent,
        violet: neutralAccent,
        emerald: neutralAccent,
        amber: neutralAccent,
        rose: neutralAccent,
        indigo: neutralAccent,
        teal: neutralAccent,
        orange: neutralAccent,
        sky: neutralAccent,
        fuchsia: neutralAccent,
        slate: neutralAccent,
        zinc: neutralAccent,
    }),

    // The inner <path>s of each icon (resources/views/components/data/icons.php);
    // app.renderIcon(name, size) draws one as <x-icon> does.
    icons: Object.freeze({
        home: '<path d="M5 12l-2 0l9 -9l9 9l-2 0" /><path d="M5 12v7a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-7" /><path d="M9 21v-6a2 2 0 0 1 2 -2h2a2 2 0 0 1 2 2v6" />',
        class: '<path d="M3 19a9 9 0 0 1 9 0a9 9 0 0 1 9 0" /><path d="M3 6a9 9 0 0 1 9 0a9 9 0 0 1 9 0" /><path d="M3 6l0 13" /><path d="M12 6l0 13" /><path d="M21 6l0 13" />',
        academic: '<path d="M22 9l-10 -4l-10 4l10 4l10 -4v6" /><path d="M6 10.6v5.4a6 3 0 0 0 12 0v-5.4" />',
        finance: '<path d="M17 8v-3a1 1 0 0 0 -1 -1h-10a2 2 0 0 0 0 4h12a1 1 0 0 1 1 1v3m0 4v3a1 1 0 0 1 -1 1h-12a2 2 0 0 1 -2 -2v-12" /><path d="M20 12v4h-4a2 2 0 0 1 0 -4h4" />',
        more: '<path d="M4 5a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v4a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1z" /><path d="M14 5a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v4a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1z" /><path d="M4 15a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v4a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1z" /><path d="M14 15a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v4a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1z" />',
        skkm: '<path d="M12 9m-6 0a6 6 0 1 0 12 0a6 6 0 1 0 -12 0" /><path d="M12 15l3.4 5.89l1.598 -3.233l3.598 .232l-3.4 -5.889" /><path d="M6.802 12l-3.4 5.89l3.598 -.233l1.598 3.232l3.4 -5.889" />',
        thesis: '<path d="M14 3v4a1 1 0 0 0 1 1h4" /><path d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2z" /><path d="M9 9l1 0" /><path d="M9 13l6 0" /><path d="M9 17l6 0" />',
        library: '<path d="M5 5a1 1 0 0 1 1 -1h2a1 1 0 0 1 1 1v14a1 1 0 0 1 -1 1h-2a1 1 0 0 1 -1 -1z" /><path d="M9 5a1 1 0 0 1 1 -1h2a1 1 0 0 1 1 1v14a1 1 0 0 1 -1 1h-2a1 1 0 0 1 -1 -1z" /><path d="M5 8h4" /><path d="M9 16h4" /><path d="M13.803 4.56l2.184 -.53c.562 -.135 1.133 .19 1.282 .732l3.695 13.418a1.02 1.02 0 0 1 -.634 1.219l-.133 .041l-2.184 .53c-.562 .135 -1.133 -.19 -1.282 -.732l-3.695 -13.418a1.02 1.02 0 0 1 .634 -1.219l.133 -.041z" /><path d="M14 9l4 -1" /><path d="M16 16l3.923 -.98" />',
        announcement: '<path d="M18 8a3 3 0 0 1 0 6" /><path d="M10 8v11a1 1 0 0 1 -1 1h-1a1 1 0 0 1 -1 -1v-5" /><path d="M12 8h0l4.524 -3.77a.9 .9 0 0 1 1.476 .692v12.156a.9 .9 0 0 1 -1.476 .692l-4.524 -3.77h-8a1 1 0 0 1 -1 -1v-4a1 1 0 0 1 1 -1h8" />',
        notebook: '<path d="M6 4h11a2 2 0 0 1 2 2v12a2 2 0 0 1 -2 2h-11a1 1 0 0 1 -1 -1v-14a1 1 0 0 1 1 -1m3 0v18" /><path d="M13 8l2 0" /><path d="M13 12l2 0" />',
        archive: '<path d="M3 6a2 2 0 0 1 2 -2h14a2 2 0 0 1 2 2a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2z" /><path d="M5 8v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-10" /><path d="M10 12l4 0" />',
        bell: '<path d="M10 5a2 2 0 1 1 4 0a7 7 0 0 1 4 6v3a4 4 0 0 0 2 3h-16a4 4 0 0 0 2 -3v-3a7 7 0 0 1 4 -6" /><path d="M9 17v1a3 3 0 0 0 6 0v-1" />',
        user: '<path d="M8 7a4 4 0 1 0 8 0a4 4 0 0 0 -8 0" /><path d="M6 21v-2a4 4 0 0 1 4 -4h4a4 4 0 0 1 4 4v2" />',
        users: '<path d="M9 7m-4 0a4 4 0 1 0 8 0a4 4 0 1 0 -8 0" /><path d="M3 21v-2a4 4 0 0 1 4 -4h4a4 4 0 0 1 4 4v2" /><path d="M16 3.13a4 4 0 0 1 0 7.75" /><path d="M21 21v-2a4 4 0 0 0 -3 -3.85" />',
        lock: '<path d="M5 13a2 2 0 0 1 2 -2h10a2 2 0 0 1 2 2v6a2 2 0 0 1 -2 2h-10a2 2 0 0 1 -2 -2v-6z" /><path d="M11 16a1 1 0 1 0 2 0a1 1 0 0 0 -2 0" /><path d="M8 11v-4a4 4 0 1 1 8 0v4" />',
        calendar: '<path d="M4 7a2 2 0 0 1 2 -2h12a2 2 0 0 1 2 2v12a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2v-12z" /><path d="M16 3v4" /><path d="M8 3v4" /><path d="M4 11h16" />',
        clock: '<path d="M3 12a9 9 0 1 0 18 0a9 9 0 0 0 -18 0" /><path d="M12 7v5l3 3" />',
        history: '<path d="M12 8l0 4l2 2" /><path d="M3.05 11a9 9 0 1 1 .5 4m-.5 5v-5h5" />',
        exam: '<path d="M9 5h-2a2 2 0 0 0 -2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-12a2 2 0 0 0 -2 -2h-2" /><path d="M9 3m0 2a2 2 0 0 1 2 -2h2a2 2 0 0 1 2 2v0a2 2 0 0 1 -2 2h-2a2 2 0 0 1 -2 -2z" /><path d="M9 14l2 2l4 -4" />',
        presentation: '<path d="M3 4l18 0" /><path d="M4 4v10a2 2 0 0 0 2 2h12a2 2 0 0 0 2 -2v-10" /><path d="M12 16l0 4" /><path d="M9 20l6 0" /><path d="M8 12l3 -3l2 2l3 -3" />',
        'list-check': '<path d="M3.5 5.5l1.5 1.5l2.5 -2.5" /><path d="M3.5 11.5l1.5 1.5l2.5 -2.5" /><path d="M3.5 17.5l1.5 1.5l2.5 -2.5" /><path d="M11 6l9 0" /><path d="M11 12l9 0" /><path d="M11 18l9 0" />',
        check: '<path d="M5 12l5 5l10 -10" />',
        'circle-check': '<path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" /><path d="M9 12l2 2l4 -4" />',
        info: '<path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" /><path d="M12 9h.01" /><path d="M11 12h1v4h1" />',
        alert: '<path d="M12 9v4" /><path d="M10.363 3.591l-8.106 13.534a1.914 1.914 0 0 0 1.636 2.871h16.214a1.914 1.914 0 0 0 1.636 -2.87l-8.106 -13.536a1.914 1.914 0 0 0 -3.274 0z" /><path d="M12 16h.01" />',
        help: '<path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" /><path d="M12 17l0 .01" /><path d="M12 13.5a1.5 1.5 0 0 1 1 -1.5a2.6 2.6 0 1 0 -3 -4" />',
        qr: '<path d="M4 4m0 1a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v4a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1z" /><path d="M7 7l0 .01" /><path d="M14 4m0 1a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v4a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1z" /><path d="M17 7l0 .01" /><path d="M4 14m0 1a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v4a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1z" /><path d="M7 17l0 .01" /><path d="M14 14l3 0" /><path d="M20 14l0 .01" /><path d="M14 14l0 3" /><path d="M14 20l3 0" /><path d="M17 17l3 0" /><path d="M20 17l0 3" />',
        search: '<path d="M10 10m-7 0a7 7 0 1 0 14 0a7 7 0 1 0 -14 0" /><path d="M21 21l-6 -6" />',
        filter: '<path d="M4 4h16v2.172a2 2 0 0 1 -.586 1.414l-4.414 4.414v7l-6 2v-8.5l-4.48 -4.928a2 2 0 0 1 -.52 -1.345v-2.227z" />',
        'chevron-right': '<path d="M9 6l6 6l-6 6" />',
        'chevron-left': '<path d="M15 6l-6 6l6 6" />',
        'chevron-down': '<path d="M6 9l6 6l6 -6" />',
        'arrow-right': '<path d="M5 12l14 0" /><path d="M13 18l6 -6" /><path d="M13 6l6 6" />',
        'external-link': '<path d="M12 6h-6a2 2 0 0 0 -2 2v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-6" /><path d="M11 13l9 -9" /><path d="M15 4h5v5" />',
        download: '<path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2 -2v-2" /><path d="M7 11l5 5l5 -5" /><path d="M12 4l0 12" />',
        upload: '<path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2 -2v-2" /><path d="M7 9l5 -5l5 5" /><path d="M12 4l0 12" />',
        file: '<path d="M14 3v4a1 1 0 0 0 1 1h4" /><path d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2z" />',
        folder: '<path d="M5 4h4l3 3h7a2 2 0 0 1 2 2v8a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2v-11a2 2 0 0 1 2 -2" />',
        inbox: '<path d="M4 6a2 2 0 0 1 2 -2h12a2 2 0 0 1 2 2v12a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2v-12z" /><path d="M4 13h3l3 3h4l3 -3h3" />',
        chart: '<path d="M3 13a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v6a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1z" /><path d="M15 9a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v10a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1z" /><path d="M9 5a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v14a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1z" /><path d="M4 20h14" />',
        'trending-up': '<path d="M3 17l6 -6l4 4l8 -8" /><path d="M14 7l7 0l0 7" />',
        star: '<path d="M12 17.75l-6.172 3.245l1.179 -6.873l-5 -4.867l6.9 -1l3.086 -6.253l3.086 6.253l6.9 1l-5 4.867l1.179 6.873z" />',
        receipt: '<path d="M5 21v-16a2 2 0 0 1 2 -2h10a2 2 0 0 1 2 2v16l-3 -2l-2 2l-2 -2l-2 2l-2 -2l-3 2" /><path d="M14 8h-2.5a1.5 1.5 0 0 0 0 3h1a1.5 1.5 0 0 1 0 3h-2.5m2 0v1.5m0 -9v1.5" />',
        'credit-card': '<path d="M3 8a3 3 0 0 1 3 -3h12a3 3 0 0 1 3 3v8a3 3 0 0 1 -3 3h-12a3 3 0 0 1 -3 -3z" /><path d="M3 10l18 0" /><path d="M7 15l.01 0" /><path d="M11 15l2 0" />',
        'map-pin': '<path d="M9 11a3 3 0 1 0 6 0a3 3 0 0 0 -6 0" /><path d="M17.657 16.657l-4.243 4.243a2 2 0 0 1 -2.827 0l-4.244 -4.243a8 8 0 1 1 11.314 0z" />',
        video: '<path d="M15 10l4.553 -2.276a1 1 0 0 1 1.447 .894v6.764a1 1 0 0 1 -1.447 .894l-4.553 -2.276v-4z" /><path d="M3 6m0 2a2 2 0 0 1 2 -2h8a2 2 0 0 1 2 2v8a2 2 0 0 1 -2 2h-8a2 2 0 0 1 -2 -2z" />',
        refresh: '<path d="M20 11a8.1 8.1 0 0 0 -15.5 -2m-.5 -4v4h4" /><path d="M4 13a8.1 8.1 0 0 0 15.5 2m.5 4v-4h-4" />',
        plus: '<path d="M12 5l0 14" /><path d="M5 12l14 0" />',
        x: '<path d="M18 6l-12 12" /><path d="M6 6l12 12" />',
        printer: '<path d="M17 17h2a2 2 0 0 0 2 -2v-4a2 2 0 0 0 -2 -2h-14a2 2 0 0 0 -2 2v4a2 2 0 0 0 2 2h2" /><path d="M17 9v-4a2 2 0 0 0 -2 -2h-6a2 2 0 0 0 -2 2v4" /><path d="M7 13m0 2a2 2 0 0 1 2 -2h6a2 2 0 0 1 2 2v4a2 2 0 0 1 -2 2h-6a2 2 0 0 1 -2 -2z" />',
        pin: '<path d="M15 4.5l-4 4l-4 1.5l-1.5 1.5l7 7l1.5 -1.5l1.5 -4l4 -4" /><path d="M9 15l-4.5 4.5" /><path d="M14.5 4l5.5 5.5" />',
        logout: '<path d="M14 8v-2a2 2 0 0 0 -2 -2h-7a2 2 0 0 0 -2 2v12a2 2 0 0 0 2 2h7a2 2 0 0 0 2 -2v-2" /><path d="M9 12h12l-3 -3" /><path d="M18 15l3 -3" />',
        world: '<path d="M3 12a9 9 0 1 0 18 0a9 9 0 0 0 -18 0" /><path d="M3.6 9h16.8" /><path d="M3.6 15h16.8" /><path d="M11.5 3a17 17 0 0 0 0 18" /><path d="M12.5 3a17 17 0 0 1 0 18" />',
        id: '<path d="M3 4m0 3a3 3 0 0 1 3 -3h12a3 3 0 0 1 3 3v10a3 3 0 0 1 -3 3h-12a3 3 0 0 1 -3 -3z" /><path d="M9 10m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" /><path d="M15 8l2 0" /><path d="M15 12l2 0" /><path d="M7 16l10 0" />',
        mail: '<path d="M3 7a2 2 0 0 1 2 -2h14a2 2 0 0 1 2 2v10a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2v-10z" /><path d="M3 7l9 6l9 -6" />',
        eye: '<path d="M10 12a2 2 0 1 0 4 0a2 2 0 0 0 -4 0" /><path d="M21 12c-2.4 4 -5.4 6 -9 6c-3.6 0 -6.6 -2 -9 -6c2.4 -4 5.4 -6 9 -6c3.6 0 6.6 2 9 6" />',
    }),

    iconSize: Object.freeze({
        sm: 'size-4 shrink-0',
        md: 'size-[18px] shrink-0',
        lg: 'size-5 shrink-0',
        xl: 'size-6 shrink-0',
    }),

    button: Object.freeze({
        primary: 'inline-flex min-h-11 cursor-pointer select-none items-center justify-center gap-2 whitespace-nowrap rounded-lg bg-brand px-4 text-sm font-medium text-brand-ink shadow-xs ring-offset-panel transition-colors hover:bg-brand-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40 focus-visible:ring-offset-2 active:bg-brand-pressed disabled:pointer-events-none disabled:opacity-50 aria-disabled:pointer-events-none aria-disabled:opacity-50 sm:min-h-9',
        secondary: 'inline-flex min-h-11 cursor-pointer select-none items-center justify-center gap-2 whitespace-nowrap rounded-lg border border-border bg-panel px-4 text-sm font-medium text-ink shadow-xs ring-offset-panel transition-colors hover:bg-surface focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40 focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 aria-disabled:pointer-events-none aria-disabled:opacity-50 sm:min-h-9 dark:shadow-none',
        ghost: 'inline-flex min-h-11 cursor-pointer select-none items-center justify-center gap-2 whitespace-nowrap rounded-lg px-4 text-sm font-medium text-ink-muted ring-offset-panel transition-colors hover:bg-surface hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40 focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 aria-disabled:pointer-events-none aria-disabled:opacity-50 sm:min-h-9',
        destructive: 'inline-flex min-h-11 cursor-pointer select-none items-center justify-center gap-2 whitespace-nowrap rounded-lg bg-danger px-4 text-sm font-medium text-white shadow-xs ring-offset-panel transition-colors hover:bg-danger/90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-danger/40 focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 aria-disabled:pointer-events-none aria-disabled:opacity-50 sm:min-h-9',
        icon: 'inline-flex size-9 shrink-0 cursor-pointer select-none items-center justify-center rounded-lg text-ink-muted ring-offset-panel transition-colors hover:bg-surface hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40 focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 aria-disabled:pointer-events-none aria-disabled:opacity-50',
        primarySm: 'inline-flex min-h-11 cursor-pointer select-none items-center justify-center gap-1.5 whitespace-nowrap rounded-lg bg-brand px-3 text-[13px] font-medium text-brand-ink shadow-xs ring-offset-panel transition-colors hover:bg-brand-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40 focus-visible:ring-offset-2 active:bg-brand-pressed disabled:pointer-events-none disabled:opacity-50 aria-disabled:pointer-events-none aria-disabled:opacity-50 sm:min-h-8',
        secondarySm: 'inline-flex min-h-11 cursor-pointer select-none items-center justify-center gap-1.5 whitespace-nowrap rounded-lg border border-border bg-panel px-3 text-[13px] font-medium text-ink shadow-xs ring-offset-panel transition-colors hover:bg-surface focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40 focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 aria-disabled:pointer-events-none aria-disabled:opacity-50 sm:min-h-8 dark:shadow-none',
        ghostSm: 'inline-flex min-h-11 cursor-pointer select-none items-center justify-center gap-1.5 whitespace-nowrap rounded-lg px-3 text-[13px] font-medium text-ink-muted ring-offset-panel transition-colors hover:bg-surface hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40 focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 aria-disabled:pointer-events-none aria-disabled:opacity-50 sm:min-h-8',
        destructiveSm: 'inline-flex min-h-11 cursor-pointer select-none items-center justify-center gap-1.5 whitespace-nowrap rounded-lg bg-danger px-3 text-[13px] font-medium text-white shadow-xs ring-offset-panel transition-colors hover:bg-danger/90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-danger/40 focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 aria-disabled:pointer-events-none aria-disabled:opacity-50 sm:min-h-8',
        iconSm: 'inline-flex size-8 shrink-0 cursor-pointer select-none items-center justify-center rounded-lg text-ink-muted ring-offset-panel transition-colors hover:bg-surface hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40 focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 aria-disabled:pointer-events-none aria-disabled:opacity-50',
    }),

    card: 'rounded-xl border border-border bg-panel',
    cardInteractive: 'block cursor-pointer rounded-xl border border-border bg-panel transition-colors hover:bg-surface/50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40',
    cardHeader: 'flex items-center justify-between gap-3 border-b border-border-light px-4 py-3.5 sm:px-5 sm:py-4',
    cardTitle: 'text-sm font-semibold tracking-tight text-ink',
    cardSubtitle: 'mt-0.5 text-[13px] text-ink-muted',
    cardBody: 'p-4 sm:p-5',
    cardFooter: 'flex flex-wrap items-center gap-3 border-t border-border-light px-4 py-3 sm:px-5',

    metric: 'flex min-w-0 flex-col gap-1 bg-panel p-4 sm:p-5',
    metricInteractive: 'flex min-w-0 cursor-pointer flex-col gap-1 bg-panel p-4 transition-colors hover:bg-surface focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brand/40 sm:p-5',
    metricHead: 'flex items-start justify-between gap-3',
    metricLabel: 'truncate text-xs font-medium text-ink-muted',
    metricValue: 'text-2xl font-semibold tracking-tight text-ink tabular-nums',
    metricNote: 'text-xs text-ink-subtle',
    statGrid: 'grid gap-px overflow-hidden rounded-xl border border-border bg-border',
    statGridKpi: 'grid grid-cols-2 gap-px overflow-hidden rounded-xl border border-border bg-border lg:grid-cols-4',

    pageHeader: 'mb-6',
    pageEyebrow: 'mb-1 text-[13px] font-medium text-ink-subtle',
    pageTitle: 'text-2xl font-semibold tracking-tight text-ink',
    pageSubtitle: 'mt-1 text-sm text-ink-muted',
    sectionTitle: 'text-sm font-semibold text-ink',
    sectionHeading: 'flex items-end justify-between gap-3',
    sectionTitleRow: 'flex min-w-0 items-baseline gap-1.5',
    sectionCount: "text-sm text-ink-muted tabular-nums before:me-1.5 before:content-['·'] empty:hidden",
    sectionDescription: 'mt-0.5 text-[13px] text-ink-muted',
    sectionLink: 'inline-flex min-h-11 shrink-0 items-center gap-1 rounded-sm text-[13px] font-medium text-ink-muted transition-colors hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40 sm:min-h-0',

    pageHero: 'mb-6',
    pageHeroRow: 'flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between',
    pageHeroTitle: 'text-2xl font-semibold tracking-tight text-ink',
    pageHeroSubtitle: 'mt-1 text-sm text-ink-muted',
    pageHeroActions: 'flex shrink-0 flex-wrap items-center gap-2',
    pageHeroMeta: 'mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-ink-muted',
    pageHeroTabs: 'mt-4',

    breadcrumb: 'flex min-w-0 items-center text-xs text-ink-subtle',
    breadcrumbMd: 'flex min-w-0 items-center text-sm text-ink-subtle',
    breadcrumbList: 'flex min-w-0 items-center gap-1.5',
    breadcrumbItem: 'flex min-w-0 items-center gap-1.5',
    breadcrumbLink: '-my-3.5 block truncate py-3.5 transition-colors hover:text-ink focus-visible:text-ink focus-visible:underline focus-visible:outline-none lg:my-0 lg:py-0',
    breadcrumbCurrent: 'block truncate font-medium text-ink',
    breadcrumbSeparator: 'shrink-0 text-ink-subtle/60',
    link: 'font-medium text-brand-text underline-offset-4 hover:underline',
    backLink: '-ms-2 inline-flex min-h-11 cursor-pointer items-center gap-1 rounded-lg px-2 text-sm font-medium text-ink-muted transition-colors hover:bg-surface hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40 sm:min-h-9',
    divider: 'my-3 border-t border-border-light',

    badge: Object.freeze({
        neutral: 'inline-flex items-center gap-1 whitespace-nowrap rounded-md bg-surface px-2 py-0.5 text-xs font-medium text-ink-muted ring-1 ring-inset ring-border',
        brand: 'inline-flex items-center gap-1 whitespace-nowrap rounded-md bg-surface-tint px-2 py-0.5 text-xs font-medium text-brand-text ring-1 ring-inset ring-brand/20',
        accent: 'inline-flex items-center gap-1 whitespace-nowrap rounded-md bg-accent-soft px-2 py-0.5 text-xs font-medium text-amber-900 ring-1 ring-inset ring-accent/40 dark:text-amber-200',
        success: 'inline-flex items-center gap-1 whitespace-nowrap rounded-md bg-success-soft px-2 py-0.5 text-xs font-medium text-success ring-1 ring-inset ring-success/20',
        warning: 'inline-flex items-center gap-1 whitespace-nowrap rounded-md bg-warning-soft px-2 py-0.5 text-xs font-medium text-warning ring-1 ring-inset ring-warning/20',
        danger: 'inline-flex items-center gap-1 whitespace-nowrap rounded-md bg-danger-soft px-2 py-0.5 text-xs font-medium text-danger ring-1 ring-inset ring-danger/20',
        info: 'inline-flex items-center gap-1 whitespace-nowrap rounded-md bg-info-soft px-2 py-0.5 text-xs font-medium text-info ring-1 ring-inset ring-info/20',
    }),

    alert: Object.freeze({
        neutral: 'rounded-lg border border-border bg-panel px-4 py-3 text-sm text-ink-muted',
        brand: 'rounded-lg border border-brand/20 bg-surface-tint px-4 py-3 text-sm text-ink',
        info: 'rounded-lg border border-info/20 bg-info-soft px-4 py-3 text-sm text-ink',
        success: 'rounded-lg border border-success/25 bg-success-soft px-4 py-3 text-sm text-ink',
        warning: 'rounded-lg border border-warning/25 bg-warning-soft px-4 py-3 text-sm text-ink',
        danger: 'rounded-lg border border-danger/25 bg-danger-soft px-4 py-3 text-sm text-ink',
    }),

    alertIcon: Object.freeze({
        neutral: 'mt-0.5 size-4 shrink-0 text-ink-subtle',
        brand: 'mt-0.5 size-4 shrink-0 text-brand-text',
        info: 'mt-0.5 size-4 shrink-0 text-info',
        success: 'mt-0.5 size-4 shrink-0 text-success',
        warning: 'mt-0.5 size-4 shrink-0 text-warning',
        danger: 'mt-0.5 size-4 shrink-0 text-danger',
    }),

    panel: Object.freeze({
        neutral: 'rounded-lg bg-surface p-3 text-ink-muted',
        brand: 'rounded-lg bg-surface-tint p-3 text-brand-text',
        info: 'rounded-lg bg-info-soft p-3 text-info',
        success: 'rounded-lg bg-success-soft p-3 text-success',
        warning: 'rounded-lg bg-warning-soft p-3 text-warning',
        danger: 'rounded-lg bg-danger-soft p-3 text-danger',
    }),

    inset: 'rounded-lg border border-border-light bg-surface/40 p-4',
    insetPlain: 'rounded-lg border border-border bg-panel p-3',

    iconTileBase: 'flex shrink-0 items-center justify-center rounded-lg',
    iconTileBaseMd: 'flex shrink-0 items-center justify-center rounded-md',
    iconTileBaseXl: 'flex shrink-0 items-center justify-center rounded-xl',
    iconTileBaseFull: 'flex shrink-0 items-center justify-center rounded-full',
    iconTile: Object.freeze({
        neutral: 'flex shrink-0 items-center justify-center rounded-lg bg-surface text-ink-muted',
        brand: 'flex shrink-0 items-center justify-center rounded-lg bg-surface-tint text-brand-text',
        accent: 'flex shrink-0 items-center justify-center rounded-lg bg-accent-soft text-amber-900 dark:text-amber-200',
        success: 'flex shrink-0 items-center justify-center rounded-lg bg-success-soft text-success',
        warning: 'flex shrink-0 items-center justify-center rounded-lg bg-warning-soft text-warning',
        danger: 'flex shrink-0 items-center justify-center rounded-lg bg-danger-soft text-danger',
        info: 'flex shrink-0 items-center justify-center rounded-lg bg-info-soft text-info',
    }),

    text: Object.freeze({
        brand: 'text-brand-text',
        success: 'text-success',
        warning: 'text-warning',
        danger: 'text-danger',
        info: 'text-info',
        muted: 'text-ink-muted',
        subtle: 'text-ink-subtle',
    }),

    fill: Object.freeze({
        brand: 'bg-brand',
        success: 'bg-success',
        warning: 'bg-warning',
        danger: 'bg-danger',
        info: 'bg-info',
        accent: 'bg-accent',
        neutral: 'bg-ink-subtle/50',
    }),

    form: 'flex flex-col gap-5',
    field: 'flex flex-col gap-1.5',
    label: 'text-sm font-medium text-ink',
    hint: 'text-xs leading-relaxed text-ink-subtle',
    error: 'text-xs font-medium leading-relaxed text-danger',
    input: 'h-11 w-full min-w-0 rounded-lg border border-border bg-panel px-3 text-base text-ink shadow-xs transition-[color,box-shadow,border-color] placeholder:text-ink-subtle focus-visible:border-brand focus-visible:outline-none focus-visible:ring-[3px] focus-visible:ring-brand/20 disabled:cursor-not-allowed disabled:bg-surface disabled:opacity-60 aria-invalid:border-danger aria-invalid:ring-danger/20 sm:h-9 sm:text-sm dark:shadow-none',
    inputWithIcon: 'h-11 w-full min-w-0 rounded-lg border border-border bg-panel pe-3 ps-9 text-base text-ink shadow-xs transition-[color,box-shadow,border-color] placeholder:text-ink-subtle focus-visible:border-brand focus-visible:outline-none focus-visible:ring-[3px] focus-visible:ring-brand/20 disabled:cursor-not-allowed disabled:bg-surface disabled:opacity-60 aria-invalid:border-danger aria-invalid:ring-danger/20 sm:h-9 sm:text-sm dark:shadow-none',
    inputWithToggle: 'h-11 w-full min-w-0 rounded-lg border border-border bg-panel pe-11 ps-3 text-base text-ink shadow-xs transition-[color,box-shadow,border-color] placeholder:text-ink-subtle focus-visible:border-brand focus-visible:outline-none focus-visible:ring-[3px] focus-visible:ring-brand/20 disabled:cursor-not-allowed disabled:bg-surface disabled:opacity-60 aria-invalid:border-danger aria-invalid:ring-danger/20 sm:h-9 sm:text-sm dark:shadow-none',
    inputWithIconAndToggle: 'h-11 w-full min-w-0 rounded-lg border border-border bg-panel pe-11 ps-9 text-base text-ink shadow-xs transition-[color,box-shadow,border-color] placeholder:text-ink-subtle focus-visible:border-brand focus-visible:outline-none focus-visible:ring-[3px] focus-visible:ring-brand/20 disabled:cursor-not-allowed disabled:bg-surface disabled:opacity-60 aria-invalid:border-danger aria-invalid:ring-danger/20 sm:h-9 sm:text-sm dark:shadow-none',
    select: 'h-11 w-full min-w-0 cursor-pointer rounded-lg border border-border bg-panel px-3 text-base text-ink shadow-xs transition-[color,box-shadow,border-color] focus-visible:border-brand focus-visible:outline-none focus-visible:ring-[3px] focus-visible:ring-brand/20 disabled:cursor-not-allowed disabled:bg-surface disabled:opacity-60 aria-invalid:border-danger aria-invalid:ring-danger/20 sm:h-9 sm:text-sm dark:shadow-none',
    textarea: 'min-h-20 w-full min-w-0 rounded-lg border border-border bg-panel px-3 py-2 text-base leading-relaxed text-ink shadow-xs transition-[color,box-shadow,border-color] placeholder:text-ink-subtle focus-visible:border-brand focus-visible:outline-none focus-visible:ring-[3px] focus-visible:ring-brand/20 disabled:cursor-not-allowed disabled:bg-surface disabled:opacity-60 aria-invalid:border-danger aria-invalid:ring-danger/20 sm:text-sm dark:shadow-none',
    fileInput: 'w-full min-w-0 cursor-pointer rounded-lg border border-border bg-panel py-1.5 pe-3 ps-1.5 text-xs text-ink-subtle shadow-xs transition-[color,box-shadow,border-color] file:me-3 file:cursor-pointer file:rounded-md file:border-0 file:bg-surface file:px-3 file:py-1.5 file:text-xs file:font-medium file:text-ink hover:file:bg-surface-tint focus-visible:border-brand focus-visible:outline-none focus-visible:ring-[3px] focus-visible:ring-brand/20 disabled:cursor-not-allowed disabled:opacity-60 dark:shadow-none',
    checkbox: 'size-4 shrink-0 cursor-pointer rounded border-border accent-brand',
    inputWrap: 'relative flex items-center',
    inputIcon: 'pointer-events-none absolute start-3 top-1/2 z-10 -translate-y-1/2 text-ink-subtle',
    inputToggle: 'absolute end-1 top-1/2 z-10 flex size-9 -translate-y-1/2 sm:size-7 cursor-pointer items-center justify-center rounded-md text-ink-subtle transition-colors hover:bg-surface hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40',
    dropzone: 'relative flex cursor-pointer flex-col items-center justify-center rounded-xl border border-dashed border-border bg-surface/40 p-6 text-center transition-colors hover:border-brand hover:bg-surface-tint/40 [&.dragover]:border-brand [&.dragover]:bg-surface-tint/60 [&.dragover]:ring-2 [&.dragover]:ring-brand/20',
    legalText: 'text-center text-xs leading-relaxed text-ink-subtle',

    skeleton: 'animate-pulse rounded-md bg-surface',
    spinner: 'block size-4 shrink-0 animate-spin rounded-full border-2 border-current border-t-transparent',
    empty: 'flex flex-col items-center gap-2 rounded-xl border border-dashed border-border px-6 py-10 text-center',
    emptyIcon: 'mb-1 flex text-ink-subtle',
    emptyTitle: 'text-sm font-medium text-ink',
    emptySubtitle: 'max-w-sm text-xs leading-relaxed text-ink-subtle',

    listRow: 'flex items-start justify-between gap-3 border-b border-border-light py-3 first:pt-0 last:border-b-0 last:pb-0',
    listRowInteractive: '-mx-2 cursor-pointer rounded-lg px-2 transition-colors hover:bg-surface/60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40',
    listRowDivided: 'flex items-center justify-between gap-3 py-3 first:pt-0 last:pb-0',
    list: 'divide-y divide-border-light',
    listRowFlush: 'flex items-center gap-3 px-4 py-3.5 sm:px-5',
    listRowFlushInteractive: 'flex min-h-11 cursor-pointer items-center gap-3 px-4 py-3.5 transition-colors hover:bg-surface/60 focus-visible:bg-surface/60 focus-visible:outline-none sm:px-5',
    listRowBody: 'min-w-0 flex-1',
    listRowTitle: 'truncate text-sm font-medium text-ink',
    listRowSubtitle: 'mt-0.5 truncate text-xs text-ink-subtle',
    listRowTrailing: 'flex shrink-0 flex-col items-end gap-1 text-end text-xs text-ink-subtle',
    listRowChevron: 'size-4 shrink-0 text-ink-subtle',

    dateTile: 'flex size-11 shrink-0 flex-col items-center justify-center rounded-lg bg-surface text-ink',
    dateTileBase: 'flex size-11 shrink-0 flex-col items-center justify-center rounded-lg',
    dateTileMonth: 'text-[10px] font-medium uppercase leading-none tracking-wide opacity-80',
    dateTileDay: 'mt-0.5 text-base font-semibold leading-none tabular-nums',

    split: 'grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem] lg:items-start xl:grid-cols-[minmax(0,1fr)_22rem] xl:gap-8',
    splitXl: 'grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem] xl:items-start xl:gap-8',
    splitMain: 'flex min-w-0 flex-col gap-8',
    splitAside: 'flex min-w-0 flex-col gap-6 lg:sticky lg:top-20',
    splitAsideXl: 'flex min-w-0 flex-col gap-6 xl:sticky xl:top-20',
    splitAsideStatic: 'flex min-w-0 flex-col gap-6',

    tabs: 'inline-flex max-w-full items-center gap-1 overflow-x-auto rounded-lg bg-surface p-1',
    tab: 'inline-flex h-11 shrink-0 cursor-pointer select-none items-center justify-center gap-1.5 whitespace-nowrap rounded-md px-3 text-sm font-medium text-ink-muted transition-colors hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40 aria-selected:bg-panel aria-selected:text-ink aria-selected:shadow-xs aria-pressed:bg-panel aria-pressed:text-ink aria-pressed:shadow-xs aria-[current=page]:bg-panel aria-[current=page]:text-ink aria-[current=page]:shadow-xs sm:h-8 dark:aria-selected:bg-surface-tint dark:aria-pressed:bg-surface-tint dark:aria-[current=page]:bg-surface-tint',
    tabActive: 'inline-flex h-11 shrink-0 cursor-pointer select-none items-center justify-center gap-1.5 whitespace-nowrap rounded-md bg-panel px-3 text-sm font-medium text-ink shadow-xs transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40 sm:h-8 dark:bg-surface-tint',
    underlineTabs: 'flex max-w-full gap-4 overflow-x-auto shadow-[inset_0_-1px_0_var(--color-border)]',
    // The active one carries an ink underline (no colour).
    underlineTab: 'inline-flex min-h-11 shrink-0 cursor-pointer select-none items-center gap-1.5 whitespace-nowrap border-b-2 border-transparent px-1 text-sm font-medium text-ink-muted transition-colors hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40 focus-visible:ring-inset aria-selected:border-ink aria-selected:text-ink aria-pressed:border-ink aria-pressed:text-ink aria-[current=page]:border-ink aria-[current=page]:text-ink',

    tableWrap: 'overflow-x-auto rounded-xl border border-border bg-panel',
    table: 'w-full caption-bottom text-sm',
    th: 'h-10 whitespace-nowrap border-b border-border bg-surface/40 px-4 text-start text-xs font-medium text-ink-muted',
    td: 'border-b border-border-light px-4 py-3 text-sm',
    tr: 'transition-colors hover:bg-surface/50 [&:last-child>td]:border-b-0',

    progressTrack: 'flex w-full gap-0.5 overflow-hidden rounded-full bg-surface',
    progressFill: 'h-full shrink-0 first:rounded-s-full last:rounded-e-full',
    progressStriped: 'bg-[repeating-linear-gradient(135deg,rgb(255_255_255/38%)_0_4px,transparent_4px_8px)]',

    chip: 'inline-flex items-center gap-2 rounded-md border border-border-light bg-panel px-2 py-1 text-xs font-medium text-ink-muted',
    chipOnline: "inline-flex shrink-0 items-center gap-2 text-xs font-medium text-ink-muted before:size-1.5 before:shrink-0 before:rounded-full before:bg-success before:content-['']",
    chipOffline: "inline-flex shrink-0 items-center gap-2 rounded-md bg-warning-soft px-2 py-0.5 text-xs font-medium text-warning before:size-1.5 before:shrink-0 before:rounded-full before:bg-warning before:content-['']",

    syncStrip: Object.freeze({
        base: 'flex min-h-9 min-w-0 items-center text-xs text-ink-subtle',
        success: 'flex min-h-9 min-w-0 items-center text-xs text-ink-muted',
        warning: 'flex min-h-9 min-w-0 items-center rounded-lg border border-warning/25 bg-warning-soft px-3 py-1 text-xs text-ink',
        danger: 'flex min-h-9 min-w-0 items-center rounded-lg border border-danger/25 bg-danger-soft px-3 py-1 text-xs text-ink',
    }),

    dropdownPanel: 'absolute end-0 top-full z-50 mt-2 min-w-52 rounded-xl border border-border bg-panel p-1 shadow-float',
    dropdownPanelUp: 'absolute inset-x-0 bottom-full z-50 mb-1 rounded-xl border border-border bg-panel p-1 shadow-float',
    dropdownLabel: 'px-2.5 py-1.5 text-xs font-medium text-ink-subtle',
    dropdownItem: 'flex min-h-11 w-full cursor-pointer items-center gap-2 rounded-md px-2.5 py-2 text-start text-sm text-ink-muted transition-colors hover:bg-surface hover:text-ink focus-visible:bg-surface focus-visible:text-ink focus-visible:outline-none aria-[current=page]:font-medium aria-[current=page]:text-ink sm:min-h-9',
    dropdownItemDanger: 'flex min-h-11 w-full cursor-pointer items-center gap-2 rounded-md px-2.5 py-2 text-start text-sm font-medium text-danger transition-colors hover:bg-danger-soft focus-visible:bg-danger-soft focus-visible:outline-none sm:min-h-9',
    dropdownDivider: '-mx-1 my-1 h-px bg-border-light',

    avatar: 'inline-flex size-8 shrink-0 items-center justify-center overflow-hidden rounded-full bg-surface text-xs font-medium text-ink-muted ring-1 ring-border',
    avatarLg: 'inline-flex size-16 shrink-0 items-center justify-center overflow-hidden rounded-full border border-border bg-surface text-xl font-medium text-ink-muted [&_img]:size-full [&_img]:object-cover',

    modal: 'fixed inset-0 z-50',
    modalBackdrop: 'absolute inset-0 bg-black/50 backdrop-blur-[2px]',
    modalPanel: 'absolute left-1/2 top-1/2 z-10 max-h-[calc(100dvh-2rem)] w-[calc(100%-2rem)] max-w-md -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-xl border border-border bg-panel shadow-float focus:outline-none',
    modalPanelLg: 'absolute left-1/2 top-1/2 z-10 max-h-[calc(100dvh-2rem)] w-[calc(100%-2rem)] max-w-lg -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-xl border border-border bg-panel shadow-float focus:outline-none',
    modalHeader: 'flex items-start justify-between gap-3 border-b border-border-light px-4 py-4 sm:px-5',
    modalTitle: 'text-base font-semibold tracking-tight text-ink',
    modalBody: 'px-4 py-4 sm:px-5',
    modalFooter: 'flex flex-col-reverse gap-2 border-t border-border-light px-4 py-3 sm:flex-row sm:justify-end sm:px-5',
    modalClose: 'inline-flex size-9 shrink-0 cursor-pointer items-center justify-center rounded-lg text-ink-subtle transition-colors hover:bg-surface hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40',

    sheet: 'fixed inset-0 z-[55]',
    sheetBackdrop: 'absolute inset-0 bg-black/50 backdrop-blur-[2px]',
    sheetPanel: 'absolute inset-x-0 bottom-0 z-10 max-h-[85dvh] animate-sheet-in overflow-y-auto rounded-t-xl border border-b-0 border-border bg-panel pb-[env(safe-area-inset-bottom,0px)] shadow-float focus:outline-none md:inset-x-auto md:bottom-auto md:left-1/2 md:top-1/2 md:w-[calc(100%-2rem)] md:max-w-md md:-translate-x-1/2 md:-translate-y-1/2 md:rounded-xl md:border-b md:pb-0',
    sheetHandle: 'mx-auto mt-2 h-1 w-10 rounded-full bg-border md:hidden',
    sheetAction: 'flex min-h-12 w-full cursor-pointer items-center gap-3 rounded-lg border border-border-light bg-panel px-3 py-2 text-start text-sm transition-colors hover:bg-surface focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40',
    sheetActionIcon: 'flex size-9 shrink-0 items-center justify-center rounded-lg bg-surface-tint text-brand-text',

    toastHost: 'pointer-events-none fixed inset-x-0 bottom-[calc(4.75rem+env(safe-area-inset-bottom,0px))] z-[60] flex flex-col items-center gap-2 px-4 lg:top-4 lg:bottom-auto',
    toastHostGuest: 'pointer-events-none fixed inset-x-0 bottom-[calc(1rem+env(safe-area-inset-bottom,0px))] z-[60] flex flex-col items-center gap-2 px-4 md:top-4 md:bottom-auto',
    toast: 'pointer-events-auto flex w-full max-w-sm animate-toast-in items-center gap-3 rounded-xl border border-border bg-panel px-3 py-2.5 text-sm shadow-float motion-reduce:animate-none',
    toastIcon: Object.freeze({
        success: 'flex size-5 shrink-0 items-center justify-center text-success',
        danger: 'flex size-5 shrink-0 items-center justify-center text-danger',
        warning: 'flex size-5 shrink-0 items-center justify-center text-warning',
        info: 'flex size-5 shrink-0 items-center justify-center text-info',
    }),
    toastMessage: 'm-0 min-w-0 flex-1 text-sm font-medium text-ink',
    toastClose: 'inline-flex size-8 shrink-0 cursor-pointer items-center justify-center rounded-lg text-ink-subtle transition-colors hover:bg-surface hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40',

    stickyAction: 'fixed inset-x-3 bottom-[calc(4.75rem+env(safe-area-inset-bottom,0px))] z-30 rounded-xl border border-border bg-panel p-2.5 shadow-float lg:static lg:rounded-none lg:border-0 lg:bg-transparent lg:p-0 lg:shadow-none',

    tile: 'flex cursor-pointer flex-col items-center gap-2 rounded-lg p-2 text-center transition-colors hover:bg-surface focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40',
    tileIcon: 'flex size-12 items-center justify-center rounded-lg bg-surface text-ink-muted',
    tileLabel: 'text-[11px] font-medium leading-tight text-ink-muted',
    outlineItem: 'flex items-start gap-3 rounded-lg border border-border-light bg-surface/40 p-4 transition-colors hover:bg-surface/80',
    outlineBadge: 'flex size-6 shrink-0 items-center justify-center rounded-full bg-surface-tint text-xs font-medium text-brand-text tabular-nums',
    noScrollbar: '[scrollbar-width:none] [&::-webkit-scrollbar]:hidden',

    navBadge: 'pointer-events-none absolute -end-2 -top-1.5 h-4 min-w-4 rounded-full bg-danger px-1 text-center text-[11px] font-medium leading-4 text-white tabular-nums ring-2 ring-panel data-[tone=brand]:bg-brand data-[tone=info]:bg-brand data-[tone=warning]:bg-warning data-[tone=warning]:text-amber-950 data-[variant=dot]:h-2.5 data-[variant=dot]:min-w-2.5 data-[variant=dot]:px-0',
    navBadgeBottom: 'pointer-events-none absolute end-1.5 -top-0.5 h-4 min-w-4 rounded-full bg-danger px-1 text-center text-[11px] font-medium leading-4 text-white tabular-nums ring-2 ring-panel data-[tone=brand]:bg-brand data-[tone=info]:bg-brand data-[tone=warning]:bg-warning data-[tone=warning]:text-amber-950 data-[variant=dot]:h-2.5 data-[variant=dot]:min-w-2.5 data-[variant=dot]:px-0',
    navBadgeInline: 'ms-auto h-5 min-w-5 shrink-0 rounded-full bg-danger px-1.5 text-center text-[11px] font-medium leading-5 text-white tabular-nums data-[tone=brand]:bg-brand data-[tone=info]:bg-brand data-[tone=warning]:bg-warning data-[tone=warning]:text-amber-950 data-[variant=dot]:h-2 data-[variant=dot]:min-w-2 data-[variant=dot]:px-0',
    sidebarItem: 'group relative flex h-9 items-center gap-2.5 rounded-lg px-2.5 text-sm font-medium text-ink-muted transition-colors hover:bg-black/5 hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand aria-[current=page]:bg-panel aria-[current=page]:text-ink aria-[current=page]:shadow-xs aria-[current=page]:ring-1 aria-[current=page]:ring-border dark:hover:bg-white/5 dark:aria-[current=page]:shadow-none',
    sidebarItemIcon: 'flex shrink-0 text-ink-subtle transition-colors group-hover:text-ink-muted group-aria-[current=page]:text-ink',
    topBarCrumbs: 'hidden min-w-0 flex-1 items-center lg:flex',
    sidebarGroupLabel: 'px-2.5 pb-1 pt-4 text-xs font-medium text-ink-subtle',
    topNavLink: 'inline-flex min-h-9 shrink-0 cursor-pointer items-center gap-2 rounded-lg px-3 text-sm font-medium text-ink-muted transition-colors hover:bg-surface hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand aria-[current=page]:bg-surface aria-[current=page]:text-ink',
    bottomNavItem: 'group flex flex-1 flex-col items-center gap-1 rounded-lg px-1 py-1 text-[11px] font-medium text-ink-subtle transition-colors active:bg-surface aria-[current=page]:text-brand-text',
    bottomNavIcon: 'relative flex h-7 w-11 items-center justify-center rounded-lg transition-colors group-aria-[current=page]:bg-surface-tint group-aria-[current=page]:text-brand-text',
    bottomNavAction: 'flex h-8 w-11 cursor-pointer items-center justify-center rounded-lg bg-brand text-brand-ink shadow-xs transition-colors hover:bg-brand-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand active:bg-brand-pressed',

    // Deprecated: the round-2 decoration (accent bands, icon tiles beside
    // headings and metrics, tinted empty chips). Each now draws nothing or
    // the plain look, so a script not yet converted degrades instead of
    // breaking. Delete the elements that use them; these keys will go.
    pageHeroTabbed: 'mb-6',
    pageHeroBand: 'hidden',
    pageHeroLead: 'min-w-0',
    pageHeroIcon: 'hidden',
    metricIcon: 'hidden',
    metricIconBase: 'hidden',
    sectionIconBase: 'hidden',
    emptyIconBase: 'hidden',
});

export default ui;
