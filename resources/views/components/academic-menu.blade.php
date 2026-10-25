{{--
    KRS & Perwalian · Nilai · Transkrip, above every academic page.
    `active` names the current one: krs, grades or transcript.
--}}
@props(['active'])

@php
    $academicLinks = [
        'krs' => ['href' => route('krs.index'), 'label' => 'KRS & Perwalian'],
        'grades' => ['href' => route('grades.index'), 'label' => 'Nilai'],
        'transcript' => ['href' => route('transcript.index'), 'label' => 'Transkrip'],
    ];
@endphp

<nav {{ $attributes->class('mb-4') }} aria-label="Menu akademik">
    <x-view-tabs>
        @foreach ($academicLinks as $key => $link)
            <x-view-tab :href="$link['href']" :active="$active === $key">{{ $link['label'] }}</x-view-tab>
        @endforeach
    </x-view-tabs>
</nav>
