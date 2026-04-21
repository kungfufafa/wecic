@extends('layouts.guest')

@section('title', 'Login')
@section('page_id', 'login')

@section('content')
    <div class="text-center mb-5 mt-3">
        <a href="{{ route('login') }}" class="text-decoration-none d-inline-block">
            <div class="avatar avatar-md bg-primary text-white rounded-3 mb-4 shadow-sm" style="width: 56px; height: 56px;">
                <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                    <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                    <path d="M12 3l8 4.5l0 9l-8 4.5l-8 -4.5l0 -9l8 -4.5" />
                    <path d="M12 12l8 -4.5" />
                    <path d="M12 12l0 9" />
                    <path d="M12 12l-8 -4.5" />
                </svg>
            </div>
        </a>
        <h1 class="h2 fw-bold text-dark mb-2">Welcome to myCIC</h1>
        <p class="text-secondary m-0">Sistem Informasi Akademik</p>
    </div>

    <div class="px-sm-4">
        <form id="login-form">
            <div class="mb-3">
                <label for="username" class="form-label fw-semibold text-dark">Username</label>
                <input id="username" name="username" type="text" class="form-control" autocomplete="username" placeholder="NIM atau username" required>
            </div>

            <div class="mb-4">
                <label for="password" class="form-label fw-semibold text-dark">Password</label>
                <div class="input-group input-group-flat bg-white rounded-3 shadow-sm border-0">
                    <input id="password" name="password" type="password" class="form-control" autocomplete="current-password" placeholder="Password portal kampus" required>
                    <span class="input-group-text">
                        <a href="#" class="link-secondary text-decoration-none" id="password-visibility-toggle" title="Tampilkan password" data-bs-toggle="tooltip">
                            <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M10 12a2 2 0 1 0 4 0a2 2 0 0 0 -4 0" /><path d="M21 12c-2.4 4 -5.4 6 -9 6c-3.6 0 -6.6 -2 -9 -6c2.4 -4 5.4 -6 9 -6c3.6 0 6.6 2 9 6" /></svg>
                        </a>
                    </span>
                </div>
            </div>

            <div class="form-footer mt-5">
                <button id="login-submit" type="submit" class="btn btn-primary btn-3 w-100" data-online-required>Continue</button>
            </div>
        </form>

        <div class="text-center mt-5">
            <p class="text-secondary small">
                Gunakan akun portal kampus untuk masuk.
            </p>
        </div>
    </div>
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
                passwordToggle.setAttribute('data-bs-original-title', isVisible ? 'Sembunyikan password' : 'Tampilkan password');
                
                if (isVisible) {
                    passwordToggle.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M10.585 10.587a2 2 0 0 0 2.829 2.828" /><path d="M16.681 16.673a8.717 8.717 0 0 1 -4.681 1.327c-3.6 0 -6.6 -2 -9 -6c1.272 -2.12 2.712 -3.678 4.32 -4.674m2.86 -1.146a9.055 9.055 0 0 1 1.82 -.18c3.6 0 6.6 2 9 6c-.666 1.11 -1.379 2.067 -2.138 2.87" /><path d="M3 3l18 18" /></svg>';
                } else {
                    passwordToggle.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M10 12a2 2 0 1 0 4 0a2 2 0 0 0 -4 0" /><path d="M21 12c-2.4 4 -5.4 6 -9 6c-3.6 0 -6.6 -2 -9 -6c2.4 -4 5.4 -6 9 -6c3.6 0 6.6 2 9 6" /></svg>';
                }
            };

            passwordToggle?.addEventListener('click', (e) => {
                e.preventDefault();
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
