{{-- A dialog's ✕ button (app.ui.modalClose), labelled "Tutup" unless the page says otherwise. --}}
<button {{ $attributes->merge(['type' => 'button', 'aria-label' => 'Tutup'])->class('inline-flex size-9 shrink-0 cursor-pointer items-center justify-center rounded-lg text-ink-subtle transition-colors hover:bg-surface hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40') }}>
    <svg xmlns="http://www.w3.org/2000/svg" class="size-[18px]" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6l-12 12" /><path d="M6 6l12 12" /></svg>
</button>
