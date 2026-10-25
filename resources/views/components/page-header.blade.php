{{--
    The header every page opens with: its h1 and, optionally, a subtitle.
    `subtitle` is a string, or a named slot when the paragraph needs its own
    attributes (`<x-slot:subtitle id="…">`). The default slot follows the
    subtitle: the page's "Diperbarui …" line, an action next to the title.
--}}
@props(['title', 'subtitle' => null])

<header {{ $attributes->class('mb-4') }}>
    <h1 class="mycic-page-heading">{{ $title }}</h1>
    @if ($subtitle instanceof \Illuminate\View\ComponentSlot)
        <p {{ $subtitle->attributes->class('mycic-page-subheading') }}>{{ $subtitle }}</p>
    @elseif (filled($subtitle))
        <p class="mycic-page-subheading">{{ $subtitle }}</p>
    @endif
    {{ $slot }}
</header>
