{{--
    Where app.showNotice() puts its toasts (#notice-host): above the bottom
    navigation on a phone, at the top from lg up. `guest` is the one for
    pages without the bottom navigation (app.ui.toastHostGuest).
--}}
@props(['guest' => false])

<div id="notice-host" {{ $attributes->class($guest ? 'pointer-events-none fixed inset-x-0 bottom-[calc(1rem+env(safe-area-inset-bottom,0px))] z-[60] flex flex-col items-center gap-2 px-4 md:top-4 md:bottom-auto' : 'pointer-events-none fixed inset-x-0 bottom-[calc(4.75rem+env(safe-area-inset-bottom,0px))] z-[60] flex flex-col items-center gap-2 px-4 lg:top-4 lg:bottom-auto') }}></div>
