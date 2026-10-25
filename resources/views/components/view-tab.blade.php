{{--
    One tab of a <x-view-tabs> bar. With `href` it is a link to another page
    (an <li>, `aria-current` on the active one); without, a role="tab"
    button whose panel the page's script shows. The button's two class
    strings are the ones resources/views/classes/index.blade.php's
    switchView() writes, so keep them in step.
--}}
@props(['href' => null, 'active' => false])

@if ($href !== null)
    <li class="shrink-0">
        <a
            href="{{ $href }}"
            {{ $attributes->class([
                'inline-flex min-h-11 items-center whitespace-nowrap sm:min-h-10 rounded-lg px-3 py-2 text-xs font-semibold transition-all focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand sm:text-sm',
                'border border-border/40 bg-panel font-bold text-brand shadow-xs' => $active,
                'text-ink-muted hover:text-ink' => ! $active,
            ]) }}
            @if ($active) aria-current="page" @endif
        >{{ $slot }}</a>
    </li>
@else
    <button
        type="button"
        {{ $attributes->class($active
            ? 'mycic-view-tab inline-flex items-center gap-1.5 min-h-10 whitespace-nowrap px-3 py-2 text-xs sm:text-sm font-bold rounded-lg transition-all bg-panel text-brand shadow-xs border border-border/40'
            : 'mycic-view-tab inline-flex items-center gap-1.5 min-h-10 whitespace-nowrap px-3 py-2 text-xs sm:text-sm font-semibold rounded-lg transition-all text-ink-muted hover:text-ink') }}
        role="tab"
        aria-selected="{{ $active ? 'true' : 'false' }}"
    >{{ $slot }}</button>
@endif
