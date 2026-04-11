@extends('layouts.guest')

@section('title', 'Login')
@section('page_id', 'login')

@section('content')
    <section class="mx-auto grid w-full max-w-5xl gap-8 lg:grid-cols-[minmax(0,0.95fr)_minmax(22rem,0.8fr)] lg:items-center">
        <div class="hidden space-y-6 lg:block">
            <div class="app-panel">
                <p class="section-label">Portal Mahasiswa</p>
                <h1 data-display class="mt-4 text-4xl font-semibold text-[color:var(--ink)] sm:text-5xl">Akademik, satu pintu.</h1>
                <p class="mt-4 max-w-[48ch] text-sm leading-7 text-[color:var(--ink-soft)]">
                    Kelas, jadwal, dan ujian — dari satu portal.
                </p>
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <article class="app-panel-muted">
                    <p class="section-label">LMS</p>
                    <p class="mt-3 text-sm font-medium text-[color:var(--ink)]">Materi & progres SIPANDAI.</p>
                </article>
                <article class="app-panel-muted">
                    <p class="section-label">Jadwal</p>
                    <p class="mt-3 text-sm font-medium text-[color:var(--ink)]">Kuliah & pertemuan.</p>
                </article>
                <article class="app-panel-muted">
                    <p class="section-label">Ujian</p>
                    <p class="mt-3 text-sm font-medium text-[color:var(--ink)]">Jadwal & unggah berkas.</p>
                </article>
            </div>
        </div>

        <div class="app-panel">
            <div class="space-y-1">
                <p class="section-label">Autentikasi</p>
                <h2 data-display class="text-3xl font-semibold text-[color:var(--ink)]">Login</h2>
            </div>

            <form id="login-form" class="mt-8 grid gap-5">
                <label class="grid gap-2">
                    <span class="section-label">Username</span>
                    <input id="username" name="username" type="text" class="field-shell" autocomplete="username" placeholder="NIM atau username" required>
                </label>

                <label class="grid gap-2">
                    <span class="section-label">Password</span>
                    <input id="password" name="password" type="password" class="field-shell" autocomplete="current-password" placeholder="Password portal kampus" required>
                </label>

                <button id="login-submit" type="submit" class="primary-button w-full" data-online-required>Masuk</button>
            </form>

        </div>
    </section>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const app = window.portalApp;
            const form = document.getElementById('login-form');
            const submit = document.getElementById('login-submit');

            form.addEventListener('submit', async (event) => {
                event.preventDefault();

                if (!app.requireOnlineAction('Login butuh koneksi internet.')) {
                    return;
                }

                submit.setAttribute('disabled', 'disabled');
                submit.textContent = 'Memproses...';

                try {
                    const payload = await app.request('/api/auth/login', {
                        method: 'POST',
                        json: {
                            username: document.getElementById('username').value,
                            password: document.getElementById('password').value,
                        },
                        redirectOnAuth: false,
                    });

                    app.handleLoginSuccess(payload);
                    app.storeNotice(payload?.message || 'Login berhasil.', 'success');
                    window.location.href = '/dashboard';
                } catch (error) {
                    app.showNotice(error.payload?.message || error.message, 'error');
                    submit.removeAttribute('disabled');
                    submit.textContent = 'Masuk';
                }
            });
        });
    </script>
@endpush
