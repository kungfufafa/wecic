{{--
    The card shell (.mycic-card): a <div>, or `as="section"`. `title` adds
    the usual header with an h2 (`titleId` labels it); a header with more in
    it is a <x-card.header>. The content goes in <x-card.body>, or straight
    into the slot for a card without padding (a table, a scroller).
--}}
@props(['as' => 'div', 'title' => null, 'titleId' => null])

<{{ $as }} {{ $attributes->class('mycic-card') }}>
    @if (filled($title))
        <div class="mycic-card-header">
            <h2 class="mycic-card-title" @if ($titleId) id="{{ $titleId }}" @endif>{{ $title }}</h2>
        </div>
    @endif
    {{ $slot }}
</{{ $as }}>
