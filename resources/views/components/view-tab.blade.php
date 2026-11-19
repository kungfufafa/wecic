{{--
    One tab of a <x-view-tabs> bar. With `href` it is a link to another page
    (an <li>, `aria-current` on the active one); without, a role="tab"
    button whose panel the page's script shows. The button's two class
    strings are the ones resources/views/classes/index/scripts/view-modes.blade.php's
    switchView() writes, so keep them in step.
--}}
@props(['href' => null, 'active' => false])

@if ($href !== null)
    <li class="shrink-0">
        <a
            href="{{ $href }}"
            {{ $attributes->class([
                'mycic-segmented-item',
                'mycic-segmented-item-active' => $active,
            ]) }}
            @if ($active) aria-current="page" @endif
        >{{ $slot }}</a>
    </li>
@else
    <button
        type="button"
        {{ $attributes->class($active
            ? 'mycic-view-tab mycic-segmented-item mycic-segmented-item-active'
            : 'mycic-view-tab mycic-segmented-item') }}
        role="tab"
        aria-selected="{{ $active ? 'true' : 'false' }}"
    >{{ $slot }}</button>
@endif
