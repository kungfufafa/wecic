{{-- A header cell (app.ui.th); scope="col" unless the page says otherwise. --}}
<th {{ $attributes->merge(['scope' => 'col'])->class('h-10 whitespace-nowrap border-b border-border bg-surface/40 px-4 text-start text-xs font-medium text-ink-muted') }}>{{ $slot }}</th>
