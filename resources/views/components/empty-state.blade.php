{{--
    An empty or loading box (.mycic-empty), as app.renderEmptyState() draws
    it from a script: a title, an optional subtitle, and anything else (a
    button) in the slot.
--}}
@props(['title', 'subtitle' => null, 'titleClass' => null])

<div {{ $attributes->class('mycic-empty') }}>
    <p class="{{ trim('mycic-empty-title '.$titleClass) }}">{{ $title }}</p>
    @if (filled($subtitle))
        <p class="mycic-empty-subtitle">{{ $subtitle }}</p>
    @endif
    {{ $slot }}
</div>
