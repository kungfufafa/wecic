{{--
    One tab of a <x-view-tabs> bar. With `href` it is a link to another page
    (an <li>, `aria-current` on the active one); without, a role="tab"
    button (data-view-tab) whose panel the page's script shows. The look
    follows aria-selected / aria-current (app.ui.tab), so a script switching
    tabs only sets aria-selected. An underlined tab (app.ui.underlineTab)
    marks the active one with an ink-coloured 2px underline.
--}}
@aware(['underline' => false])
@props(['href' => null, 'active' => false])

@php
    $tabClass = $underline ? 'inline-flex min-h-11 shrink-0 cursor-pointer select-none items-center gap-1.5 whitespace-nowrap border-b-2 border-transparent px-1 text-sm font-medium text-ink-muted transition-colors hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40 focus-visible:ring-inset aria-selected:border-ink aria-selected:text-ink aria-pressed:border-ink aria-pressed:text-ink aria-[current=page]:border-ink aria-[current=page]:text-ink' : 'inline-flex h-11 shrink-0 cursor-pointer select-none items-center justify-center gap-1.5 whitespace-nowrap rounded-full px-3.5 text-sm font-medium text-ink-muted transition-colors hover:bg-black/[0.04] hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40 aria-selected:bg-black/[0.06] aria-selected:text-ink aria-pressed:bg-black/[0.06] aria-pressed:text-ink aria-[current=page]:bg-black/[0.06] aria-[current=page]:text-ink sm:h-8 dark:hover:bg-white/[0.05] dark:aria-selected:bg-white/[0.08] dark:aria-pressed:bg-white/[0.08] dark:aria-[current=page]:bg-white/[0.08]';
@endphp

@if ($href !== null)
    <li class="shrink-0">
        <a
            href="{{ $href }}"
            {{ $attributes->class($tabClass) }}
            @if ($active) aria-current="page" @endif
        >{{ $slot }}</a>
    </li>
@else
    <button
        type="button"
        {{ $attributes->class($tabClass) }}
        role="tab"
        aria-selected="{{ $active ? 'true' : 'false' }}"
        data-view-tab
    >{{ $slot }}</button>
@endif
