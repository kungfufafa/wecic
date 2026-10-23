@extends('layouts.guest')

@section('title', 'Masuk — myCIC')
@section('page_id', 'login')

@section('content')
    <div class="mycic-auth-page">
        <aside class="mycic-auth-showcase">
            <svg class="mycic-auth-paths" viewBox="0 0 800 800" preserveAspectRatio="xMidYMid slice" xmlns="http://www.w3.org/2000/svg">
                <path class="mycic-auth-path" d="M-120 80C80 80 200 420 420 520C620 620 760 760 900 900" />
                <path class="mycic-auth-path" d="M-80 140C120 140 240 460 460 560C660 660 800 800 940 940" />
                <path class="mycic-auth-path" d="M-40 200C160 200 280 500 500 600C700 700 840 840 980 980" />
                <path class="mycic-auth-path" d="M0 260C200 260 320 540 540 640C740 740 880 880 1020 1020" />
                <path class="mycic-auth-path" d="M40 320C240 320 360 580 580 680C780 780 920 920 1060 1060" />
                <path class="mycic-auth-path" d="M80 380C280 380 400 620 620 720C820 820 960 960 1100 1100" />
            </svg>

            <div class="mycic-auth-showcase-content">
                <div class="flex items-center gap-3">
                    <img src="/images/ucic-shield.png" alt="Logo UCIC" class="h-12 w-auto object-contain" width="31" height="48">
                    <div>
                        <div class="text-2xl font-black tracking-tight text-white">my<span class="text-[#ffc300]">CIC</span></div>
                        <div class="text-[0.68rem] font-semibold uppercase tracking-wider text-white/80">Universitas Catur Insan Cendekia</div>
                    </div>
                </div>

                <div>
                    <div class="mb-4 inline-flex items-center gap-2 rounded-lg border border-white/15 bg-white/10 px-3 py-1 text-xs font-semibold text-white/90 backdrop-blur-sm">
                        <span class="h-2 w-2 rounded-full bg-[#ffc300]"></span>
                        <span>Portal Resmi Akademik</span>
                    </div>
                    <blockquote class="mycic-auth-quote">
                        “Portal terintegrasi mahasiswa UCIC untuk memantau perkuliahan, presensi, tugas, dan nilai akademik secara real-time.”
                    </blockquote>
                    <p class="mycic-auth-quote-author">— Universitas Catur Insan Cendekia</p>
                </div>
            </div>
        </aside>

        <main class="mycic-auth-form-panel">
            <div class="mycic-auth-form-inner">
                <div class="mycic-auth-mobile-brand mb-6 flex items-center gap-3 lg:hidden">
                    <img src="/images/ucic-shield.png" alt="Logo UCIC" class="h-9 w-auto object-contain" width="23" height="36">
                    <div class="leading-tight">
                        <div class="text-2xl font-extrabold tracking-tight text-brand">my<span class="text-ink">CIC</span></div>
                        <div class="text-[0.65rem] font-semibold uppercase tracking-wider text-ink-subtle">Universitas Catur Insan Cendekia</div>
                    </div>
                </div>

                <header>
                    <h1 class="mycic-page-heading">Masuk ke myCIC</h1>
                    <p class="mycic-page-subheading">Gunakan NIM dan password portal kampus Anda.</p>
                    {{-- Present from page load so a failed login is read out; stays on screen unlike a toast. --}}
                    <div id="login-error" role="alert" data-login-error></div>
                </header>

                {{-- POST so a submit that beats the script never puts the password in the URL. --}}
                <form id="login-form" class="mycic-form mt-8" method="post" novalidate>
                    <div class="mycic-field">
                        <label for="username" class="mycic-label">NIM atau username</label>
                        <div class="mycic-input-wrap">
                            <svg class="mycic-input-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                                <path d="M8 7a4 4 0 1 0 8 0a4 4 0 0 0 -8 0" />
                                <path d="M6 21v-2a4 4 0 0 1 4 -4h4a4 4 0 0 1 4 4v2" />
                            </svg>
                            <input
                                id="username"
                                name="username"
                                type="text"
                                class="mycic-input mycic-input-with-icon"
                                autocomplete="username"
                                placeholder="12345678"
                                autocapitalize="off"
                                spellcheck="false"
                                inputmode="text"
                                aria-describedby="login-error"
                                required
                            >
                        </div>
                    </div>

                    <div class="mycic-field">
                        <label for="password" class="mycic-label">Password</label>
                        <div class="mycic-input-wrap">
                            <svg class="mycic-input-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                                <path d="M5 13a2 2 0 0 1 2 -2h10a2 2 0 0 1 2 2v6a2 2 0 0 1 -2 2h-10a2 2 0 0 1 -2 -2v-6z" />
                                <path d="M11 16a1 1 0 1 0 2 0a1 1 0 0 0 -2 0" />
                                <path d="M8 11v-4a4 4 0 1 1 8 0v4" />
                            </svg>
                            <input
                                id="password"
                                name="password"
                                type="password"
                                class="mycic-input mycic-input-with-icon mycic-input-with-toggle"
                                autocomplete="current-password"
                                aria-describedby="login-error"
                                required
                            >
                            <button
                                type="button"
                                class="mycic-input-toggle"
                                id="password-visibility-toggle"
                                aria-label="Tampilkan password"
                                aria-pressed="false"
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                                    <path d="M10 12a2 2 0 1 0 4 0a2 2 0 0 0 -4 0" />
                                    <path d="M21 12c-2.4 4 -5.4 6 -9 6c-3.6 0 -6.6 -2 -9 -6c2.4 -4 5.4 -6 9 -6c3.6 0 6.6 2 9 6" />
                                </svg>
                            </button>
                        </div>
                    </div>

                    <button id="login-submit" type="submit" class="mycic-btn-primary mycic-btn-block mt-2 min-h-12" data-online-required>
                        Masuk
                    </button>
                </form>

                <p class="mycic-legal-text mt-8">
                    Dengan masuk, Anda menyetujui penggunaan layanan akademik sesuai kebijakan kampus.
                </p>
            </div>
        </main>
    </div>
@endsection

@push('scripts')
    <script>
        (() => {
            const form = document.getElementById('login-form');
            const submit = document.getElementById('login-submit');
            const usernameInput = document.getElementById('username');
            const passwordInput = document.getElementById('password');
            const passwordToggle = document.getElementById('password-visibility-toggle');
            const errorHost = document.getElementById('login-error');
            const expiredRetryKey = 'mycic-login-expired-username';
            // The bundle (app.js) is a module: window.portalApp exists from DOMContentLoaded.
            const appReady = new Promise((resolve) => {
                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', resolve, { once: true });
                } else {
                    resolve();
                }
            });
            let submitting = false;

            const showLoginError = (message, { tone = 'danger', invalid = false } = {}) => {
                const notice = document.createElement('div');

                notice.className = `${tone === 'warning' ? 'mycic-alert-warning' : 'mycic-alert-danger'} mt-4`;
                notice.textContent = message;
                errorHost.replaceChildren(notice);

                [usernameInput, passwordInput].forEach((input) => {
                    if (invalid) {
                        input.setAttribute('aria-invalid', 'true');
                    } else {
                        input.removeAttribute('aria-invalid');
                    }
                });
            };

            const clearLoginError = () => {
                errorHost.replaceChildren();
                [usernameInput, passwordInput].forEach((input) => input.removeAttribute('aria-invalid'));
            };

            const eyeOpen = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M10 12a2 2 0 1 0 4 0a2 2 0 0 0 -4 0" /><path d="M21 12c-2.4 4 -5.4 6 -9 6c-3.6 0 -6.6 -2 -9 -6c2.4 -4 5.4 -6 9 -6c3.6 0 6.6 2 9 6" /></svg>';
            const eyeClosed = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M10.585 10.587a2 2 0 0 0 2.829 2.828" /><path d="M16.681 16.673a8.717 8.717 0 0 1 -4.681 1.327c-3.6 0 -6.6 -2 -9 -6c1.272 -2.12 2.712 -3.678 4.32 -4.674m2.86 -1.146a9.055 9.055 0 0 1 1.82 -.18c3.6 0 6.6 2 9 6c-.666 1.11 -1.379 2.067 -2.138 2.87" /><path d="M3 3l18 18" /></svg>';

            const syncPasswordToggle = (isVisible) => {
                passwordInput.type = isVisible ? 'text' : 'password';
                passwordToggle.dataset.state = isVisible ? 'shown' : 'hidden';
                passwordToggle.setAttribute('aria-pressed', isVisible ? 'true' : 'false');
                passwordToggle.setAttribute('aria-label', isVisible ? 'Sembunyikan password' : 'Tampilkan password');
                passwordToggle.innerHTML = isVisible ? eyeClosed : eyeOpen;
            };

            passwordToggle?.addEventListener('click', (event) => {
                event.preventDefault();
                syncPasswordToggle(passwordInput.type === 'password');
                passwordInput.focus({ preventScroll: true });
            });

            // Bound as soon as the form exists: a submit before the bundle has
            // loaded waits for it, and one it never reaches says so.
            form.addEventListener('submit', async (event) => {
                event.preventDefault();

                if (submitting) {
                    return;
                }

                submitting = true;

                try {
                    await appReady;

                    const app = window.portalApp;

                    if (!app) {
                        showLoginError('Aplikasi belum termuat sepenuhnya. Muat ulang halaman lalu coba lagi.');

                        return;
                    }

                    if (!app.requireOnlineAction('Login butuh koneksi internet.')) {
                        return;
                    }

                    clearLoginError();

                    await app.withSubmitLoading(submit, async () => {
                        try {
                            const payload = await app.request('/api/auth/login', {
                                method: 'POST',
                                json: {
                                    username: usernameInput.value,
                                    password: passwordInput.value,
                                },
                                redirectOnAuth: false,
                            });

                            app.handleLoginSuccess(payload);
                            app.storeNotice(payload?.message || 'Login berhasil.', 'success');
                            syncPasswordToggle(false);
                            window.location.href = '/dashboard';
                        } catch (error) {
                            // The page outlived its app session (left open for hours, or
                            // a logout in another tab): only a reload brings a valid token.
                            if (error.status === 419) {
                                try {
                                    sessionStorage.setItem(expiredRetryKey, usernameInput.value);
                                } catch (storageError) {
                                    // The username is simply typed again.
                                }

                                showLoginError('Halaman sudah kedaluwarsa. Memuat ulang...', { tone: 'warning' });
                                window.location.reload();

                                return;
                            }

                            showLoginError(app.describeError(error, 'Login gagal. Coba lagi sebentar lagi.'), { invalid: error.status === 401 });

                            if (error.status === 401) {
                                passwordInput.focus();
                                passwordInput.select();
                            }
                        }
                    });
                } finally {
                    submitting = false;
                }
            });

            let expiredUsername = null;

            try {
                expiredUsername = sessionStorage.getItem(expiredRetryKey);
                sessionStorage.removeItem(expiredRetryKey);
            } catch (error) {
                expiredUsername = null;
            }

            if (expiredUsername !== null) {
                usernameInput.value = expiredUsername;
                showLoginError('Halaman dimuat ulang karena sudah kedaluwarsa. Masukkan password lagi.', { tone: 'warning' });
                passwordInput.focus();
            }

            // Back from the dashboard can restore this page as it was left
            // (back/forward cache): on a shared device the password typed for
            // the last login must not be there to reveal.
            window.addEventListener('pageshow', (event) => {
                if (event.persisted) {
                    passwordInput.value = '';
                    syncPasswordToggle(false);
                    clearLoginError();
                }
            });

            // The server shows this page when no session is valid, or (flagged)
            // when it could not check one: then the session may well be alive.
            @if (! ($sessionUnchecked ?? false))
                appReady.then(() => window.portalApp?.forgetEndedSession());
            @endif
        })();
    </script>
@endpush
