{{--
    A row of a list whose parent divides its rows (`divide-y`).

    - By default (app.ui.listRowDivided): the label on the left, a value or
      an action on the right, no padding above the first row or below the
      last; for a list inside a padded <x-card.body>.
    - `flush` (app.ui.listRowFlush): a full-width row in a card without body
      padding, inside an <x-list>. A `leading` slot (e.g. an <x-icon-tile>)
      sits first, the default slot fills the middle
      (app.ui.listRowTitle / listRowSubtitle for its lines), and a
      `trailing` slot holds the right-aligned meta. With `href` the row is a
      link with a hover tint (app.ui.listRowFlushInteractive) and, unless
      `chevron` is false, a chevron at the end.

    Rows a page's script renders without a divided parent use app.ui.listRow.
--}}
@props(['flush' => false, 'href' => null, 'chevron' => true])

@if (! $flush && $href === null)
    <div {{ $attributes->class('flex items-center justify-between gap-3 py-3 first:pt-0 last:pb-0') }}>
        {{ $slot }}
    </div>
@else
    <{{ $href !== null ? 'a' : 'div' }} @if ($href !== null) href="{{ $href }}" @endif {{ $attributes->class($href !== null ? 'flex min-h-11 cursor-pointer items-center gap-3 px-4 py-3.5 transition-colors hover:bg-surface/60 focus-visible:bg-surface/60 focus-visible:outline-none sm:px-5' : 'flex items-center gap-3 px-4 py-3.5 sm:px-5') }} data-slot="list-row">
        {{ $leading ?? '' }}
        <div class="min-w-0 flex-1">{{ $slot }}</div>
        @isset($trailing)
            <div {{ $trailing->attributes->class('flex shrink-0 flex-col items-end gap-1 text-end text-xs text-ink-subtle') }}>{{ $trailing }}</div>
        @endisset
        @if ($href !== null && $chevron)
            <x-icon name="chevron-right" size="sm" class="text-ink-subtle" />
        @endif
    </{{ $href !== null ? 'a' : 'div' }}>
@endif
