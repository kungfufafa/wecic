{{--
    A row of key numbers (app.ui.statGrid): ONE bordered box whose
    <x-metric> cells are split by 1px dividers. The dividers are the border
    colour showing through a 1px gap, so they line up however the cells
    wrap; a page fills every row (or spans the last cell) so no empty gray
    corner shows. The page sets the columns: class="grid-cols-3",
    "grid-cols-2 sm:grid-cols-4"… `kpi` is the strip right under a page's
    header: two columns, four from lg (app.ui.statGridKpi).
--}}
@props(['kpi' => false])

<div {{ $attributes->class($kpi ? 'grid grid-cols-2 gap-px overflow-hidden rounded-xl border border-border bg-border lg:grid-cols-4' : 'grid gap-px overflow-hidden rounded-xl border border-border bg-border') }} data-slot="stat-strip">
    {{ $slot }}
</div>
