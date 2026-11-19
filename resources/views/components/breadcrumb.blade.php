{{--
    Where the page sits: "Akademik / KRS & Perwalian". `items` is a list of
    labels or ['label' => …, 'href' => …] arrays, the last being the page
    itself; without it the crumbs come from the current route
    (resources/views/components/data/sections.php). `size="md"` is the top
    bar's. Renders nothing when there is nothing to show. `icon` and
    `section` are ignored (the crumbs carry no icon); delete them where you
    see them.
--}}
@props(['items' => null, 'size' => 'sm', 'icon' => false, 'section' => null])

@php
    $shellMap = require resource_path('views/components/data/sections.php');
    $routeName = request()->route()?->getName();
    $routePage = $routeName !== null ? ($shellMap['pages'][$routeName] ?? null) : null;

    $crumbs = [];

    if (is_array($items)) {
        foreach ($items as $item) {
            $crumbs[] = is_array($item)
                ? ['label' => (string) ($item['label'] ?? $item[0] ?? ''), 'href' => $item['href'] ?? null]
                : ['label' => (string) $item, 'href' => null];
        }
    } elseif ($routePage !== null) {
        foreach ($routePage['crumbs'] as $crumb) {
            $crumbs[] = ['label' => $crumb[0], 'href' => isset($crumb[1]) ? route($crumb[1]) : null];
        }
    }

    $crumbs = array_values(array_filter($crumbs, static fn (array $crumb): bool => $crumb['label'] !== ''));
@endphp

@if ($crumbs !== [])
    <nav {{ $attributes->class($size === 'md' ? 'flex min-w-0 items-center text-sm text-ink-subtle' : 'flex min-w-0 items-center text-xs text-ink-subtle') }} aria-label="Lokasi halaman" data-slot="breadcrumb">
        <ol class="flex min-w-0 items-center gap-1.5">
            @foreach ($crumbs as $crumb)
                <li class="flex min-w-0 items-center gap-1.5">
                    @unless ($loop->first)
                        <span class="shrink-0 text-ink-subtle/60" aria-hidden="true">/</span>
                    @endunless
                    @if ($loop->last)
                        <span class="block truncate font-medium text-ink" aria-current="page">{{ $crumb['label'] }}</span>
                    @elseif ($crumb['href'] !== null)
                        <a href="{{ $crumb['href'] }}" class="-my-3.5 block truncate py-3.5 transition-colors hover:text-ink focus-visible:text-ink focus-visible:underline focus-visible:outline-none lg:my-0 lg:py-0">{{ $crumb['label'] }}</a>
                    @else
                        <span class="block truncate">{{ $crumb['label'] }}</span>
                    @endif
                </li>
            @endforeach
        </ol>
    </nav>
@endif
