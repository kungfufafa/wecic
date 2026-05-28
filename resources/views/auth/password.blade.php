@extends('layouts.app')

@section('title', 'Ubah Password')
@section('page_id', 'password')

@section('content')
    <div class="row row-cards">
        <div class="col-12 col-md-6 offset-md-3">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Ubah Password</h3>
                </div>
                <div class="card-body">
                    <form id="form-change-password">
                        <div class="mb-3">
                            <label class="form-label required">Password Lama</label>
                            <div class="input-group input-group-flat bg-white rounded-3 shadow-sm border-0">
                                <input type="password" class="form-control" name="old_password" id="old_password" required>
                                <span class="input-group-text">
                                    <a href="#" class="link-secondary text-decoration-none password-toggle" data-target="old_password" title="Tampilkan password" data-bs-toggle="tooltip">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M10 12a2 2 0 1 0 4 0a2 2 0 0 0 -4 0" /><path d="M21 12c-2.4 4 -5.4 6 -9 6c-3.6 0 -6.6 -2 -9 -6c2.4 -4 5.4 -6 9 -6c3.6 0 6.6 2 9 6" /></svg>
                                    </a>
                                </span>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label required">Password Baru</label>
                            <div class="input-group input-group-flat bg-white rounded-3 shadow-sm border-0">
                                <input type="password" class="form-control" name="new_password" id="new_password" required>
                                <span class="input-group-text">
                                    <a href="#" class="link-secondary text-decoration-none password-toggle" data-target="new_password" title="Tampilkan password" data-bs-toggle="tooltip">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M10 12a2 2 0 1 0 4 0a2 2 0 0 0 -4 0" /><path d="M21 12c-2.4 4 -5.4 6 -9 6c-3.6 0 -6.6 -2 -9 -6c2.4 -4 5.4 -6 9 -6c3.6 0 6.6 2 9 6" /></svg>
                                    </a>
                                </span>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label required">Konfirmasi Password Baru</label>
                            <div class="input-group input-group-flat bg-white rounded-3 shadow-sm border-0">
                                <input type="password" class="form-control" name="new_password_confirmation" id="new_password_confirmation" required>
                                <span class="input-group-text">
                                    <a href="#" class="link-secondary text-decoration-none password-toggle" data-target="new_password_confirmation" title="Tampilkan password" data-bs-toggle="tooltip">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M10 12a2 2 0 1 0 4 0a2 2 0 0 0 -4 0" /><path d="M21 12c-2.4 4 -5.4 6 -9 6c-3.6 0 -6.6 -2 -9 -6c2.4 -4 5.4 -6 9 -6c3.6 0 6.6 2 9 6" /></svg>
                                    </a>
                                </span>
                            </div>
                        </div>
                        <div class="form-footer">
                            <button type="submit" class="btn btn-primary w-100" id="btn-submit">Simpan password</button>
                        </div>
                    </form>
                </div>
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
                    toggle.setAttribute('data-bs-original-title', isVisible ? 'Sembunyikan password' : 'Tampilkan password');
                    
                    if (isVisible) {
                        toggle.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M10.585 10.587a2 2 0 0 0 2.829 2.828" /><path d="M16.681 16.673a8.717 8.717 0 0 1 -4.681 1.327c-3.6 0 -6.6 -2 -9 -6c1.272 -2.12 2.712 -3.678 4.32 -4.674m2.86 -1.146a9.055 9.055 0 0 1 1.82 -.18c3.6 0 6.6 2 9 6c-.666 1.11 -1.379 2.067 -2.138 2.87" /><path d="M3 3l18 18" /></svg>';
                    } else {
                        toggle.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M10 12a2 2 0 1 0 4 0a2 2 0 0 0 -4 0" /><path d="M21 12c-2.4 4 -5.4 6 -9 6c-3.6 0 -6.6 -2 -9 -6c2.4 -4 5.4 -6 9 -6c3.6 0 6.6 2 9 6" /></svg>';
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
