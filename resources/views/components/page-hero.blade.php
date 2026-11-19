{{--
    The plain header a main page opens with (app.ui.pageHero…): the h1 and
    its muted subtitle on the left, the `actions` slot (buttons) on the
    right (under the text on a phone), a `meta` line of small muted facts,
    and an underlined tab bar under it all. No band, no icon, no breadcrumb:
    the top bar shows where the page sits.

    - `title` is a string, or a named slot when the h1 needs its own id or
      data-* (`<x-slot:title id="…">`); `subtitle` likewise.
    - `breadcrumb` (a list of labels or ['label' => …, 'href' => …]) fills
      the top bar's breadcrumb unless the page sets @section('breadcrumb');
      without it the top bar shows the route's.
    - `back` (a URL, with `backLabel`) puts a back link above the title, as
      detail pages do.
    - Slots: the default slot follows the subtitle, `meta` is the row of
      small facts under it ("Diperbarui …", one badge), and `tabs` the
      <x-view-tabs underline> or <x-academic-menu underline> under the header.
    - The back link never prints; the title, subtitle and meta do (a page
      hides its tabs with <x-slot:tabs class="print:hidden">).
    - `accent` and `icon` are ignored (kept so older call sites still render);
      delete them where you see them.
--}}
@props(['title' => null, 'subtitle' => null, 'accent' => null, 'icon' => null, 'breadcrumb' => null, 'back' => null, 'backLabel' => 'Kembali'])

@php
    if (is_array($breadcrumb) && $breadcrumb !== [] && ! $__env->hasSection('breadcrumb')) {
        $__env->startSection('breadcrumb', new \Illuminate\Support\HtmlString(\Illuminate\Support\Facades\Blade::render(
            '<x-breadcrumb :items="$items" size="md" />',
            ['items' => $breadcrumb],
        )));
    }
@endphp

<header {{ $attributes->class('mb-6') }} data-slot="page-hero">
    @if ($back !== null)
        <x-back-link :href="$back" class="mb-2 print:hidden">{{ $backLabel }}</x-back-link>
    @endif

    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            @if ($title instanceof \Illuminate\View\ComponentSlot)
                <h1 {{ $title->attributes->class('text-2xl font-semibold tracking-tight text-ink') }}>{{ $title }}</h1>
            @else
                <h1 class="text-2xl font-semibold tracking-tight text-ink">{{ $title }}</h1>
            @endif
            @if ($subtitle instanceof \Illuminate\View\ComponentSlot)
                <p {{ $subtitle->attributes->class('mt-1 text-sm text-ink-muted') }}>{{ $subtitle }}</p>
            @elseif (filled($subtitle))
                <p class="mt-1 text-sm text-ink-muted">{{ $subtitle }}</p>
            @endif
            {{ $slot }}
            @isset($meta)
                <div {{ $meta->attributes->class('mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-ink-muted') }} data-slot="page-hero-meta">{{ $meta }}</div>
            @endisset
        </div>
        @isset($actions)
            <div {{ $actions->attributes->class('flex shrink-0 flex-wrap items-center gap-2') }} data-slot="page-hero-actions">{{ $actions }}</div>
        @endisset
    </div>

    @isset($tabs)
        <div {{ $tabs->attributes->class('mt-4') }} data-slot="page-hero-tabs">{{ $tabs }}</div>
    @endisset
</header>
