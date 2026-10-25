{{--
    The segmented bar view tabs sit in (<x-view-tab>): a <ul> of page links
    by default, or `as="div"` for a role="tablist" of buttons that switch
    panels on the page.
--}}
@props(['as' => 'ul'])

<{{ $as }} {{ $attributes->class('inline-flex max-w-full items-center gap-1 overflow-x-auto rounded-xl border border-border/40 bg-surface p-1') }}>
    {{ $slot }}
</{{ $as }}>
