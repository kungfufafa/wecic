{{--
    A row of a list whose parent divides its rows (`divide-y`): the label on
    the left, a value or an action on the right, no padding above the first
    row or below the last. Rows a page's script renders use .mycic-list-row.
--}}
<div {{ $attributes->class('flex items-center justify-between gap-3 py-3 first:pt-0 last:pb-0') }}>
    {{ $slot }}
</div>
