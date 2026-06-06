@extends('layouts.app')

@section('title', 'Ubah Password')
@section('page_id', 'password')

@section('content')
    <div class="mx-auto max-w-lg">
        <header class="mb-6">
            <h1 class="mycic-page-heading">Ubah Password</h1>
            <p class="mycic-page-subheading">Gunakan password portal kampus Anda saat ini, lalu tentukan password baru.</p>
        </header>

        <div class="mycic-card">
            <div class="mycic-card-body">
                <form id="form-change-password" class="mycic-form">
                    <div class="mycic-field">
                        <label class="mycic-label" for="old_password">Password Lama</label>
                        <div class="mycic-input-wrap">
                            <input type="password" class="mycic-input mycic-input-with-toggle" name="old_password" id="old_password" required>
                            <button type="button" class="mycic-input-toggle password-toggle" data-target="old_password" aria-label="Tampilkan password" aria-pressed="false">
                                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M10 12a2 2 0 1 0 4 0a2 2 0 0 0 -4 0" /><path d="M21 12c-2.4 4 -5.4 6 -9 6c-3.6 0 -6.6 -2 -9 -6c2.4 -4 5.4 -6 9 -6c3.6 0 6.6 2 9 6" /></svg>
                            </button>
                        </div>
                    </div>
                    <div class="mycic-field">
                        <label class="mycic-label" for="new_password">Password Baru</label>
                        <div class="mycic-input-wrap">
                            <input type="password" class="mycic-input mycic-input-with-toggle" name="new_password" id="new_password" required>
                            <button type="button" class="mycic-input-toggle password-toggle" data-target="new_password" aria-label="Tampilkan password" aria-pressed="false">
                                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M10 12a2 2 0 1 0 4 0a2 2 0 0 0 -4 0" /><path d="M21 12c-2.4 4 -5.4 6 -9 6c-3.6 0 -6.6 -2 -9 -6c2.4 -4 5.4 -6 9 -6c3.6 0 6.6 2 9 6" /></svg>
                            </button>
                        </div>
                    </div>
                    <div class="mycic-field">
                        <label class="mycic-label" for="new_password_confirmation">Konfirmasi Password Baru</label>
                        <div class="mycic-input-wrap">
                            <input type="password" class="mycic-input mycic-input-with-toggle" name="new_password_confirmation" id="new_password_confirmation" required>
                            <button type="button" class="mycic-input-toggle password-toggle" data-target="new_password_confirmation" aria-label="Tampilkan password" aria-pressed="false">
                                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M10 12a2 2 0 1 0 4 0a2 2 0 0 0 -4 0" /><path d="M21 12c-2.4 4 -5.4 6 -9 6c-3.6 0 -6.6 -2 -9 -6c2.4 -4 5.4 -6 9 -6c3.6 0 6.6 2 9 6" /></svg>
                            </button>
                        </div>
                    </div>
                    <button type="submit" class="mycic-btn-primary mycic-btn-block mt-2 min-h-12" id="btn-submit">Simpan password</button>
                </form>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', async () => {
            const app = window.portalApp;
            const form = document.getElementById('form-change-password');
            const btnSubmit = document.getElementById('btn-submit');

            const ready = await app.ensureAuthenticated();
            if (!ready) return;

            document.querySelectorAll('.password-toggle').forEach((toggle) => {
                toggle.addEventListener('click', (e) => {
                    e.preventDefault();
                    const input = document.getElementById(toggle.dataset.target);
                    const isVisible = input.type === 'password';

                    input.type = isVisible ? 'text' : 'password';
                    toggle.dataset.state = isVisible ? 'shown' : 'hidden';
                    toggle.setAttribute('aria-pressed', isVisible ? 'true' : 'false');
                    toggle.setAttribute('aria-label', isVisible ? 'Sembunyikan password' : 'Tampilkan password');
                    if (isVisible) {
                        toggle.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M10.585 10.587a2 2 0 0 0 2.829 2.828" /><path d="M16.681 16.673a8.717 8.717 0 0 1 -4.681 1.327c-3.6 0 -6.6 -2 -9 -6c1.272 -2.12 2.712 -3.678 4.32 -4.674m2.86 -1.146a9.055 9.055 0 0 1 1.82 -.18c3.6 0 6.6 2 9 6c-.666 1.11 -1.379 2.067 -2.138 2.87" /><path d="M3 3l18 18" /></svg>';
                    } else {
                        toggle.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M10 12a2 2 0 1 0 4 0a2 2 0 0 0 -4 0" /><path d="M21 12c-2.4 4 -5.4 6 -9 6c-3.6 0 -6.6 -2 -9 -6c2.4 -4 5.4 -6 9 -6c3.6 0 6.6 2 9 6" /></svg>';
                    }
                    input.focus({ preventScroll: true });
                });
            });

            form.addEventListener('submit', async (e) => {
                e.preventDefault();

                const formData = new FormData(form);
                const oldPassword = formData.get('old_password');
                const newPassword = formData.get('new_password');
                const confirmPassword = formData.get('new_password_confirmation');

                if (newPassword !== confirmPassword) {
                    app.showNotice('Konfirmasi password tidak cocok', 'error');
                    return;
                }

                await app.withSubmitLoading(btnSubmit, async () => {
                    try {
                        const response = await app.request('/api/auth/password', {
                            method: 'POST',
                            body: JSON.stringify({
                                old_password: oldPassword,
                                new_password: newPassword,
                                new_password_confirmation: confirmPassword
                            }),
                        });

                        app.showNotice(response.message || 'Password berhasil diubah', 'success');
                        form.reset();
                    } catch (error) {
                        app.showNotice(error.payload?.message || error.message, 'error');
                    }
                });
            });
        });
    </script>
@endpush
