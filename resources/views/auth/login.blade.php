@extends('layouts.guest')

@section('title', 'Masuk — WeCIC')
@section('page_id', 'login')

@section('content')
    <div class="grid min-h-dvh lg:grid-cols-2">
        <aside class="relative hidden border-e border-border bg-surface text-ink lg:flex lg:flex-col lg:justify-between">
            <div class="relative z-10 flex h-full flex-col justify-between gap-8 p-10 xl:p-14">
                <div class="flex flex-col items-start gap-2">
                    <x-brand-logo class="h-9" />
                    <p class="text-xs font-medium text-ink-subtle">Aplikasi unofficial untuk MyCIC</p>
                </div>

                <p class="text-xs leading-relaxed text-ink-subtle">Data tetap bersumber dari MyCIC (my.cic.ac.id) · WeCIC tidak berafiliasi dengan UCIC</p>
            </div>
        </aside>

        <main class="relative flex flex-col bg-panel px-6 py-8 sm:px-10 sm:py-10 lg:px-14 lg:py-12 xl:px-20">
            <div class="mx-auto flex w-full max-w-sm flex-1 flex-col justify-center">
                <div class="mb-8 flex flex-col items-start gap-2 lg:hidden">
                    <x-brand-logo class="h-9" />
                    <p class="text-xs font-medium text-ink-subtle">Aplikasi unofficial untuk MyCIC</p>
                </div>

                <x-page-header title="Masuk ke WeCIC" subtitle="Gunakan NIM dan password MyCIC kamu">
                    {{-- Present from page load so a failed login is read out; stays on screen unlike a toast. --}}
                    <div id="login-error" role="alert" data-login-error></div>
                </x-page-header>

                {{-- POST so a submit that beats the script never puts the password in the URL. --}}
                <form id="login-form" class="flex flex-col gap-5" method="post" novalidate>
                    <x-field label="NIM atau username" for="username">
                        <div class="relative flex items-center">
                            <svg class="pointer-events-none absolute start-3 top-1/2 z-10 -translate-y-1/2 text-ink-subtle" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                                <path d="M8 7a4 4 0 1 0 8 0a4 4 0 0 0 -8 0" />
                                <path d="M6 21v-2a4 4 0 0 1 4 -4h4a4 4 0 0 1 4 4v2" />
                            </svg>
                            <x-input
                                icon
                                id="username"
                                name="username"
                                type="text"
                                autocomplete="username"
                                placeholder="2023102001"
                                autocapitalize="off"
                                spellcheck="false"
                                inputmode="text"
                                aria-describedby="login-error"
                                required
                            />
                        </div>
                    </x-field>

                    <x-field label="Password" for="password">
                        <div class="relative flex items-center">
                            <svg class="pointer-events-none absolute start-3 top-1/2 z-10 -translate-y-1/2 text-ink-subtle" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                                <path d="M5 13a2 2 0 0 1 2 -2h10a2 2 0 0 1 2 2v6a2 2 0 0 1 -2 2h-10a2 2 0 0 1 -2 -2v-6z" />
                                <path d="M11 16a1 1 0 1 0 2 0a1 1 0 0 0 -2 0" />
                                <path d="M8 11v-4a4 4 0 1 1 8 0v4" />
                            </svg>
                            {{-- A plain input, not x-input: FrontendShellTest reads password field names from the view source. --}}
                            <input
                                class="h-11 w-full min-w-0 rounded-lg border border-border bg-panel pe-11 ps-9 text-base text-ink shadow-xs transition-[color,box-shadow,border-color] placeholder:text-ink-subtle focus-visible:border-brand focus-visible:outline-none focus-visible:ring-[3px] focus-visible:ring-brand/20 disabled:cursor-not-allowed disabled:bg-surface disabled:opacity-60 aria-invalid:border-danger aria-invalid:ring-danger/20 sm:h-9 sm:text-sm dark:shadow-none"
                                id="password"
                                name="password"
                                type="password"
                                autocomplete="current-password"
                                placeholder="Masukkan password"
                                aria-describedby="login-error"
                                required
                            >
                            <button
                                type="button"
                                class="absolute end-1 top-1/2 z-10 flex size-9 -translate-y-1/2 sm:size-7 cursor-pointer items-center justify-center rounded-md text-ink-subtle transition-colors hover:bg-surface hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40"
                                id="password-visibility-toggle"
                                aria-label="Tampilkan password"
                                aria-pressed="false"
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                                    <path d="M10 12a2 2 0 1 0 4 0a2 2 0 0 0 -4 0" />
                                    <path d="M21 12c-2.4 4 -5.4 6 -9 6c-3.6 0 -6.6 -2 -9 -6c2.4 -4 5.4 -6 9 -6c3.6 0 6.6 2 9 6" />
                                </svg>
                            </button>
                        </div>
                    </x-field>

                    <x-button id="login-submit" type="submit" class="mt-1 w-full" data-online-required>
                        Masuk
                    </x-button>
                </form>

                @php($demoSignInUrl = App\Services\PortalDemoFixtures::signInUrl(app()))
                @if ($demoSignInUrl !== null)
                    {{-- Local demo mode (PORTAL_DEMO=true): a session on the fixture pages, without the portal. --}}
                    <form id="demo-login-form" class="mt-6 flex flex-col gap-2 border-t border-border-light pt-6" method="post" action="{{ $demoSignInUrl }}">
                        @csrf
                        <x-button id="demo-login-submit" type="submit" variant="secondary" class="w-full">
                            Masuk sebagai mahasiswa demo
                        </x-button>
                        <p class="text-center text-xs leading-relaxed text-ink-subtle">
                            Mode demo lokal: data dari <code class="font-mono">tests/Fixtures/portal</code>, tidak menghubungi portal kampus.
                        </p>
                    </form>
                @endif

                <p class="mt-8 text-center text-xs leading-relaxed text-ink-subtle lg:hidden">
                    WeCIC adalah aplikasi unofficial buatan mahasiswa dan tidak berafiliasi dengan UCIC. Data tetap bersumber dari MyCIC.
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

                // Full strings, not app.ui: this also runs when the bundle never loaded.
                notice.className = tone === 'warning'
                    ? 'mt-4 rounded-lg border border-warning/25 bg-warning-soft px-4 py-3 text-sm text-ink'
                    : 'mt-4 rounded-lg border border-danger/25 bg-danger-soft px-4 py-3 text-sm text-ink';
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

            const eyeOpen = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M10 12a2 2 0 1 0 4 0a2 2 0 0 0 -4 0" /><path d="M21 12c-2.4 4 -5.4 6 -9 6c-3.6 0 -6.6 -2 -9 -6c2.4 -4 5.4 -6 9 -6c3.6 0 6.6 2 9 6" /></svg>';
            const eyeClosed = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M10.585 10.587a2 2 0 0 0 2.829 2.828" /><path d="M16.681 16.673a8.717 8.717 0 0 1 -4.681 1.327c-3.6 0 -6.6 -2 -9 -6c1.272 -2.12 2.712 -3.678 4.32 -4.674m2.86 -1.146a9.055 9.055 0 0 1 1.82 -.18c3.6 0 6.6 2 9 6c-.666 1.11 -1.379 2.067 -2.138 2.87" /><path d="M3 3l18 18" /></svg>';

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
