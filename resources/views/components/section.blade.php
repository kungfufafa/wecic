{{--
    A titled section of a page (h2 in app.ui.sectionTitle), labelled by its
    heading. Pages space sections with `flex flex-col gap-6` (or space-y-6).
    The heading is text only.

    - With an `aside` slot the heading shares a row with it (a date, a
      button); the slot's attributes style that row.
    - Otherwise `count` (a number, or a named slot with the attributes its
      script fills), `description` and `href` with `linkLabel` ("Lihat
      semua") draw the heading row (app.ui.sectionHeading): the title with
      its count as muted text after a dot ("Tugas · 14"), the description
      under it, and the link, muted, on the right. An empty count hides,
      dot and all.
    - `headingClass` spaces the heading (or that row): mb-3 by default.
    - `icon` and `accent` are ignored (headings carry no icon tile); delete
      them where you see them.
--}}
@props(['title', 'titleId', 'headingClass' => 'mb-3', 'count' => null, 'description' => null, 'href' => null, 'linkLabel' => 'Lihat semua', 'icon' => null, 'accent' => null])

@php
    $hasCount = $count instanceof \Illuminate\View\ComponentSlot || ($count !== null && $count !== '');
    $hasHeadingRow = $hasCount || filled($description) || $href !== null;
@endphp

<section {{ $attributes }} aria-labelledby="{{ $titleId }}">
    @isset($aside)
        <div {{ $aside->attributes }}>
            <h2 class="{{ trim('text-sm font-semibold text-ink '.$headingClass) }}" id="{{ $titleId }}">{{ $title }}</h2>
            {{ $aside }}
        </div>
    @elseif ($hasHeadingRow)
        <div class="{{ trim('flex items-end justify-between gap-3 '.$headingClass) }}" data-slot="section-heading">
            <div class="min-w-0">
                <div class="flex min-w-0 items-baseline gap-1.5">
                    <h2 class="text-sm font-semibold text-ink" id="{{ $titleId }}">{{ $title }}</h2>
                    @if ($count instanceof \Illuminate\View\ComponentSlot)
                        <span {{ $count->attributes->class("text-sm text-ink-muted tabular-nums before:me-1.5 before:content-['·'] empty:hidden") }} data-slot="section-count">{{ $count }}</span>
                    @elseif ($hasCount)
                        <span class="text-sm text-ink-muted tabular-nums before:me-1.5 before:content-['·'] empty:hidden" data-slot="section-count">{{ $count }}</span>
                    @endif
                </div>
                @if (filled($description))
                    <p class="mt-0.5 text-[13px] text-ink-muted">{{ $description }}</p>
                @endif
            </div>
            @if ($href !== null)
                <a href="{{ $href }}" class="inline-flex min-h-11 shrink-0 items-center gap-1 rounded-sm text-[13px] font-medium text-ink-muted transition-colors hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40 sm:min-h-0">
                    {{ $linkLabel }}
                    <x-icon name="chevron-right" size="sm" />
                </a>
            @endif
        </div>
    @else
        <h2 class="{{ trim('text-sm font-semibold text-ink '.$headingClass) }}" id="{{ $titleId }}">{{ $title }}</h2>
    @endisset
    {{ $slot }}
</section>
