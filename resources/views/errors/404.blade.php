@extends('layouts.guest')

@section('title', 'Halaman tidak ditemukan — WeCIC')
@section('page_id', 'error-404')

@section('content')
    <main class="flex min-h-dvh flex-col items-center justify-center gap-10 px-4 py-10">
        <x-brand-logo class="h-8" />

        <div class="flex w-full max-w-md flex-col items-center gap-3 text-center">
            <p class="text-sm font-medium text-ink-muted tabular-nums">Error 404</p>
            <h1 class="text-2xl font-semibold tracking-tight text-ink">Halaman tidak ditemukan</h1>
            <p class="max-w-xs text-sm leading-relaxed text-ink-muted">Link yang kamu buka tidak ada atau sudah dipindahkan. Coba mulai lagi dari dashboard.</p>
            <div class="mt-3 flex w-full flex-col gap-2 sm:w-auto sm:flex-row">
                <x-button :href="url('/dashboard')"><x-icon name="home" size="sm" />Ke dashboard</x-button>
                <x-button variant="secondary" onclick="history.length > 1 ? history.back() : (window.location.href = '/dashboard')"><x-icon name="chevron-left" size="sm" />Kembali</x-button>
            </div>
        </div>
    </main>
@endsection
