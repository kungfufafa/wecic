{{--
    A titled section of a page (h2 in .mycic-section-title), labelled by its
    heading. With an `aside` slot the heading shares a row with it (a count,
    a date, a "Lihat semua" link); the slot's attributes style that row.
    `headingClass` spaces the heading: mb-3 on its own by default.
--}}
@props(['title', 'titleId', 'headingClass' => 'mb-3'])

<section {{ $attributes }} aria-labelledby="{{ $titleId }}">
    @isset($aside)
        <div {{ $aside->attributes }}>
            <h2 class="{{ trim('mycic-section-title '.$headingClass) }}" id="{{ $titleId }}">{{ $title }}</h2>
            {{ $aside }}
        </div>
    @else
        <h2 class="{{ trim('mycic-section-title '.$headingClass) }}" id="{{ $titleId }}">{{ $title }}</h2>
    @endisset
    {{ $slot }}
</section>
