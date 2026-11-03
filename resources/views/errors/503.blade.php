@extends('layouts.guest')

@section('title', 'Coba lagi sebentar — WeCIC')
@section('page_id', 'error-503')

@section('content')
    <main class="flex min-h-screen items-center justify-center px-4 py-10">
        <div class="mycic-card w-full max-w-md text-center">
            <div class="mycic-card-body py-10">
                <div class="mb-4 inline-flex h-14 w-14 items-center justify-center rounded-2xl bg-brand/10 text-brand">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-3-6.7"/><path d="M21 4v5h-5"/></svg>
                </div>
                <p class="mb-1 text-caption font-semibold uppercase tracking-wide text-ink-subtle">Error 503</p>
                <h1 class="mb-2 text-xl font-extrabold text-ink">WeCIC sedang sibuk</h1>
                <p class="mb-6 text-sm text-ink-muted">Server belum bisa memproses halaman ini. Muat ulang halaman sebentar lagi.</p>
                <div class="flex flex-col gap-2 sm:flex-row sm:justify-center">
                    <button type="button" class="mycic-btn-primary" onclick="window.location.reload()">Muat ulang</button>
                </div>
            </div>
        </div>
    </main>
@endsection
