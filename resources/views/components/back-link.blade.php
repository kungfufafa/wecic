{{-- A quiet "← Kembali" link above a page title (app.ui.backLink). The slot is its label. --}}
@props(['href'])

<a href="{{ $href }}" {{ $attributes->class('-ms-2 inline-flex min-h-11 cursor-pointer items-center gap-1 rounded-lg px-2 text-sm font-medium text-ink-muted transition-colors hover:bg-surface hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40 sm:min-h-9') }}>
    <svg xmlns="http://www.w3.org/2000/svg" class="size-4 shrink-0" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 6l-6 6l6 6" /></svg>
    <span>{{ $slot }}</span>
</a>
