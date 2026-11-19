@extends('layouts.app')

@section('title', 'Ubah Password')
@section('page_id', 'password')
{{-- Nothing on this page comes from a dataset, so there is nothing to sync. --}}
@section('hide_sync_refresh', '1')

@section('content')
    <x-page-hero title="Ubah password" subtitle="Gunakan password portal kampus kamu saat ini, lalu tentukan password baru" />

    <x-split at="xl">
        <x-card as="section" title="Password portal" title-id="password-form-title" subtitle="Password yang sama dipakai untuk masuk ke WeCIC dan portal kampus." aria-labelledby="password-form-title">
            <x-card.body class="sm:p-6">
                {{-- Submitted only by the script below: a native submit must never put the passwords in a URL. --}}
                <form id="form-change-password" class="flex flex-col gap-5" method="post" action="javascript:void(0)">
                    <x-field label="Password lama" for="current_password">
                        <div class="relative flex items-center">
                            <input type="password" class="h-11 w-full min-w-0 rounded-lg border border-border bg-panel pe-11 ps-3 text-base text-ink shadow-xs transition-[color,box-shadow,border-color] placeholder:text-ink-subtle focus-visible:border-brand focus-visible:outline-none focus-visible:ring-[3px] focus-visible:ring-brand/20 disabled:cursor-not-allowed disabled:bg-surface disabled:opacity-60 aria-invalid:border-danger aria-invalid:ring-danger/20 sm:h-9 sm:text-sm dark:shadow-none" name="current_password" id="current_password" autocomplete="current-password" aria-describedby="current_password-error" required>
                            <button type="button" class="absolute end-1 top-1/2 z-10 flex size-9 -translate-y-1/2 sm:size-7 cursor-pointer items-center justify-center rounded-md text-ink-subtle transition-colors hover:bg-surface hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40" data-password-toggle data-target="current_password" aria-label="Tampilkan password" aria-pressed="false">
                                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M10 12a2 2 0 1 0 4 0a2 2 0 0 0 -4 0" /><path d="M21 12c-2.4 4 -5.4 6 -9 6c-3.6 0 -6.6 -2 -9 -6c2.4 -4 5.4 -6 9 -6c3.6 0 6.6 2 9 6" /></svg>
                            </button>
                        </div>
                        <p class="text-xs font-medium leading-relaxed text-danger" id="current_password-error" data-field-error="current_password" hidden></p>
                    </x-field>
                    <x-field label="Password baru" for="new_password">
                        <div class="relative flex items-center">
                            <input type="password" class="h-11 w-full min-w-0 rounded-lg border border-border bg-panel pe-11 ps-3 text-base text-ink shadow-xs transition-[color,box-shadow,border-color] placeholder:text-ink-subtle focus-visible:border-brand focus-visible:outline-none focus-visible:ring-[3px] focus-visible:ring-brand/20 disabled:cursor-not-allowed disabled:bg-surface disabled:opacity-60 aria-invalid:border-danger aria-invalid:ring-danger/20 sm:h-9 sm:text-sm dark:shadow-none" name="new_password" id="new_password" autocomplete="new-password" aria-describedby="new_password-error" required>
                            <button type="button" class="absolute end-1 top-1/2 z-10 flex size-9 -translate-y-1/2 sm:size-7 cursor-pointer items-center justify-center rounded-md text-ink-subtle transition-colors hover:bg-surface hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40" data-password-toggle data-target="new_password" aria-label="Tampilkan password" aria-pressed="false">
                                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M10 12a2 2 0 1 0 4 0a2 2 0 0 0 -4 0" /><path d="M21 12c-2.4 4 -5.4 6 -9 6c-3.6 0 -6.6 -2 -9 -6c2.4 -4 5.4 -6 9 -6c3.6 0 6.6 2 9 6" /></svg>
                            </button>
                        </div>
                        <p class="text-xs font-medium leading-relaxed text-danger" id="new_password-error" data-field-error="new_password" hidden></p>
                    </x-field>
                    <x-field label="Konfirmasi password baru" for="new_password_confirmation">
                        <div class="relative flex items-center">
                            <input type="password" class="h-11 w-full min-w-0 rounded-lg border border-border bg-panel pe-11 ps-3 text-base text-ink shadow-xs transition-[color,box-shadow,border-color] placeholder:text-ink-subtle focus-visible:border-brand focus-visible:outline-none focus-visible:ring-[3px] focus-visible:ring-brand/20 disabled:cursor-not-allowed disabled:bg-surface disabled:opacity-60 aria-invalid:border-danger aria-invalid:ring-danger/20 sm:h-9 sm:text-sm dark:shadow-none" name="new_password_confirmation" id="new_password_confirmation" autocomplete="new-password" aria-describedby="new_password_confirmation-error" required>
                            <button type="button" class="absolute end-1 top-1/2 z-10 flex size-9 -translate-y-1/2 sm:size-7 cursor-pointer items-center justify-center rounded-md text-ink-subtle transition-colors hover:bg-surface hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40" data-password-toggle data-target="new_password_confirmation" aria-label="Tampilkan password" aria-pressed="false">
                                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M10 12a2 2 0 1 0 4 0a2 2 0 0 0 -4 0" /><path d="M21 12c-2.4 4 -5.4 6 -9 6c-3.6 0 -6.6 -2 -9 -6c2.4 -4 5.4 -6 9 -6c3.6 0 6.6 2 9 6" /></svg>
                            </button>
                        </div>
                        <p class="text-xs font-medium leading-relaxed text-danger" id="new_password_confirmation-error" data-field-error="new_password_confirmation" hidden></p>
                    </x-field>
                    <x-button type="submit" class="mt-1 w-full sm:w-auto sm:self-end" id="btn-submit" disabled>Simpan password</x-button>
                </form>
            </x-card.body>
        </x-card>

        <x-slot:aside aria-label="Ketentuan password">
            <x-card as="section" title="Ketentuan" title-id="password-rules-title" aria-labelledby="password-rules-title">
                <x-card.body>
                    <ol class="flex flex-col gap-3">
                        @foreach (['Isi password lama yang kamu pakai sekarang.', 'Password baru minimal 6 karakter.', 'Password baru harus berbeda dari password lama.', 'Ketik ulang password baru sama persis di kolom konfirmasi.'] as $rule)
                            <li class="flex gap-3 text-sm">
                                <span class="w-4 shrink-0 text-end font-medium text-ink-subtle tabular-nums">{{ $loop->iteration }}.</span>
                                <span class="text-ink-muted">{{ $rule }}</span>
                            </li>
                        @endforeach
                    </ol>
                </x-card.body>
            </x-card>
        </x-slot:aside>
    </x-split>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', async () => {
            const app = window.portalApp;
            const form = document.getElementById('form-change-password');
            const btnSubmit = document.getElementById('btn-submit');
            let ready = false;

            document.querySelectorAll('[data-password-toggle]').forEach((toggle) => {
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
