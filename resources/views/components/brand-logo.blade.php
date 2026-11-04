@props(['onDark' => false])

<img
    src="{{ asset('icons/wecic-logo.svg') }}"
    alt="WeCIC"
    width="880"
    height="256"
    {{ $attributes->class([
        'block w-auto shrink-0',
        'brightness-0 invert' => $onDark,
        'dark:brightness-0 dark:invert' => ! $onDark,
    ]) }}
>
