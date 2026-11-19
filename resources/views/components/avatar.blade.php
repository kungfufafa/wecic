{{--
    A round, gray initial (app.ui.avatar) or, with `size="lg"`, the large
    round profile picture (app.ui.avatarLg). The slot is the initial or an <img>.
--}}
@props(['size' => 'md'])

<span {{ $attributes->class($size === 'lg' ? 'inline-flex size-16 shrink-0 items-center justify-center overflow-hidden rounded-full border border-border bg-surface text-xl font-medium text-ink-muted [&_img]:size-full [&_img]:object-cover' : 'inline-flex size-8 shrink-0 items-center justify-center overflow-hidden rounded-full bg-surface text-xs font-medium text-ink-muted ring-1 ring-border') }}>{{ $slot }}</span>
