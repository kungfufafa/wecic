@extends('layouts.guest')

@section('title', 'Halaman tidak ditemukan — myCIC')
@section('page_id', 'error-404')

@section('content')
    <main class="flex min-h-screen items-center justify-center px-4 py-10">
        <div class="mycic-card w-full max-w-md text-center">
            <div class="mycic-card-body py-10">
                <div class="mb-4 inline-flex h-14 w-14 items-center justify-center rounded-2xl bg-brand/10 text-brand">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/><path d="M8.5 8.5l5 5M13.5 8.5l-5 5"/></svg>
                </div>
                <p class="mb-1 text-caption font-semibold uppercase tracking-wide text-ink-subtle">Error 404</p>
                <h1 class="mb-2 text-xl font-extrabold text-ink">Halaman tidak ditemukan</h1>
                <p class="mb-6 text-sm text-ink-muted">Link yang kamu buka tidak ada atau sudah dipindahkan. Coba mulai lagi dari dashboard.</p>
                <div class="flex flex-col gap-2 sm:flex-row sm:justify-center">
                    <a href="{{ url('/dashboard') }}" class="mycic-btn-primary">Ke dashboard</a>
                    <button type="button" class="mycic-btn-secondary" onclick="history.length > 1 ? history.back() : (window.location.href = '/dashboard')">Kembali</button>
                </div>
            </div>
        </div>
    </main>
@endsection
