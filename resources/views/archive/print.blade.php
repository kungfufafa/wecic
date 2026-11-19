@extends('layouts.app')

@section('title', 'Bukti arsip '.$label)
@section('page_id', 'archive-print')
@section('hide_status_strip', '1')

@php
    $statusLabels = [
        'trusted' => 'Terpercaya',
        'suspect' => 'Janggal (ditahan)',
        'rejected' => 'Ditolak mahasiswa',
        'dismissed' => 'Sempat janggal',
    ];
    $userInfo = is_array(session('campus_user_info')) ? session('campus_user_info') : [];
    $studentName = $userInfo['name'] ?? $userInfo['nama'] ?? null;
    $studentId = $userInfo['student_id'] ?? $userInfo['nim'] ?? $userInfo['username'] ?? null;
    $formatMoment = static fn ($moment): string => $moment ? $moment->copy()->timezone(config('app.timezone'))->locale('id')->translatedFormat('j F Y, H:i') : '–';
    $rowClass = 'break-inside-avoid [&:last-child>td]:border-b-0';
    $cellClass = 'border-b border-border-light px-4 py-2.5 text-sm';
@endphp

@section('content')
    <article class="mx-auto flex max-w-3xl flex-col gap-6">
        <x-page-header eyebrow="Bukti arsip WeCIC" :title="$label.($version->subject ? ' · '.$version->subject : '')" subtitle="Salinan data yang ditampilkan portal kampus kepada mahasiswa, disimpan otomatis oleh WeCIC." class="mb-0!">
            <x-slot:actions class="print:hidden">
                <x-button onclick="window.print()">
                    <svg xmlns="http://www.w3.org/2000/svg" class="size-4 shrink-0" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 17h2a2 2 0 0 0 2 -2v-4a2 2 0 0 0 -2 -2h-14a2 2 0 0 0 -2 2v4a2 2 0 0 0 2 2h2" /><path d="M17 9v-4a2 2 0 0 0 -2 -2h-6a2 2 0 0 0 -2 2v4" /><path d="M7 15a2 2 0 0 1 2 -2h6a2 2 0 0 1 2 2v4a2 2 0 0 1 -2 2h-6a2 2 0 0 1 -2 -2z" /></svg>
                    Cetak / simpan PDF
                </x-button>
            </x-slot:actions>
        </x-page-header>

        <x-card as="section" class="break-inside-avoid">
            <x-card.body>
                <dl class="grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-2 print:grid-cols-2">
                    @if ($studentName)
                        <div><dt class="text-xs text-ink-subtle">Nama</dt><dd class="font-medium text-ink">{{ $studentName }}</dd></div>
                    @endif
                    @if ($studentId)
                        <div><dt class="text-xs text-ink-subtle">NIM</dt><dd class="font-mono font-medium text-ink">{{ $studentId }}</dd></div>
                    @endif
                    <div><dt class="text-xs text-ink-subtle">Status versi</dt><dd class="font-medium text-ink">{{ $statusLabels[$version->status] ?? $version->status }}</dd></div>
                    <div><dt class="text-xs text-ink-subtle">Terlihat</dt><dd class="font-medium text-ink tabular-nums">{{ $version->seen_count }} kali sinkronisasi</dd></div>
                    <div><dt class="text-xs text-ink-subtle">Pertama terlihat di portal</dt><dd class="font-medium text-ink tabular-nums">{{ $formatMoment($version->first_seen_at) }}</dd></div>
                    <div><dt class="text-xs text-ink-subtle">Terakhir terlihat di portal</dt><dd class="font-medium text-ink tabular-nums">{{ $formatMoment($version->last_seen_at) }}</dd></div>
                    <div><dt class="text-xs text-ink-subtle">Dicetak</dt><dd class="font-medium text-ink tabular-nums">{{ $formatMoment(now()) }}</dd></div>
                    <div><dt class="text-xs text-ink-subtle">Kode versi</dt><dd class="break-all font-mono text-xs text-ink">#{{ $version->id }} · {{ substr($version->content_hash, 0, 16) }}</dd></div>
                </dl>
            </x-card.body>
        </x-card>

        @if (! empty($version->findings))
            <x-alert tone="warning" icon class="break-inside-avoid">
                <h2 class="font-semibold text-ink">Perubahan janggal yang terdeteksi</h2>
                <ul class="mt-1 flex list-disc flex-col gap-1 ps-5">
                    @foreach ($version->findings as $finding)
                        <li>{{ $finding['message'] ?? '' }}</li>
                    @endforeach
                </ul>
            </x-alert>
        @endif

        @if ($baseline)
            <x-section :title="'Perbedaan dengan versi '.$formatMoment($baseline->first_seen_at)" title-id="archive-print-diff-title" class="flex flex-col gap-3" heading-class="">
                @if ($diff['changes'] === [])
                    <p class="text-sm text-ink-subtle">Tidak ada perbedaan isi.</p>
                @else
                    @if ($diff['truncated'])
                        <p class="text-sm text-ink-muted" data-diff-truncated>Menampilkan {{ count($diff['changes']) }} dari {{ $diff['total'] }} perubahan. Isi lengkap versi ini ada di bagian berikutnya dan di berkas JSON arsip.</p>
                    @endif
                    <x-table wrap-class="print:overflow-visible">
                        <thead>
                            <tr>
                                <x-table.th>Bagian</x-table.th>
                                <x-table.th>Sebelumnya</x-table.th>
                                <x-table.th>Versi ini</x-table.th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($diff['changes'] as $row)
                                <tr class="{{ $rowClass }}">
                                    <td class="{{ $cellClass }} align-top">{{ $row['path'] }}</td>
                                    <td class="{{ $cellClass }} align-top">{{ $row['old'] ?? '–' }}</td>
                                    <td class="{{ $cellClass }} align-top">{{ $row['new'] ?? '–' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </x-table>
                @endif
            </x-section>
        @endif

        <x-section title="Isi lengkap versi ini" title-id="archive-print-entries-title" class="flex flex-col gap-3" heading-class="">
            <x-table wrap-class="print:overflow-visible">
                <tbody>
                    @foreach ($entries['changes'] as $entry)
                        <tr class="{{ $rowClass }}">
                            <td class="{{ $cellClass }} w-1/2 align-top text-ink-subtle">{{ $entry['path'] }}</td>
                            <td class="{{ $cellClass }} align-top break-words">{{ $entry['new'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </x-table>
        </x-section>

        <p class="text-xs leading-relaxed text-ink-subtle">Dokumen ini dibuat oleh WeCIC dari arsip pribadi mahasiswa. WeCIC bukan sistem resmi kampus; cocokkan dengan bagian akademik atau keuangan bila diperlukan.</p>
    </article>
@endsection
