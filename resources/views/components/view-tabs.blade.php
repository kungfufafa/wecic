{{--
    The bar <x-view-tab>s sit in: a <ul> of page links by default, or
    `as="div"` for a role="tablist" of buttons that switch panels on the
    page. The segmented look (app.ui.tabs) by default; `underline` draws
    the underlined section tabs instead (app.ui.underlineTabs: the baseline
    is an inset shadow, so the active tab's 2px underline and its focus
    ring stay inside the scroll box); the active tab's underline is ink, not
    a colour. `accent` is ignored; delete it where you see it.
--}}
@props(['as' => 'ul', 'underline' => false, 'accent' => null])

<{{ $as }} {{ $attributes->class($underline ? 'flex max-w-full gap-4 overflow-x-auto shadow-[inset_0_-1px_0_var(--color-border)]' : 'inline-flex max-w-full items-center gap-1 overflow-x-auto rounded-lg bg-surface p-1') }}>
    {{ $slot }}
</{{ $as }}>
