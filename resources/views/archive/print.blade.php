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
@endphp

@section('content')
    <article class="mx-auto flex max-w-3xl flex-col gap-5">
        <header class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="text-caption uppercase tracking-wide text-ink-subtle">Bukti arsip MyCIC</p>
                <h1 class="mycic-page-heading">{{ $label }}{{ $version->subject ? ' · '.$version->subject : '' }}</h1>
                <p class="mycic-page-subheading">Salinan data yang ditampilkan portal kampus kepada mahasiswa, disimpan otomatis oleh MyCIC.</p>
            </div>
            <button type="button" class="mycic-btn-primary print:hidden" onclick="window.print()">Cetak / simpan PDF</button>
        </header>

        <section class="mycic-card">
            <div class="mycic-card-body">
                <dl class="grid grid-cols-1 gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                    @if ($studentName)
                        <div><dt class="text-ink-subtle">Nama</dt><dd class="font-semibold text-ink">{{ $studentName }}</dd></div>
                    @endif
                    @if ($studentId)
                        <div><dt class="text-ink-subtle">NIM</dt><dd class="font-semibold text-ink">{{ $studentId }}</dd></div>
                    @endif
                    <div><dt class="text-ink-subtle">Status versi</dt><dd class="font-semibold text-ink">{{ $statusLabels[$version->status] ?? $version->status }}</dd></div>
                    <div><dt class="text-ink-subtle">Terlihat</dt><dd class="font-semibold text-ink">{{ $version->seen_count }} kali sinkronisasi</dd></div>
                    <div><dt class="text-ink-subtle">Pertama terlihat di portal</dt><dd class="font-semibold text-ink">{{ $formatMoment($version->first_seen_at) }}</dd></div>
                    <div><dt class="text-ink-subtle">Terakhir terlihat di portal</dt><dd class="font-semibold text-ink">{{ $formatMoment($version->last_seen_at) }}</dd></div>
                    <div><dt class="text-ink-subtle">Dicetak</dt><dd class="font-semibold text-ink">{{ $formatMoment(now()) }}</dd></div>
                    <div><dt class="text-ink-subtle">Kode versi</dt><dd class="break-all font-mono text-caption text-ink">#{{ $version->id }} · {{ substr($version->content_hash, 0, 16) }}</dd></div>
                </dl>
            </div>
        </section>

        @if (! empty($version->findings))
            <section class="mycic-alert-warning mb-0">
                <h2 class="font-bold">Perubahan janggal yang terdeteksi</h2>
                <ul class="mt-1 flex list-disc flex-col gap-1 ps-5">
                    @foreach ($version->findings as $finding)
                        <li>{{ $finding['message'] ?? '' }}</li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($baseline)
            <section class="flex flex-col gap-2">
                <h2 class="mycic-section-title">Perbedaan dengan versi {{ $formatMoment($baseline->first_seen_at) }}</h2>
                @if ($diff === [])
                    <p class="text-sm text-ink-subtle">Tidak ada perbedaan isi.</p>
                @else
                    <div class="mycic-table-wrap">
                        <table class="mycic-table">
                            <thead>
                                <tr>
                                    <th scope="col">Bagian</th>
                                    <th scope="col">Sebelumnya</th>
                                    <th scope="col">Versi ini</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($diff as $row)
                                    <tr>
                                        <td class="align-top">{{ $row['path'] }}</td>
                                        <td class="align-top">{{ $row['old'] ?? '–' }}</td>
                                        <td class="align-top">{{ $row['new'] ?? '–' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        @endif

        <section class="flex flex-col gap-2">
            <h2 class="mycic-section-title">Isi lengkap versi ini</h2>
            <div class="mycic-table-wrap">
                <table class="mycic-table">
                    <tbody>
                        @foreach ($entries as $entry)
                            <tr>
                                <td class="w-1/2 align-top text-ink-subtle">{{ $entry['path'] }}</td>
                                <td class="align-top break-words">{{ $entry['new'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <p class="text-caption text-ink-subtle">Dokumen ini dibuat oleh MyCIC dari arsip pribadi mahasiswa. MyCIC bukan sistem resmi kampus; cocokkan dengan bagian akademik atau keuangan bila diperlukan.</p>
    </article>
@endsection
