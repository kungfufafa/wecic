{{--
    A page's main action bar: floating above the bottom navigation on a
    phone, in the flow from lg up (app.ui.stickyAction). data-sticky-action
    makes the shell leave room for it (resources/css/app.css).
--}}
<div {{ $attributes->class('fixed inset-x-3 bottom-[calc(4.75rem+env(safe-area-inset-bottom,0px))] z-30 rounded-xl border border-border bg-panel p-2.5 shadow-float lg:static lg:rounded-none lg:border-0 lg:bg-transparent lg:p-0 lg:shadow-none') }} data-sticky-action>
    {{ $slot }}
</div>
