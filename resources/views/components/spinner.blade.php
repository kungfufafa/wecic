{{--
    A small spinning ring in the current text colour (app.ui.spinner). It is
    a block box, so a script can hide it with `hidden`; put it in a flex row
    next to text.
--}}
<span {{ $attributes->merge(['aria-hidden' => 'true'])->class('block size-4 shrink-0 animate-spin rounded-full border-2 border-current border-t-transparent') }}></span>
