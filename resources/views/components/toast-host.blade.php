{{--
    Where app.showNotice() puts its toasts (#notice-host): on the right side,
    above the bottom navigation on a phone and at the bottom corner from lg up.
    `guest` is the one for pages without the bottom navigation (app.ui.toastHostGuest).
--}}
@props(['guest' => false])

<div id="notice-host" {{ $attributes->class($guest ? 'pointer-events-none fixed inset-x-0 bottom-[calc(1rem+env(safe-area-inset-bottom,0px))] z-[60] flex flex-col items-end gap-2.5 px-4 sm:inset-x-auto sm:end-4 sm:w-full sm:max-w-sm sm:px-0' : 'pointer-events-none fixed inset-x-0 bottom-[calc(4.75rem+env(safe-area-inset-bottom,0px))] z-[60] flex flex-col items-end gap-2.5 px-4 sm:inset-x-auto sm:end-4 sm:w-full sm:max-w-sm sm:px-0 lg:bottom-[calc(1rem+env(safe-area-inset-bottom,0px))]') }}></div>
