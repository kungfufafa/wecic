@extends('layouts.guest')

@section('title', 'Coba lagi sebentar — WeCIC')
@section('page_id', 'error-503')

@section('content')
    <main class="flex min-h-dvh flex-col items-center justify-center gap-10 px-4 py-10">
        <x-brand-logo class="h-8" />

        <div class="flex w-full max-w-md flex-col items-center gap-3 text-center">
            <p class="text-sm font-medium text-ink-muted tabular-nums">Error 503</p>
            <h1 class="text-2xl font-semibold tracking-tight text-ink">WeCIC sedang sibuk</h1>
            <p class="max-w-xs text-sm leading-relaxed text-ink-muted">Server belum bisa memproses halaman ini. Muat ulang halaman sebentar lagi.</p>
            <div class="mt-3 flex w-full flex-col gap-2 sm:w-auto sm:flex-row">
                <x-button onclick="window.location.reload()"><x-icon name="refresh" size="sm" />Muat ulang</x-button>
            </div>
        </div>
    </main>
@endsection
