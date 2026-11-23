@extends('layouts.app')

@section('title', 'Pengaturan AI')
@section('page_id', 'notebook-ai-settings')
{{-- Nothing on this page comes from a dataset, so there is nothing to sync. --}}
@section('hide_sync_refresh', '1')

{{--
    The student's own Notebook AI provider and API keys (/api/ai-settings).
    Saved keys never come back in full: the page only shows their last four
    characters, and an empty key field keeps the saved key.
--}}
@php
    $keyFields = [
        'gemini' => ['label' => 'Google Gemini', 'field' => 'gemini_api_key', 'placeholder' => 'AIza…', 'hint' => 'Buat di Google AI Studio (aistudio.google.com).'],
        'anthropic' => ['label' => 'Anthropic Claude', 'field' => 'anthropic_api_key', 'placeholder' => 'sk-ant-…', 'hint' => 'Buat di Claude Console (platform.claude.com).'],
        'openai' => ['label' => 'OpenAI-compatible', 'field' => 'openai_api_key', 'placeholder' => 'sk-…', 'hint' => 'OpenAI, OpenRouter, atau layanan lain yang memakai format Chat Completions.'],
    ];
    $providerLabels = array_map(static fn (array $meta): string => $meta['label'], $keyFields);
    $providerKeyFields = array_map(static fn (array $meta): string => $meta['field'], $keyFields);
@endphp

@section('content')
    <x-page-hero :back="route('notebook.index')" back-label="Notebook" title="Pengaturan AI" subtitle="Pakai API key milikmu sendiri untuk tanya jawab dan studio Notebook" />

    <x-split at="xl">
        {{-- Submitted only by the script below: a native submit must never put the keys in a URL. --}}
        <form id="form-ai-settings" class="flex flex-col gap-6" method="post" action="javascript:void(0)" autocomplete="off">
            <x-card as="section" title="Penyedia AI" title-id="ai-provider-title" subtitle="Penyedia yang menjawab pertanyaan dan membuat bahan belajar di Notebook-mu." aria-labelledby="ai-provider-title">
                <x-card.body class="flex flex-col gap-4 sm:p-6">
                    <x-field label="Penyedia" for="provider" hint="Ikuti server memakai penyedia yang dipilih pengelola WeCIC.">
                        <x-select name="provider" id="provider" aria-describedby="provider-hint provider-error">
                            <option value="">Ikuti server</option>
                            @foreach ($keyFields as $provider => $meta)
                                <option value="{{ $provider }}">{{ $meta['label'] }}</option>
                            @endforeach
                        </x-select>
                        <p class="text-xs font-medium leading-relaxed text-danger" id="provider-error" data-field-error="provider" hidden></p>
                    </x-field>
                    <p class="text-sm text-ink-muted" id="ai-active-provider" aria-live="polite"></p>
                </x-card.body>
            </x-card>

            @foreach ($keyFields as $provider => $meta)
                <x-card as="section" :title="$meta['label']" :title-id="'ai-'.$provider.'-title'" :subtitle="$meta['hint']" :aria-labelledby="'ai-'.$provider.'-title'">
                    <x-card.body class="flex flex-col gap-4 sm:p-6">
                        <x-field label="API key" :for="$meta['field']">
                            <x-input type="password" :name="$meta['field']" :id="$meta['field']" :placeholder="$meta['placeholder']" autocomplete="off" spellcheck="false" :aria-describedby="$meta['field'].'-status '.$meta['field'].'-error'" />
                            <p class="text-xs leading-relaxed text-ink-subtle" id="{{ $meta['field'] }}-status" data-key-status="{{ $provider }}">Memuat…</p>
                            <p class="text-xs font-medium leading-relaxed text-danger" id="{{ $meta['field'] }}-error" data-field-error="{{ $meta['field'] }}" hidden></p>
                        </x-field>
                        <label class="hidden items-center gap-2 text-sm text-ink-muted" data-clear-key="{{ $provider }}">
                            <input type="checkbox" class="size-4 rounded border-border text-brand focus-visible:ring-2 focus-visible:ring-brand/40" name="clear_{{ $meta['field'] }}" value="1">
                            Hapus kunci yang tersimpan
                        </label>

                        @if ($provider === 'openai')
                            <x-field label="Alamat layanan (base URL)" for="openai_base_url" hint="Kosongkan untuk memakai alamat server. Harus HTTPS, mis. https://api.openai.com/v1.">
                                <x-input type="url" name="openai_base_url" id="openai_base_url" placeholder="https://api.openai.com/v1" inputmode="url" spellcheck="false" aria-describedby="openai_base_url-hint openai_base_url-error" />
                                <p class="text-xs font-medium leading-relaxed text-danger" id="openai_base_url-error" data-field-error="openai_base_url" hidden></p>
                            </x-field>
                            <x-field label="Model" for="openai_model" hint="Kosongkan untuk memakai model server. Wajib bila alamat layanan diisi.">
                                <x-input name="openai_model" id="openai_model" placeholder="gpt-5-mini" spellcheck="false" aria-describedby="openai_model-hint openai_model-error" />
                                <p class="text-xs font-medium leading-relaxed text-danger" id="openai_model-error" data-field-error="openai_model" hidden></p>
                            </x-field>
                        @endif
                    </x-card.body>
                </x-card>
            @endforeach

            <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                <x-button type="button" variant="ghost" class="text-danger! hover:bg-danger-soft!" id="btn-clear-ai-settings" disabled>Hapus semua pengaturan</x-button>
                <x-button type="submit" id="btn-save-ai-settings" disabled>Simpan pengaturan</x-button>
            </div>
        </form>

        <x-slot:aside aria-label="Tentang API key">
            <x-card as="section" title="Tentang API key" title-id="ai-keys-about-title" aria-labelledby="ai-keys-about-title">
                <x-card.body>
                    <ul class="flex flex-col gap-3 text-sm text-ink-muted">
                        <li>Kunci disimpan terenkripsi dan tidak pernah ditampilkan utuh lagi, hanya empat karakter terakhirnya.</li>
                        <li>Kolom kunci yang dikosongkan tetap memakai kunci yang tersimpan. Bila belum ada, Notebook memakai kunci server WeCIC.</li>
                        <li>Biaya pemakaian kunci milikmu ditagihkan oleh penyedianya ke akunmu.</li>
                        <li>Bila penyedia berganti, kamu perlu menyetujui lagi pengiriman isi Notebook ke penyedia baru.</li>
                    </ul>
                </x-card.body>
            </x-card>
        </x-slot:aside>
    </x-split>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', async () => {
            const app = window.portalApp;
            const form = document.getElementById('form-ai-settings');
            const btnSave = document.getElementById('btn-save-ai-settings');
            const btnClear = document.getElementById('btn-clear-ai-settings');
            const activeProvider = document.getElementById('ai-active-provider');
            const labels = @json($providerLabels);
            const keyFields = @json($providerKeyFields);
            let ready = false;

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

            form.querySelectorAll('input, select').forEach((input) => {
                input.addEventListener('input', () => setFieldError(input.name, ''));
            });

            const render = (data) => {
                const settings = data?.settings;

                if (!settings) {
                    return;
                }

                form.elements.namedItem('provider').value = settings.provider || '';
                form.elements.namedItem('openai_base_url').value = settings.providers.openai.base_url || '';
                form.elements.namedItem('openai_model').value = settings.providers.openai.model || '';

                Object.entries(keyFields).forEach(([provider, field]) => {
                    const state = settings.providers[provider];
                    const input = form.elements.namedItem(field);
                    const status = form.querySelector(`[data-key-status="${provider}"]`);
                    const clear = form.querySelector(`[data-clear-key="${provider}"]`);

                    input.value = '';
                    clear.querySelector('input').checked = false;
                    clear.classList.toggle('hidden', !state.has_key);
                    clear.classList.toggle('flex', state.has_key);

                    if (state.has_key) {
                        status.textContent = `Kunci milikmu tersimpan (${state.key_preview}). Kosongkan kolom untuk tetap memakainya.`;
                    } else if (state.server_configured) {
                        status.textContent = 'Belum ada kunci milikmu; Notebook memakai kunci server WeCIC.';
                    } else {
                        status.textContent = 'Belum ada kunci. Server WeCIC juga belum punya kunci untuk penyedia ini.';
                    }
                });

                const active = labels[settings.active_provider] || 'penyedia AI server';
                activeProvider.textContent = settings.provider
                    ? `Notebook-mu memakai ${active}.`
                    : `Notebook-mu mengikuti server dan memakai ${active}.`;

                btnClear.disabled = !ready || !(settings.provider || Object.keys(keyFields).some((provider) => settings.providers[provider].has_key) || settings.providers.openai.base_url || settings.providers.openai.model);
            };

            const afterChange = (response) => {
                render(response?.data);

                if (response?.data?.consent && !response.data.consent.granted) {
                    app.showNotice(response.message, 'info');
                } else {
                    app.showNotice(response?.message || 'Pengaturan AI disimpan.', 'success');
                }
            };

            form.addEventListener('submit', async (e) => {
                e.preventDefault();

                if (!ready) {
                    return;
                }

                clearFieldErrors();

                const formData = new FormData(form);
                const payload = {
                    provider: formData.get('provider') || null,
                    openai_base_url: formData.get('openai_base_url') || null,
                    openai_model: formData.get('openai_model') || null,
                };

                Object.values(keyFields).forEach((field) => {
                    const key = String(formData.get(field) || '').trim();

                    if (key) {
                        payload[field] = key;
                    }

                    if (formData.get(`clear_${field}`)) {
                        payload[`clear_${field}`] = true;
                    }
                });

                await app.withSubmitLoading(btnSave, async () => {
                    try {
                        afterChange(await app.request('/api/ai-settings', { method: 'PUT', json: payload }));
                    } catch (error) {
                        if (!showFieldErrors(error.payload?.errors)) {
                            app.showNotice(error.payload?.message || error.message, 'error');
                        }
                    }
                });
            });

            btnClear.addEventListener('click', async () => {
                if (!ready || !window.confirm('Hapus penyedia dan semua API key milikmu? Notebook kembali memakai pengaturan server WeCIC.')) {
                    return;
                }

                clearFieldErrors();

                await app.withSubmitLoading(btnClear, async () => {
                    try {
                        afterChange(await app.request('/api/ai-settings', { method: 'DELETE' }));
                    } catch (error) {
                        app.showNotice(error.payload?.message || error.message, 'error');
                    }
                });
            });

            ready = Boolean(await app.ensureAuthenticated());

            if (!ready) {
                return;
            }

            try {
                render((await app.request('/api/ai-settings'))?.data);
                btnSave.disabled = false;
            } catch (error) {
                app.showNotice(error.payload?.message || error.message, 'error');
            }
        });
    </script>
@endpush
