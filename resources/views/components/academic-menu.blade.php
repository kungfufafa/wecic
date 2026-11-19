{{--
    KRS & Perwalian · Nilai · Transkrip, above every academic page.
    `active` names the current one: krs, grades or transcript. `underline`
    draws the underlined tabs that sit in <x-page-hero>'s `tabs` slot
    (without the segmented bar's bottom margin). `accent` is ignored;
    delete it where you see it.
--}}
@props(['active', 'underline' => false, 'accent' => null])

@php
    $academicLinks = [
        'krs' => ['href' => route('krs.index'), 'label' => 'KRS & Perwalian'],
        'grades' => ['href' => route('grades.index'), 'label' => 'Nilai'],
        'transcript' => ['href' => route('transcript.index'), 'label' => 'Transkrip'],
    ];
@endphp

<nav {{ $attributes->class($underline ? '' : 'mb-4') }} aria-label="Menu akademik">
    <x-view-tabs :underline="$underline">
        @foreach ($academicLinks as $key => $link)
            <x-view-tab :href="$link['href']" :active="$active === $key">{{ $link['label'] }}</x-view-tab>
        @endforeach
    </x-view-tabs>
</nav>
