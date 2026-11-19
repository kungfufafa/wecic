{{--
    The header every page opens with: its h1 and, optionally, a subtitle.
    `subtitle` is a string, or a named slot when the paragraph needs its own
    attributes (`<x-slot:subtitle id="…">`). `eyebrow` is a small line above
    the title, `back` (a URL, with `backLabel`) a back link above it all,
    and the `actions` slot buttons on the right of the title (under it on a
    phone). The default slot follows the subtitle: the page's "Diperbarui …"
    line, badges.
--}}
@props(['title', 'subtitle' => null, 'eyebrow' => null, 'back' => null, 'backLabel' => 'Kembali'])

<header {{ $attributes->class('mb-6') }}>
    @if ($back !== null)
        <x-back-link :href="$back" class="mb-2">{{ $backLabel }}</x-back-link>
    @endif
    @isset($actions)
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                @if (filled($eyebrow))
                    <p class="mb-1 text-[13px] font-medium text-ink-subtle">{{ $eyebrow }}</p>
                @endif
                <h1 class="text-2xl font-semibold tracking-tight text-ink">{{ $title }}</h1>
                @if ($subtitle instanceof \Illuminate\View\ComponentSlot)
                    <p {{ $subtitle->attributes->class('mt-1 text-sm text-ink-muted') }}>{{ $subtitle }}</p>
                @elseif (filled($subtitle))
                    <p class="mt-1 text-sm text-ink-muted">{{ $subtitle }}</p>
                @endif
                {{ $slot }}
            </div>
            <div {{ $actions->attributes->class('flex shrink-0 flex-wrap items-center gap-2') }}>{{ $actions }}</div>
        </div>
    @else
        @if (filled($eyebrow))
            <p class="mb-1 text-[13px] font-medium text-ink-subtle">{{ $eyebrow }}</p>
        @endif
        <h1 class="text-2xl font-semibold tracking-tight text-ink">{{ $title }}</h1>
        @if ($subtitle instanceof \Illuminate\View\ComponentSlot)
            <p {{ $subtitle->attributes->class('mt-1 text-sm text-ink-muted') }}>{{ $subtitle }}</p>
        @elseif (filled($subtitle))
            <p class="mt-1 text-sm text-ink-muted">{{ $subtitle }}</p>
        @endif
        {{ $slot }}
    @endisset
</header>
