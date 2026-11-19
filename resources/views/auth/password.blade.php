@extends('layouts.app')

@section('title', 'Ubah Password')
@section('page_id', 'password')
{{-- Nothing on this page comes from a dataset, so there is nothing to sync. --}}
@section('hide_sync_refresh', '1')

@section('content')
    <div class="mx-auto max-w-lg">
        <header class="mb-6">
            <h1 class="mycic-page-heading">Ubah password</h1>
            <p class="mycic-page-subheading">Gunakan password portal kampus kamu saat ini, lalu tentukan password baru</p>
        </header>

        <div class="mycic-card">
            <div class="mycic-card-body">
                {{-- Submitted only by the script below: a native submit must never put the passwords in a URL. --}}
                <form id="form-change-password" class="mycic-form" method="post" action="javascript:void(0)">
                    <div class="mycic-field">
                        <label class="mycic-label" for="current_password">Password lama</label>
                        <div class="mycic-input-wrap">
                            <input type="password" class="mycic-input mycic-input-with-toggle" name="current_password" id="current_password" autocomplete="current-password" aria-describedby="current_password-error" required>
                            <button type="button" class="mycic-input-toggle password-toggle" data-target="current_password" aria-label="Tampilkan password" aria-pressed="false">
                                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M10 12a2 2 0 1 0 4 0a2 2 0 0 0 -4 0" /><path d="M21 12c-2.4 4 -5.4 6 -9 6c-3.6 0 -6.6 -2 -9 -6c2.4 -4 5.4 -6 9 -6c3.6 0 6.6 2 9 6" /></svg>
                            </button>
                        </div>
                        <p class="mycic-field-error" id="current_password-error" data-field-error="current_password" hidden></p>
                    </div>
                    <div class="mycic-field">
                        <label class="mycic-label" for="new_password">Password baru</label>
                        <div class="mycic-input-wrap">
                            <input type="password" class="mycic-input mycic-input-with-toggle" name="new_password" id="new_password" autocomplete="new-password" aria-describedby="new_password-error" required>
                            <button type="button" class="mycic-input-toggle password-toggle" data-target="new_password" aria-label="Tampilkan password" aria-pressed="false">
                                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M10 12a2 2 0 1 0 4 0a2 2 0 0 0 -4 0" /><path d="M21 12c-2.4 4 -5.4 6 -9 6c-3.6 0 -6.6 -2 -9 -6c2.4 -4 5.4 -6 9 -6c3.6 0 6.6 2 9 6" /></svg>
                            </button>
                        </div>
                        <p class="mycic-field-error" id="new_password-error" data-field-error="new_password" hidden></p>
                    </div>
                    <div class="mycic-field">
                        <label class="mycic-label" for="new_password_confirmation">Konfirmasi password baru</label>
                        <div class="mycic-input-wrap">
                            <input type="password" class="mycic-input mycic-input-with-toggle" name="new_password_confirmation" id="new_password_confirmation" autocomplete="new-password" aria-describedby="new_password_confirmation-error" required>
                            <button type="button" class="mycic-input-toggle password-toggle" data-target="new_password_confirmation" aria-label="Tampilkan password" aria-pressed="false">
                                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M10 12a2 2 0 1 0 4 0a2 2 0 0 0 -4 0" /><path d="M21 12c-2.4 4 -5.4 6 -9 6c-3.6 0 -6.6 -2 -9 -6c2.4 -4 5.4 -6 9 -6c3.6 0 6.6 2 9 6" /></svg>
                            </button>
                        </div>
                        <p class="mycic-field-error" id="new_password_confirmation-error" data-field-error="new_password_confirmation" hidden></p>
                    </div>
                    <button type="submit" class="mycic-btn-primary mycic-btn-block mt-2" id="btn-submit" disabled>Simpan password</button>
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
            let ready = false;

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
                        toggle.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M10.585 10.587a2 2 0 0 0 2.829 2.828" /><path d="M16.681 16.673a8.717 8.717 0 0 1 -4.681 1.327c-3.6 0 -6.6 -2 -9 -6c1.272 -2.12 2.712 -3.678 4.32 -4.674m2.86 -1.146a9.055 9.055 0 0 1 1.82 -.18c3.6 0 6.6 2 9 6c-.666 1.11 -1.379 2.067 -2.138 2.87" /><path d="M3 3l18 18" /></svg>';
                    } else {
                        toggle.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M10 12a2 2 0 1 0 4 0a2 2 0 0 0 -4 0" /><path d="M21 12c-2.4 4 -5.4 6 -9 6c-3.6 0 -6.6 -2 -9 -6c2.4 -4 5.4 -6 9 -6c3.6 0 6.6 2 9 6" /></svg>';
                    }
                    input.focus({ preventScroll: true });
                });
            });

            const setFieldError = (name, message) => {
                const input = form.elements.namedItem(name);
                const errorNode = form.querySelector(`[data-field-error="${name}"]`);

                if (!input || !errorNode) {
                    return false;
                }

                errorNode.textContent = message || '';
                errorNode.hidden = !message;
                if (message) {
                    input.setAttribute('aria-invalid', 'true');
                } else {
                    input.removeAttribute('aria-invalid');
                }

                return true;
            };

            const clearFieldErrors = () => {
                form.querySelectorAll('[data-field-error]').forEach((node) => setFieldError(node.dataset.fieldError, ''));
            };

            const showFieldErrors = (errors) => {
                const shown = Object.entries(errors || {}).filter(([name, messages]) => setFieldError(name, [].concat(messages)[0]));

                if (shown.length) {
                    form.elements.namedItem(shown[0][0])?.focus();
                }

                return shown.length > 0;
            };

            form.querySelectorAll('input').forEach((input) => {
                input.addEventListener('input', () => setFieldError(input.name, ''));
            });

            form.addEventListener('submit', async (e) => {
                e.preventDefault();

                if (!ready) {
                    return;
                }

                clearFieldErrors();

                const formData = new FormData(form);
                const currentPassword = formData.get('current_password');
                const newPassword = formData.get('new_password');
                const confirmPassword = formData.get('new_password_confirmation');

                if (newPassword !== confirmPassword) {
                    showFieldErrors({ new_password_confirmation: 'Konfirmasi password baru tidak sesuai.' });
                    return;
                }

                await app.withSubmitLoading(btnSubmit, async () => {
                    try {
                        const response = await app.request('/api/auth/password', {
                            method: 'POST',
                            json: {
                                current_password: currentPassword,
                                new_password: newPassword,
                                new_password_confirmation: confirmPassword,
                            },
                        });

                        app.showNotice(response?.message || 'Password berhasil diubah', 'success');
                        form.reset();
                    } catch (error) {
                        if (!showFieldErrors(error.payload?.errors)) {
                            app.showNotice(error.payload?.message || error.message, 'error');
                        }
                    }
                });
            });

            ready = Boolean(await app.ensureAuthenticated());
            btnSubmit.disabled = !ready;
        });
    </script>
@endpush
