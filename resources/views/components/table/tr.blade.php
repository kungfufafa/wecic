{{-- A body row (app.ui.tr): hover tint, no border under the last row. --}}
<tr {{ $attributes->class('transition-colors hover:bg-surface/50 [&:last-child>td]:border-b-0') }}>{{ $slot }}</tr>
