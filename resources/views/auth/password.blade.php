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
                            <input type="password" class="form-control" name="old_password" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label required">Password Baru</label>
                            <input type="password" class="form-control" name="new_password" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label required">Konfirmasi Password Baru</label>
                            <input type="password" class="form-control" name="new_password_confirmation" required>
                        </div>
                        <div class="form-footer">
                            <button type="submit" class="btn btn-primary w-100" id="btn-submit">Ubah Password</button>
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

                const originalBtnText = btnSubmit.innerHTML;
                btnSubmit.disabled = true;
                btnSubmit.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Menyimpan...';

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
                } finally {
                    btnSubmit.disabled = false;
                    btnSubmit.innerHTML = originalBtnText;
                }
            });
        });
    </script>
@endpush
