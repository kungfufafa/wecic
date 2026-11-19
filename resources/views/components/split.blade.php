{{--
    Two columns: the page's main content and an aside beside it from `at`
    (lg by default, or xl) up, stacked below it. The aside (a named slot
    whose attributes go on the <aside>) is 20 rem wide (22 rem from xl) and
    sticks under the top bar while the main column scrolls, unless `sticky`
    is false. The main column stacks its children with gap-8.
    Scripts: app.ui.split / splitXl, splitMain, splitAside / splitAsideXl.
--}}
@props(['at' => 'lg', 'sticky' => true])

<div {{ $attributes->class($at === 'xl' ? 'grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem] xl:items-start xl:gap-8' : 'grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem] lg:items-start xl:grid-cols-[minmax(0,1fr)_22rem] xl:gap-8') }} data-slot="split">
    <div class="flex min-w-0 flex-col gap-8" data-slot="split-main">
        {{ $slot }}
    </div>
    @isset($aside)
        <aside {{ $aside->attributes->class(! $sticky ? 'flex min-w-0 flex-col gap-6' : ($at === 'xl' ? 'flex min-w-0 flex-col gap-6 xl:sticky xl:top-20' : 'flex min-w-0 flex-col gap-6 lg:sticky lg:top-20')) }} data-slot="split-aside">
            {{ $aside }}
        </aside>
    @endisset
</div>
