{{--
    A metric tile (.mycic-metric): a label, a value and an optional note.
    The value and the note are named slots, so a page can give them the id
    or data attribute its script fills (`<x-slot:value id="quick-nim">`).
--}}
@props(['label'])

<div {{ $attributes->class('mycic-metric') }}>
    <div class="mycic-metric-label">{{ $label }}</div>
    <div {{ $value->attributes->class('mycic-metric-value') }}>{{ $value }}</div>
    @isset($note)
        <div {{ $note->attributes->class('mycic-metric-note') }}>{{ $note }}</div>
    @endisset
</div>
