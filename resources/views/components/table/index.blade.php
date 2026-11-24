{{--
    A table in its bordered, scrolling wrapper (app.ui.tableWrap and
    app.ui.table). The attributes go on the <table>; `wrapClass` adds to the
    wrapper, and `flush` drops the wrapper's border for a table that fills a
    card. The rows use <x-table.th>, <x-table.td> and <x-table.tr>
    (app.ui.th, app.ui.td, app.ui.tr in scripts).
--}}
@props(['flush' => false, 'wrapClass' => null])

<div class="{{ trim(($flush ? 'overflow-x-auto' : 'overflow-x-auto rounded-xl border border-border bg-panel dark:bg-surface/40').' '.$wrapClass) }}">
    <table {{ $attributes->class('w-full caption-bottom text-sm') }}>
        {{ $slot }}
    </table>
</div>
