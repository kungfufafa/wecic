@extends('layouts.guest')

@section('title', 'Halaman tidak ditemukan — WeCIC')
@section('page_id', 'error-404')

@section('content')
    <main class="flex min-h-dvh items-center justify-center px-4 py-10">
        <div class="mycic-card w-full max-w-sm text-center">
            <div class="p-6 sm:p-8">
                <x-brand-logo class="mx-auto mb-6 h-8" />
                <div class="mb-4 inline-flex h-12 w-12 items-center justify-center rounded-xl border border-border bg-surface text-ink-muted">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/><path d="M8.5 8.5l5 5M13.5 8.5l-5 5"/></svg>
                </div>
                <p class="mb-1 font-mono text-caption font-medium text-ink-subtle">Error 404</p>
                <h1 class="mb-2 text-xl font-semibold leading-tight tracking-[-0.02em] text-ink sm:text-2xl">Halaman tidak ditemukan</h1>
                <p class="mb-6 text-sm leading-relaxed text-ink-muted">Link yang kamu buka tidak ada atau sudah dipindahkan. Coba mulai lagi dari dashboard.</p>
                <div class="flex flex-col gap-2 sm:flex-row sm:justify-center">
                    <x-button :href="url('/dashboard')">Ke dashboard</x-button>
                    <x-button variant="secondary" onclick="history.length > 1 ? history.back() : (window.location.href = '/dashboard')">Kembali</x-button>
                </div>
            </div>
        </div>
    </main>
@endsection
