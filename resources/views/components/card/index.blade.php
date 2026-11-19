{{--
    The card shell (app.ui.card): a border, no shadow. A <div>, or
    `as="section"`, `as="a"`… Inside a card, divide rows (<x-list>) or use a
    `bg-surface` well; never nest another bordered card.
    `interactive` gives it the hover and focus of a clickable card
    (app.ui.cardInteractive, display block). `title` adds the usual header
    with an h2 (`titleId` labels it), `subtitle` a line under it and the
    `actions` slot whatever sits on the right of that header. A header with
    more in it is a <x-card.header>. The content goes in <x-card.body>, or
    straight into the slot for a card without padding (a table, a list).
--}}
@props(['as' => 'div', 'title' => null, 'titleId' => null, 'subtitle' => null, 'interactive' => false])

<{{ $as }} {{ $attributes->class($interactive ? 'block cursor-pointer rounded-xl border border-border bg-panel transition-colors hover:bg-surface/50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40' : 'rounded-xl border border-border bg-panel') }} data-slot="card">
    @if (filled($title))
        <div class="flex items-center justify-between gap-3 border-b border-border-light px-4 py-3.5 sm:px-5 sm:py-4" data-slot="card-header">
            @if (filled($subtitle))
                <div class="min-w-0">
                    <h2 class="text-sm font-semibold tracking-tight text-ink" @if ($titleId) id="{{ $titleId }}" @endif>{{ $title }}</h2>
                    <p class="mt-0.5 text-[13px] text-ink-muted">{{ $subtitle }}</p>
                </div>
            @else
                <h2 class="text-sm font-semibold tracking-tight text-ink" @if ($titleId) id="{{ $titleId }}" @endif>{{ $title }}</h2>
            @endif
            {{ $actions ?? '' }}
        </div>
    @endif
    {{ $slot }}
</{{ $as }}>
