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
                    <div class="password-field-shell">
                        <input id="password" name="password" type="password" class="field-shell password-field-input" autocomplete="current-password" placeholder="Password portal kampus" required>
                        <button
                            id="password-visibility-toggle"
                            type="button"
                            class="password-field-toggle"
                            data-state="hidden"
                            aria-controls="password"
                            aria-label="Tampilkan password"
                            aria-pressed="false"
                        >
                            <svg data-password-icon="hidden" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 15.5c2.05-2.333 4.717-3.5 8-3.5s5.95 1.167 8 3.5" />
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 9.5 7 12" />
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m10 8 1.25 3" />
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m14 8-1.25 3" />
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m19 9.5-2 2.5" />
                            </svg>
                            <svg data-password-icon="shown" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.9" d="M2.036 12.322a1.012 1.012 0 0 1 0-.644C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.433 0 .644C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.964-7.178Z" />
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.9" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                            </svg>
                        </button>
                    </div>
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
            const passwordInput = document.getElementById('password');
            const passwordToggle = document.getElementById('password-visibility-toggle');

            const syncPasswordToggle = (isVisible) => {
                passwordInput.type = isVisible ? 'text' : 'password';
                passwordToggle.dataset.state = isVisible ? 'shown' : 'hidden';
                passwordToggle.setAttribute('aria-pressed', isVisible ? 'true' : 'false');
                passwordToggle.setAttribute('aria-label', isVisible ? 'Sembunyikan password' : 'Tampilkan password');
            };

            passwordToggle?.addEventListener('click', () => {
                syncPasswordToggle(passwordInput.type === 'password');
                passwordInput.focus({ preventScroll: true });
            });

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
