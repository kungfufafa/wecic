{{--
    The segmented bar view tabs sit in (<x-view-tab>): a <ul> of page links
    by default, or `as="div"` for a role="tablist" of buttons that switch
    panels on the page.
--}}
@props(['as' => 'ul'])

<{{ $as }} {{ $attributes->class('mycic-segmented') }}>
    {{ $slot }}
</{{ $as }}>
