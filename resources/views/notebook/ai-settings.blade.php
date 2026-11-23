@extends('layouts.app')

@section('title', 'Pengaturan AI')
@section('page_id', 'notebook-ai-settings')
{{-- Nothing on this page comes from a dataset, so there is nothing to sync. --}}
@section('hide_sync_refresh', '1')

{{--
    The student's own Notebook AI settings (/api/ai-settings), built around
    one decision: who answers the Notebook. Only the chosen provider's
    credentials are shown; what is in effect is spelled out above it. Saved
    keys never come back in full (only their last four characters), and a
    key is removed by its own confirmed action, never by a global save.
--}}
@php
    $providers = [
        'gemini' => ['label' => \App\Models\NotebookAiSetting::LABELS['gemini'], 'description' => 'Google', 'source' => 'Dari Google AI Studio (aistudio.google.com).'],
        'anthropic' => ['label' => \App\Models\NotebookAiSetting::LABELS['anthropic'], 'description' => 'Anthropic', 'source' => 'Dari Claude Console (platform.claude.com).'],
        'openai' => ['label' => \App\Models\NotebookAiSetting::LABELS['openai'], 'description' => 'OpenAI, OpenRouter, atau layanan sejenis', 'source' => 'Dari layanan yang dipakai.'],
    ];
    $providerLabels = array_map(static fn (array $provider): string => $provider['label'], $providers);
    $optionClass = 'flex min-h-11 cursor-pointer flex-col gap-1 rounded-lg border border-border bg-panel p-4 transition-colors hover:bg-surface/60 has-checked:border-brand has-checked:ring-2 has-checked:ring-brand/20 has-focus-visible:ring-2 has-focus-visible:ring-brand/40 has-disabled:cursor-not-allowed has-disabled:opacity-60';
    $dotClass = 'flex size-4 shrink-0 items-center justify-center rounded-full border border-border bg-panel group-has-checked:border-brand after:size-2 after:rounded-full after:bg-transparent group-has-checked:after:bg-brand';
    $passwordInputClass = 'h-11 w-full min-w-0 rounded-lg border border-border bg-panel pe-11 ps-3 text-base text-ink shadow-xs transition-[color,box-shadow,border-color] placeholder:text-ink-subtle focus-visible:border-brand focus-visible:outline-none focus-visible:ring-[3px] focus-visible:ring-brand/20 disabled:cursor-not-allowed disabled:bg-surface disabled:opacity-60 aria-invalid:border-danger aria-invalid:ring-danger/20 sm:h-9 sm:text-sm dark:shadow-none';
    $eyeIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M10 12a2 2 0 1 0 4 0a2 2 0 0 0 -4 0" /><path d="M21 12c-2.4 4 -5.4 6 -9 6c-3.6 0 -6.6 -2 -9 -6c2.4 -4 5.4 -6 9 -6c3.6 0 6.6 2 9 6" /></svg>';
@endphp

@section('content')
    <x-page-hero :back="route('notebook.index')" back-label="Notebook" title="Pengaturan AI" />

    <x-split at="xl">
        {{-- Submitted only by the script below: a native submit must never put the keys in a URL. --}}
        <form id="form-ai-settings" class="flex flex-col gap-6" method="post" action="javascript:void(0)" autocomplete="off">
            {{-- 1. What is in effect now: the SAVED state, never the draft. --}}
            <x-card as="section" title="Yang dipakai sekarang" title-id="ai-now-title" aria-labelledby="ai-now-title" id="ai-now" tabindex="-1" class="focus:outline-none">
                <x-card.body class="flex flex-col gap-4 sm:p-6">
                    <div class="flex flex-col gap-2" id="ai-now-loading">
                        <span class="sr-only">Memuat pengaturan AI…</span>
                        <x-skeleton class="h-4 w-4/5" />
                        <x-skeleton class="h-4 w-3/5" />
                    </div>
                    <div class="hidden flex-col gap-4" id="ai-now-content">
                        <p class="text-sm text-ink" id="ai-now-summary" aria-live="polite"></p>
                        <dl class="flex flex-col divide-y divide-border-light" id="ai-now-rows"></dl>
                        <div id="ai-now-alert"></div>
                    </div>
                    <div class="hidden flex-col gap-3" id="ai-now-error">
                        <x-alert tone="danger" icon title="Pengaturan AI belum bisa dimuat" role="alert"><span id="ai-now-error-message"></span></x-alert>
                        <div><x-button type="button" variant="secondary" size="sm" id="btn-ai-retry">Coba lagi</x-button></div>
                    </div>
                </x-card.body>
            </x-card>

            {{-- 2. The one decision. Only the chosen option's credentials panel is shown, right under it. --}}
            <x-card as="section" title="Siapa yang menjawab Notebook-mu?" title-id="ai-provider-title" aria-labelledby="ai-provider-title">
                <x-card.body class="flex flex-col gap-3 sm:p-6">
                    <fieldset class="flex flex-col gap-3" id="ai-provider-options" inert aria-busy="true">
                        <legend class="sr-only">Penyedia AI Notebook</legend>

                        <label class="group {{ $optionClass }}" data-provider-option="">
                            <input type="radio" name="provider" value="" class="sr-only" aria-describedby="ai-option-desc-server ai-option-badge-server">
                            <span class="flex flex-wrap items-center gap-2">
                                <span class="{{ $dotClass }}" aria-hidden="true"></span>
                                <span class="text-sm font-medium text-ink">Ikuti server WeCIC</span>
                                <span class="hidden text-xs text-ink-subtle" data-saved-tag>Tersimpan</span>
                                <span class="ms-auto" id="ai-option-badge-server" data-option-badge></span>
                            </span>
                            <span class="ps-6 text-xs text-ink-muted" id="ai-option-desc-server" data-option-description>Memuat penyedia server…</span>
                            <span class="hidden ps-6 text-xs text-ink-muted" data-option-extra></span>
                        </label>

                        @foreach ($providers as $provider => $meta)
                            <label class="group {{ $optionClass }}" data-provider-option="{{ $provider }}">
                                <input type="radio" name="provider" value="{{ $provider }}" class="sr-only" aria-describedby="ai-option-desc-{{ $provider }} ai-option-badge-{{ $provider }}" aria-controls="ai-panel-{{ $provider }}">
                                <span class="flex flex-wrap items-center gap-2">
                                    <span class="{{ $dotClass }}" aria-hidden="true"></span>
                                    <span class="text-sm font-medium text-ink">{{ $meta['label'] }}</span>
                                    <span class="hidden text-xs text-ink-subtle" data-saved-tag>Tersimpan</span>
                                    <span class="ms-auto" id="ai-option-badge-{{ $provider }}" data-option-badge></span>
                                </span>
                                <span class="ps-6 text-xs text-ink-muted" id="ai-option-desc-{{ $provider }}">{{ $meta['description'] }}</span>
                            </label>

                            {{-- The credentials of {{ $provider }}; moved under the "Ikuti server" option when that follows this provider. --}}
                            <div class="flex flex-col gap-4 rounded-lg bg-surface p-4" id="ai-panel-{{ $provider }}" data-provider-panel="{{ $provider }}" hidden>
                                <div class="flex flex-col gap-1">
                                    <h3 class="text-sm font-medium text-ink" data-panel-heading>API key {{ $meta['label'] }}</h3>
                                    <p class="text-xs leading-relaxed text-ink-muted" data-panel-explainer></p>
                                </div>

                                @if ($provider === 'openai')
                                    <fieldset class="flex flex-col gap-2">
                                        <legend class="mb-2 text-sm font-medium text-ink">Layanan yang dipakai</legend>
                                        <label class="group flex cursor-pointer items-start gap-3 rounded-lg border border-border bg-panel p-3 has-checked:border-brand has-checked:ring-2 has-checked:ring-brand/20 has-focus-visible:ring-2 has-focus-visible:ring-brand/40 has-disabled:cursor-not-allowed has-disabled:opacity-60">
                                            <input type="radio" name="openai_endpoint" value="server" class="sr-only" aria-describedby="ai-openai-server-desc">
                                            <span class="mt-0.5 {{ $dotClass }}" aria-hidden="true"></span>
                                            <span class="flex flex-col gap-0.5">
                                                <span class="text-sm font-medium text-ink">Layanan server WeCIC</span>
                                                <span class="text-xs text-ink-muted" id="ai-openai-server-desc">Alamat &amp; model dari server WeCIC.</span>
                                            </span>
                                        </label>
                                        <label class="group flex cursor-pointer items-start gap-3 rounded-lg border border-border bg-panel p-3 has-checked:border-brand has-checked:ring-2 has-checked:ring-brand/20 has-focus-visible:ring-2 has-focus-visible:ring-brand/40">
                                            <input type="radio" name="openai_endpoint" value="own" class="sr-only" aria-describedby="ai-openai-own-desc">
                                            <span class="mt-0.5 {{ $dotClass }}" aria-hidden="true"></span>
                                            <span class="flex flex-col gap-0.5">
                                                <span class="text-sm font-medium text-ink">Layanan milikmu sendiri</span>
                                                <span class="text-xs text-ink-muted" id="ai-openai-own-desc">Alamat, model &amp; API key sendiri (tanpa baca PDF/gambar).</span>
                                            </span>
                                        </label>
                                    </fieldset>

                                    <x-field label="Alamat layanan (base URL)" for="openai_base_url" hint="HTTPS, mis. https://api.openai.com/v1" data-openai-own-only hidden>
                                        <x-input type="url" name="openai_base_url" id="openai_base_url" inputmode="url" spellcheck="false" aria-describedby="openai_base_url-hint openai_base_url-error" />
                                        <p class="text-xs font-medium leading-relaxed text-danger" id="openai_base_url-error" data-field-error="openai_base_url" hidden></p>
                                    </x-field>
                                    <div class="flex flex-col gap-1.5">
                                        <label for="openai_model" class="text-sm font-medium text-ink" data-model-label>Model lain (opsional)</label>
                                        <x-input name="openai_model" id="openai_model" spellcheck="false" aria-describedby="openai_model-hint openai_model-error" />
                                        <p class="text-xs leading-relaxed text-ink-subtle" id="openai_model-hint" data-model-hint>Opsional · mis. gpt-5-mini</p>
                                        <p class="text-xs font-medium leading-relaxed text-danger" id="openai_model-error" data-field-error="openai_model" hidden></p>
                                    </div>
                                    <x-alert tone="info" icon data-openai-own-notice hidden>
                                        <span data-openai-own-notice-text></span>
                                    </x-alert>
                                @endif

                                {{-- The saved key, as a row of its own with its own actions. --}}
                                <div class="hidden flex-wrap items-center justify-between gap-3 rounded-md border border-border bg-panel px-3 py-2" data-saved-key-row>
                                    <span class="flex min-w-0 flex-wrap items-center gap-2 text-sm text-ink">
                                        <span class="text-ink-muted">Kunci tersimpan</span>
                                        <span class="break-all font-mono" aria-hidden="true" data-saved-key-mask></span><span class="sr-only" data-saved-key-mask-sr></span>
                                        <x-badge tone="success">Tersimpan</x-badge>
                                    </span>
                                    <span class="flex flex-wrap gap-2">
                                        <x-button type="button" variant="secondary" size="sm" data-key-replace aria-label="Ganti kunci {{ $meta['label'] }}">Ganti</x-button>
                                        <x-button type="button" variant="ghost" size="sm" class="text-danger! hover:bg-danger-soft!" data-key-remove="{{ $provider }}" aria-label="Hapus kunci {{ $meta['label'] }}">Hapus</x-button>
                                    </span>
                                </div>

                                {{-- A new key: shown when none is saved, or after "Ganti". --}}
                                <div class="flex flex-col gap-1.5" data-key-input hidden>
                                    <label for="{{ $provider }}_api_key" class="text-sm font-medium text-ink">Tempel API key {{ $meta['label'] }}</label>
                                    <div class="relative flex items-center">
                                        <input type="password" class="{{ $passwordInputClass }}" name="{{ $provider }}_api_key" id="{{ $provider }}_api_key" autocomplete="one-time-code" data-1p-ignore data-lpignore="true" data-bwignore spellcheck="false" aria-describedby="{{ $provider }}_api_key-hint {{ $provider }}_api_key-error">
                                        <button type="button" class="absolute end-1 top-1/2 z-10 flex size-9 -translate-y-1/2 cursor-pointer items-center justify-center rounded-md text-ink-subtle transition-colors hover:bg-surface hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40 sm:size-7" data-password-toggle data-target="{{ $provider }}_api_key" aria-label="Tampilkan kunci" aria-pressed="false">{!! $eyeIcon !!}</button>
                                    </div>
                                    <p class="text-xs leading-relaxed text-ink-subtle" id="{{ $provider }}_api_key-hint">{{ $meta['source'] }}</p>
                                    <p class="text-xs font-medium leading-relaxed text-danger" id="{{ $provider }}_api_key-error" data-field-error="{{ $provider }}_api_key" hidden></p>
                                    <div class="hidden flex-wrap items-center justify-between gap-2" data-replace-note>
                                        <p class="text-xs text-ink-muted" data-replace-note-text></p>
                                        <x-button type="button" variant="ghost" size="sm" data-key-replace-cancel>Batal ganti</x-button>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </fieldset>
                    <p class="text-xs font-medium leading-relaxed text-danger" data-field-error="provider" hidden></p>
                </x-card.body>
                <x-card.footer class="flex-col items-stretch sm:flex-row sm:items-center sm:justify-between">
                    <div class="min-w-0 flex-1" id="ai-save-consequence" aria-live="polite">
                        <p class="text-xs text-ink-subtle">Belum ada perubahan.</p>
                    </div>
                    <x-button type="submit" id="btn-save-ai-settings" class="w-full sm:w-auto" disabled>Simpan</x-button>
                </x-card.footer>
            </x-card>

            {{-- 3. Saved keys that are not in use now. --}}
            <x-card as="section" id="ai-other" hidden title="Kunci lain yang tersimpan" title-id="ai-other-title" aria-labelledby="ai-other-title">
                <ul class="flex flex-col divide-y divide-border-light" id="ai-other-list"></ul>
            </x-card>
        </form>

        <x-slot:aside aria-label="Pengaturan server">
            <x-card as="section" title="Kembalikan ke pengaturan server" title-id="ai-reset-title" aria-labelledby="ai-reset-title">
                <x-card.body>
                    <p class="text-sm text-ink-muted" id="ai-reset-empty">Belum ada pengaturan milikmu yang tersimpan.</p>
                    <x-button type="button" variant="secondary" class="text-danger! hover:bg-danger-soft!" id="btn-clear-ai-settings" hidden>Hapus semua pengaturan AI-ku</x-button>
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
            const options = document.getElementById('ai-provider-options');
            const btnSave = document.getElementById('btn-save-ai-settings');
            const btnClear = document.getElementById('btn-clear-ai-settings');
            const consequence = document.getElementById('ai-save-consequence');
            const nowSection = document.getElementById('ai-now');
            const LABELS = @json($providerLabels);
            const PROVIDERS = Object.keys(LABELS);
            const SHORT_MASK = '••••••••';
            const eyeIcon = @json($eyeIcon);
            const eyeOffIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M10.585 10.587a2 2 0 0 0 2.829 2.828" /><path d="M16.681 16.673a8.717 8.717 0 0 1 -4.681 1.327c-3.6 0 -6.6 -2 -9 -6c1.272 -2.12 2.712 -3.678 4.32 -4.674m2.86 -1.146a9.055 9.055 0 0 1 1.82 -.18c3.6 0 6.6 2 9 6c-.666 1.11 -1.379 2.067 -2.138 2.87" /><path d="M3 3l18 18" /></svg>';

            /** The last response (`data` of /api/ai-settings): what is saved. */
            let saved = null;
            let replacing = {};
            /** A save or removal is in flight: the footer leaves the Save button alone and a second submit is ignored. */
            let saving = false;

            const esc = (value) => app.escapeHtml(String(value ?? ''));
            const panelOf = (provider) => document.getElementById(`ai-panel-${provider}`);
            const keyInput = (provider) => form.elements.namedItem(`${provider}_api_key`);
            const optionOf = (value) => options.querySelector(`[data-provider-option="${value}"]`);
            const radioOf = (value) => optionOf(value)?.querySelector('input[name="provider"]');
            const settings = () => saved?.settings;
            const providerState = (provider) => settings()?.providers?.[provider] || {};
            const isKnown = (provider) => PROVIDERS.includes(provider);

            /** A saved key as the page prints it: its last four characters, or that one is saved when it is too short to show any. */
            const maskText = (preview) => (preview && preview !== SHORT_MASK ? `(${preview})` : '(kunci milikmu tersimpan)');
            const maskShort = (preview) => (preview && preview !== SHORT_MASK ? preview : 'tersimpan');

            const selectedProvider = () => form.querySelector('input[name="provider"]:checked')?.value ?? '';
            /** The panel the current choice shows: the provider itself, or the server's when following it (none when the server names none). */
            const panelProviderFor = (choice) => (choice ? choice : (isKnown(settings()?.server_provider) ? settings().server_provider : null));
            const openaiEndpoint = () => form.querySelector('input[name="openai_endpoint"]:checked')?.value || 'server';

            /** Empty a key field and hide it again, so the next key is never shown in clear text unasked. */
            const clearKeyInput = (provider) => {
                const input = keyInput(provider);
                const toggle = form.querySelector(`[data-password-toggle][data-target="${provider}_api_key"]`);

                input.value = '';
                input.type = 'password';
                toggle.setAttribute('aria-pressed', 'false');
                toggle.setAttribute('aria-label', 'Tampilkan kunci');
                toggle.innerHTML = eyeIcon;
            };

            /** Whether a provider's saved key is in use: it answers questions, or it reads scanned PDFs and images. */
            const usedFor = (provider) => {
                const s = settings();

                return {
                    chat: s.effective.provider === provider,
                    ingest: s.ingest?.provider === provider,
                };
            };

            // ---- field errors (the password page's pattern) ----
            const setFieldError = (name, message) => {
                const errorNode = form.querySelector(`[data-field-error="${name}"]`);
                const input = form.elements.namedItem(name);

                if (!errorNode) {
                    return false;
                }

                errorNode.textContent = message || '';
                errorNode.hidden = !message;
                if (input instanceof HTMLElement) {
                    if (message) {
                        input.setAttribute('aria-invalid', 'true');
                    } else {
                        input.removeAttribute('aria-invalid');
                    }
                }

                return true;
            };

            const clearFieldErrors = () => {
                form.querySelectorAll('[data-field-error]').forEach((node) => setFieldError(node.dataset.fieldError, ''));
            };

            const showFieldErrors = (errors) => {
                const entries = Object.entries(errors || {}).map(([name, messages]) => {
                    // A provider that cannot answer is fixed in its key field, when one is open.
                    if (name === 'provider') {
                        const panelProvider = panelProviderFor(selectedProvider());
                        const input = panelProvider ? keyInput(panelProvider) : null;

                        if (input && !input.closest('[data-key-input]').hidden) {
                            return [input.name, messages];
                        }
                    }

                    return [name, messages];
                });
                const shown = entries.filter(([name, messages]) => setFieldError(name, [].concat(messages)[0]));
                const first = shown.length ? form.elements.namedItem(shown[0][0]) : null;

                if (first instanceof HTMLElement && !(first instanceof RadioNodeList)) {
                    first.focus();
                } else if (shown.length) {
                    radioOf(selectedProvider())?.focus();
                }

                return shown.length > 0;
            };

            form.querySelectorAll('input').forEach((input) => {
                input.addEventListener('input', () => {
                    setFieldError(input.name, '');
                    updateFooter();
                });
            });

            form.querySelectorAll('[data-password-toggle]').forEach((toggle) => {
                toggle.addEventListener('click', (event) => {
                    event.preventDefault();
                    const input = document.getElementById(toggle.dataset.target);
                    const show = input.type === 'password';

                    input.type = show ? 'text' : 'password';
                    toggle.setAttribute('aria-pressed', show ? 'true' : 'false');
                    toggle.setAttribute('aria-label', show ? 'Sembunyikan kunci' : 'Tampilkan kunci');
                    toggle.innerHTML = show ? eyeOffIcon : eyeIcon;
                    input.focus({ preventScroll: true });
                });
            });

            // ---- status card (saved state) ----
            const row = (label, valueHtml, hint = '') => `
                <div class="flex flex-col gap-1 py-2.5 first:pt-0 last:pb-0 sm:flex-row sm:items-start sm:justify-between sm:gap-4">
                    <dt class="text-sm text-ink-muted">${esc(label)}</dt>
                    <dd class="flex min-w-0 flex-col gap-1 text-sm text-ink sm:items-end sm:text-end">
                        <span class="flex flex-wrap items-center gap-2 sm:justify-end">${valueHtml}</span>
                        ${hint ? `<span class="text-xs text-ink-subtle">${esc(hint)}</span>` : ''}
                    </dd>
                </div>`;

            const keySourceBadge = (source, preview) => {
                if (source === 'own') {
                    return app.renderBadge(preview && preview !== SHORT_MASK ? `Kunci milikmu ${preview}` : 'Kunci milikmu', 'brand');
                }

                return source === 'server' ? app.renderBadge('Kunci server WeCIC', 'secondary') : app.renderBadge('Belum ada kunci', 'danger');
            };

            const summaryText = () => {
                const s = settings();
                const effective = s.effective;
                const label = effective.label;
                const preview = providerState(effective.provider).key_preview;

                if (effective.provider === 'unknown') {
                    return 'Server WeCIC belum mengatur penyedia AI.';
                }

                if (!effective.ready) {
                    return `Notebook belum bisa memakai AI: belum ada API key ${label}.`;
                }

                if (effective.endpoint === 'own') {
                    const model = providerState('openai').model;

                    return `Notebook-mu memakai layananmu di ${effective.endpoint_host}${model ? ` (model ${model})` : ''} dengan kunci milikmu ${maskText(preview)}.`;
                }

                const whose = effective.key_source === 'own' ? `kunci milikmu ${maskText(preview)}` : 'kunci server WeCIC';

                return s.provider
                    ? `Notebook-mu memakai ${label} dengan ${whose}.`
                    : `Notebook-mu mengikuti server WeCIC: ${label} dengan ${whose}.`;
            };

            const renderStatus = () => {
                const s = settings();
                const consent = saved.consent || {};
                const effective = s.effective;
                const rows = [];

                document.getElementById('ai-now-summary').textContent = summaryText();

                rows.push(row('Penyedia', `<span>${esc(effective.endpoint === 'own' ? `Layananmu di ${effective.endpoint_host}` : effective.label)}</span>${s.provider ? app.renderBadge('Pilihanmu', 'brand') : app.renderBadge('Ikut server', 'secondary')}`));
                rows.push(row('API key', keySourceBadge(effective.key_source, providerState(effective.provider).key_preview)));

                if (s.ingest) {
                    rows.push(row('PDF pindaian & gambar', `<span>${esc(s.ingest.label)} · ${s.ingest.key_source === 'own' ? 'kunci milikmu' : (s.ingest.key_source === 'server' ? 'kunci server WeCIC' : 'belum ada kunci')}</span>`, 'Server WeCIC mengirim file seperti ini ke penyedia terpisah.'));
                }

                if (effective.provider !== 'unknown' && effective.ready) {
                    rows.push(row(
                        'Persetujuan AI',
                        `${consent.granted ? app.renderBadge('Sudah disetujui', 'success') : app.renderBadge(consent.withdrawn_at || consent.granted_at ? 'Perlu disetujui lagi' : 'Perlu disetujui', 'warning')}${consent.free_tier ? app.renderBadge('Paket gratis', 'warning') : ''}`,
                        consent.free_tier ? 'Di paket gratis, penyedia bisa menyimpan dan memakai isi yang dikirim.' : ''
                    ));
                }

                document.getElementById('ai-now-rows').innerHTML = rows.join('');

                const alertHost = document.getElementById('ai-now-alert');

                const notReady = (message) => `<div class="${app.ui.alert.danger}" role="status"><p class="font-medium">Notebook belum bisa memakai AI</p><p class="mt-1 text-ink-muted">${esc(message)}</p></div>`;

                if (effective.provider === 'unknown') {
                    alertHost.innerHTML = notReady('Server WeCIC belum mengatur penyedia AI. Pilih penyedia di bawah; bila server tidak punya kuncinya, isi API key milikmu.');
                } else if (!effective.ready && effective.provider === 'openai' && !providerState('openai').server_endpoint) {
                    alertHost.innerHTML = notReady('Server WeCIC belum menyediakan layanan OpenAI-compatible. Pilih "Layanan milikmu sendiri" dan isi alamat, model, serta API key-mu, atau pilih penyedia lain.');
                } else if (!effective.ready) {
                    alertHost.innerHTML = notReady(`Server WeCIC belum punya API key ${effective.label}. Isi API key milikmu di bawah, atau pilih penyedia lain.`);
                } else if (!consent.granted) {
                    alertHost.innerHTML = `<div class="${app.ui.alert.warning} flex flex-col gap-3" role="status"><div><p class="font-medium">Satu langkah lagi</p><p class="mt-1 text-ink-muted">Sebelum AI dipakai, Notebook meminta persetujuanmu untuk mengirim isi pertanyaan dan sumber ke ${esc(consent.provider)}. Persetujuannya diminta saat kamu membuka salah satu notebook.</p></div><div><a class="${app.ui.button.secondarySm}" href="{{ route('notebook.index') }}">Buka Notebook</a></div></div>`;
                } else {
                    alertHost.innerHTML = '';
                }

                document.getElementById('ai-now-loading').classList.add('hidden');
                document.getElementById('ai-now-error').classList.add('hidden');
                document.getElementById('ai-now-error').classList.remove('flex');
                document.getElementById('ai-now-content').classList.remove('hidden');
                document.getElementById('ai-now-content').classList.add('flex');
            };

            // ---- decision card ----
            const optionBadge = (provider) => {
                const state = providerState(provider);

                if (provider === 'openai' && state.base_url) {
                    return app.renderBadge('Layanan milikmu', 'brand');
                }

                if (state.has_key) {
                    return app.renderBadge('Kunci milikmu', 'brand');
                }

                return state.server_configured ? app.renderBadge('Kunci server', 'secondary') : app.renderBadge('Perlu kunci milikmu', 'warning');
            };

            const renderOptions = () => {
                const s = settings();
                const serverProvider = s.server_provider;
                const serverKnown = isKnown(serverProvider);
                const serverOption = optionOf('');

                serverOption.querySelector('[data-option-description]').textContent = serverKnown
                    ? `Server sekarang memakai ${s.server_provider_label}.`
                    : 'Server WeCIC belum memilih penyedia.';

                const serverState = serverKnown ? providerState(serverProvider) : {};
                serverOption.querySelector('[data-option-badge]').innerHTML = serverKnown && serverState.has_key
                    ? app.renderBadge('Kunci milikmu', 'brand')
                    : (s.server_ready ? app.renderBadge('Kunci server', 'secondary') : app.renderBadge('Server belum siap', 'danger'));

                const extra = serverOption.querySelector('[data-option-extra]');
                extra.textContent = serverKnown && serverState.has_key
                    ? `Kunci ${s.server_provider_label} (${maskText(serverState.key_preview)}) ikut dipakai.`
                    : '';
                extra.classList.toggle('hidden', !extra.textContent);

                PROVIDERS.forEach((provider) => {
                    optionOf(provider).querySelector('[data-option-badge]').innerHTML = optionBadge(provider);
                });

                options.querySelectorAll('[data-provider-option]').forEach((option) => {
                    option.querySelector('[data-saved-tag]').classList.toggle('hidden', option.dataset.providerOption !== (s.provider ?? ''));
                });
            };

            // ---- credentials panels ----
            const renderPanel = (provider) => {
                const panel = panelOf(provider);
                const state = providerState(provider);
                const label = LABELS[provider];
                const following = selectedProvider() === '';
                const ownEndpoint = provider === 'openai' && openaiEndpoint() === 'own';
                const hasKey = Boolean(state.has_key);
                const keyRequired = ownEndpoint || !state.server_configured;

                panel.querySelector('[data-panel-heading]').textContent = following && !keyRequired && !hasKey ? `API key ${label} (opsional)` : `API key ${label}`;
                panel.querySelector('[data-panel-explainer]').textContent = hasKey
                    ? `Dipakai dari kunci milikmu (${label}).`
                    : (keyRequired
                        ? (ownEndpoint ? 'Wajib memakai kuncimu sendiri.' : `Wajib. Server belum punya kunci ${label}.`)
                        : 'Opsional. Kosongkan untuk kunci server.');

                const savedRow = panel.querySelector('[data-saved-key-row]');
                savedRow.classList.toggle('hidden', !hasKey);
                savedRow.classList.toggle('flex', hasKey);
                panel.querySelector('[data-saved-key-mask]').textContent = maskShort(state.key_preview);
                panel.querySelector('[data-saved-key-mask-sr]').textContent = state.key_preview && state.key_preview !== SHORT_MASK ? `berakhiran ${state.key_preview.slice(-4)}` : 'kunci milikmu';

                const inputBox = panel.querySelector('[data-key-input]');
                const isReplacing = Boolean(replacing[provider]);
                inputBox.hidden = hasKey && !isReplacing;
                const note = panel.querySelector('[data-replace-note]');
                note.classList.toggle('hidden', !isReplacing);
                note.classList.toggle('flex', isReplacing);
                panel.querySelector('[data-replace-note-text]').textContent = `Kunci baru menggantikan ${maskShort(state.key_preview)} setelah disimpan.`;

                if (provider === 'openai') {
                    const serverEndpoint = Boolean(state.server_endpoint);
                    const serverRadio = form.querySelector('input[name="openai_endpoint"][value="server"]');
                    serverRadio.disabled = !serverEndpoint;
                    document.getElementById('ai-openai-server-desc').textContent = serverEndpoint
                        ? 'Alamat & model dari server WeCIC.'
                        : 'Server WeCIC belum menyediakan layanan OpenAI-compatible.';

                    panel.querySelectorAll('[data-openai-own-only]').forEach((node) => { node.hidden = !ownEndpoint; });
                    panel.querySelector('[data-model-label]').textContent = ownEndpoint ? 'Model' : 'Model lain (opsional)';
                    panel.querySelector('[data-model-hint]').textContent = ownEndpoint
                        ? 'Wajib · mis. gpt-5-mini'
                        : 'Opsional · mis. gpt-5-mini';

                    const notice = panel.querySelector('[data-openai-own-notice]');
                    let host = '';
                    try {
                        host = new URL(form.elements.namedItem('openai_base_url').value).host;
                    } catch {
                        host = '';
                    }
                    panel.querySelector('[data-openai-own-notice-text]').textContent = `Isi Notebook dikirim ke ${host || 'layananmu'}. Selain api.openai.com butuh persetujuan ulang.`;
                    notice.hidden = !ownEndpoint;
                }
            };

            /** Show the chosen option's panel right under it; hide (and empty) every other one so a key typed there is never sent. */
            const showPanel = () => {
                const choice = selectedProvider();
                const visible = panelProviderFor(choice);

                PROVIDERS.forEach((provider) => {
                    const panel = panelOf(provider);
                    const show = provider === visible;

                    if (!show && !panel.hidden) {
                        clearKeyInput(provider);
                        replacing[provider] = false;
                    }

                    panel.hidden = !show;

                    if (show) {
                        optionOf(choice).after(panel);
                        renderPanel(provider);
                    }
                });

                updateFooter();
            };

            // ---- dirty, blocking and what saving will do ----
            const draft = () => {
                const choice = selectedProvider();
                const visible = panelProviderFor(choice);
                const key = visible ? keyInput(visible).value.trim() : '';
                const openaiVisible = visible === 'openai';

                return {
                    choice,
                    visible,
                    key,
                    openaiVisible,
                    endpoint: openaiVisible ? openaiEndpoint() : null,
                    baseUrl: openaiVisible ? form.elements.namedItem('openai_base_url').value.trim() : '',
                    model: openaiVisible ? form.elements.namedItem('openai_model').value.trim() : '',
                };
            };

            const openaiChanged = (d) => {
                if (!d.openaiVisible) {
                    return false;
                }

                const state = providerState('openai');
                const savedEndpoint = state.base_url || !state.server_endpoint ? 'own' : 'server';

                return d.endpoint !== savedEndpoint
                    || (d.endpoint === 'own' && d.baseUrl !== (state.base_url || ''))
                    || d.model !== (state.model || '');
            };

            const assess = () => {
                const s = settings();
                const d = draft();
                const providerChanged = d.choice !== (s.provider ?? '');
                const dirty = providerChanged || d.key !== '' || openaiChanged(d);
                let blocked = '';

                if (d.visible === 'openai' && d.endpoint === 'own') {
                    if (!d.baseUrl) {
                        blocked = 'Isi alamat layananmu.';
                    } else if (!d.model) {
                        blocked = 'Isi nama model layananmu.';
                    } else if (!providerState('openai').has_key && !d.key) {
                        blocked = 'Tempel API key untuk layananmu.';
                    }
                } else if (d.visible && d.choice) {
                    const state = providerState(d.visible);

                    if (!state.usable && !state.has_key && !d.key) {
                        blocked = `Tempel API key ${LABELS[d.visible]} dulu untuk memakai pilihan ini.`;
                    }
                }

                const projected = d.choice || s.server_provider;
                const projectedHost = d.endpoint === 'own' ? (() => { try { return new URL(d.baseUrl).host; } catch { return ''; } })() : null;
                const savedOwn = s.effective.endpoint === 'own';
                const switching = projected !== s.effective.provider
                    || (d.openaiVisible && ((d.endpoint === 'own') !== savedOwn || (d.endpoint === 'own' && projectedHost !== (s.effective.endpoint_host || ''))));
                const newLabel = d.endpoint === 'own' ? `layananmu${projectedHost ? ` di ${projectedHost}` : ''}` : (d.choice ? LABELS[d.choice] : (s.server_provider_label || 'penyedia server'));
                const buttonLabel = d.endpoint === 'own' ? 'layananmu' : newLabel;
                /** A different provider surely needs a new consent; only another endpoint of the same one may not. */
                const surelyNewConsent = projected !== s.effective.provider;

                return { d, dirty, blocked, switching, providerChanged, newLabel, buttonLabel, surelyNewConsent };
            };

            const updateFooter = () => {
                if (!saved || saving) {
                    return;
                }

                const { d, dirty, blocked, switching, providerChanged, newLabel, buttonLabel, surelyNewConsent } = assess();
                const consent = saved.consent || {};

                if (!dirty) {
                    consequence.innerHTML = '<p class="text-xs text-ink-subtle">Belum ada perubahan.</p>';
                } else if (blocked) {
                    consequence.innerHTML = `<p class="text-sm text-danger">${esc(blocked)}</p>`;
                } else if (switching) {
                    consequence.innerHTML = `<div class="${app.ui.alert.warning} break-words">${!consent.granted
                        ? `Setelah disimpan, Notebook memakai ${esc(newLabel)}. Persetujuan AI tetap perlu diberikan setelah disimpan.`
                        : (surelyNewConsent
                            ? `Setelah disimpan, Notebook memakai ${esc(newLabel)}. Kamu perlu menyetujui lagi pengiriman isi Notebook sebelum bisa bertanya.`
                            : `Setelah disimpan, Notebook memakai ${esc(newLabel)}. Bila penerima isi Notebook berubah, kamu diminta menyetujui lagi sebelum bisa bertanya.`)}</div>`;
                } else if (d.key && !providerChanged && !openaiChanged(d)) {
                    consequence.innerHTML = `<p class="text-sm text-ink-muted">Kunci ${esc(LABELS[d.visible])} disimpan terenkripsi. Setelah ini hanya 4 karakter terakhirnya yang terlihat.</p>`;
                } else {
                    consequence.innerHTML = '<p class="text-sm text-ink-muted">Ada perubahan yang belum disimpan.</p>';
                }

                btnSave.textContent = !dirty
                    ? 'Simpan'
                    : (switching || providerChanged
                        ? (d.choice === '' && d.endpoint !== 'own' ? 'Pakai pengaturan server' : `Pakai ${buttonLabel}`)
                        : (d.key && !openaiChanged(d) ? 'Simpan kunci' : 'Simpan'));
                btnSave.disabled = !dirty || Boolean(blocked);
            };

            // ---- other saved keys ----
            const renderOtherKeys = () => {
                const s = settings();
                const inUse = s.effective.provider;
                const items = [];

                PROVIDERS.forEach((provider) => {
                    const state = providerState(provider);

                    if (state.has_key && provider !== inUse) {
                        items.push(`
                            <li class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 sm:px-5">
                                <span class="flex min-w-0 flex-wrap items-center gap-2 text-sm"><span class="text-ink">${esc(LABELS[provider])}</span><span class="break-all font-mono text-ink-muted">${esc(maskShort(state.key_preview))}</span>${usedFor(provider).ingest ? app.renderBadge('Dipakai untuk PDF pindaian & gambar', 'brand') : ''}</span>
                                <button type="button" class="${app.ui.button.ghostSm} text-danger! hover:bg-danger-soft!" data-key-remove="${provider}" aria-label="Hapus kunci ${esc(LABELS[provider])}">Hapus</button>
                            </li>`);
                    }
                });

                const openai = providerState('openai');
                if ((openai.base_url || openai.model) && inUse !== 'openai') {
                    let host = '';
                    try { host = openai.base_url ? new URL(openai.base_url).host : ''; } catch { host = openai.base_url; }
                    items.push(`
                        <li class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 sm:px-5">
                            <span class="flex min-w-0 flex-col gap-0.5 text-sm"><span class="text-ink">Layanan OpenAI-compatible milikmu</span><span class="break-all text-xs text-ink-muted">${esc([host, openai.model].filter(Boolean).join(' · '))}</span></span>
                            <button type="button" class="${app.ui.button.ghostSm} text-danger! hover:bg-danger-soft!" data-endpoint-remove aria-label="Hapus layanan OpenAI-compatible milikmu">Hapus</button>
                        </li>`);
                }

                const card = document.getElementById('ai-other');
                document.getElementById('ai-other-list').innerHTML = items.join('');
                card.hidden = items.length === 0;
            };

            const hasAnythingSaved = () => {
                const s = settings();

                return Boolean(s.provider || PROVIDERS.some((provider) => providerState(provider).has_key) || providerState('openai').base_url || providerState('openai').model);
            };

            // ---- render everything from the saved state ----
            const render = (data, { keepChoice = null } = {}) => {
                saved = data;
                replacing = {};
                PROVIDERS.forEach(clearKeyInput);
                clearFieldErrors();

                const s = settings();
                const openai = providerState('openai');
                form.elements.namedItem('openai_base_url').value = openai.base_url || '';
                form.elements.namedItem('openai_model').value = openai.model || '';
                const endpoint = openai.base_url || !openai.server_endpoint ? 'own' : 'server';
                form.querySelector(`input[name="openai_endpoint"][value="${endpoint}"]`).checked = true;

                const choice = keepChoice ?? (s.provider ?? '');
                (radioOf(choice) || radioOf('')).checked = true;

                renderStatus();
                renderOptions();
                showPanel();
                renderOtherKeys();

                const anything = hasAnythingSaved();
                document.getElementById('ai-reset-empty').textContent = 'Belum ada pengaturan milikmu yang tersimpan.';
                document.getElementById('ai-reset-empty').classList.toggle('hidden', anything);
                btnClear.hidden = !anything;

                options.inert = false;
                options.removeAttribute('aria-busy');
                syncUnloadGuard();
            };

            // ---- leaving with a typed key ----
            const beforeUnload = (event) => {
                event.preventDefault();
                event.returnValue = '';
            };
            let guarding = false;
            const syncUnloadGuard = () => {
                const typed = PROVIDERS.some((provider) => keyInput(provider).value.trim() !== '');

                if (typed && !guarding) {
                    window.addEventListener('beforeunload', beforeUnload);
                    guarding = true;
                } else if (!typed && guarding) {
                    window.removeEventListener('beforeunload', beforeUnload);
                    guarding = false;
                }
            };
            PROVIDERS.forEach((provider) => keyInput(provider).addEventListener('input', syncUnloadGuard));

            // ---- interactions ----
            form.querySelectorAll('input[name="provider"]').forEach((radio) => {
                radio.addEventListener('change', () => {
                    setFieldError('provider', '');
                    showPanel();
                    syncUnloadGuard();
                });
            });

            form.querySelectorAll('input[name="openai_endpoint"]').forEach((radio) => {
                radio.addEventListener('change', () => {
                    const state = providerState('openai');
                    const savedOwn = Boolean(state.base_url);

                    // Each endpoint keeps its own model: an own service's model means nothing on the server's.
                    if (radio.value === 'server') {
                        form.elements.namedItem('openai_base_url').value = '';
                        form.elements.namedItem('openai_model').value = savedOwn ? '' : (state.model || '');
                    } else {
                        form.elements.namedItem('openai_base_url').value = state.base_url || '';
                        form.elements.namedItem('openai_model').value = savedOwn ? (state.model || '') : '';
                    }
                    setFieldError('openai_base_url', '');
                    setFieldError('openai_model', '');
                    renderPanel('openai');
                    updateFooter();
                });
            });

            form.elements.namedItem('openai_base_url').addEventListener('input', () => renderPanel('openai'));

            form.addEventListener('click', (event) => {
                const replace = event.target.closest('[data-key-replace]');
                const cancel = event.target.closest('[data-key-replace-cancel]');

                if (replace) {
                    const provider = replace.closest('[data-provider-panel]').dataset.providerPanel;
                    replacing[provider] = true;
                    renderPanel(provider);
                    keyInput(provider).focus();
                } else if (cancel) {
                    const provider = cancel.closest('[data-provider-panel]').dataset.providerPanel;
                    replacing[provider] = false;
                    clearKeyInput(provider);
                    setFieldError(`${provider}_api_key`, '');
                    renderPanel(provider);
                    panelOf(provider).querySelector('[data-key-replace]').focus();
                    updateFooter();
                    syncUnloadGuard();
                }
            });

            const toastAfter = (response) => {
                app.showNotice(response?.message || 'Pengaturan AI disimpan.', response?.data?.consent && !response.data.consent.granted ? 'info' : 'success');
            };

            form.addEventListener('submit', async (event) => {
                event.preventDefault();

                const { d, dirty, blocked } = assess();

                if (!saved || saving || !dirty || blocked) {
                    return;
                }

                clearFieldErrors();

                const payload = { provider: d.choice || null };

                if (d.visible && d.key) {
                    payload[`${d.visible}_api_key`] = d.key;
                }

                if (d.openaiVisible) {
                    if (d.endpoint === 'own') {
                        payload.openai_base_url = d.baseUrl || null;
                        payload.openai_model = d.model || null;
                    } else {
                        payload.openai_base_url = null;
                        payload.openai_model = d.model || null;
                    }
                }

                let response = null;
                let failure = null;
                saving = true;

                try {
                    await app.withSubmitLoading(btnSave, async () => {
                        try {
                            response = await app.request('/api/ai-settings', { method: 'PUT', json: payload });
                        } catch (error) {
                            failure = error;
                        }
                    });
                } finally {
                    saving = false;
                }

                // The helper has put the old label back; the state decides it now.
                if (response) {
                    render(response.data);
                    toastAfter(response);
                    nowSection.focus();
                } else {
                    updateFooter();

                    if (failure && !showFieldErrors(failure.payload?.errors)) {
                        app.showNotice(failure.payload?.message || failure.message, 'error');
                    }
                }
            });

            // ---- confirm dialogs (the house sheet) ----
            /** A confirm (or, without `onConfirm`, an explanation) in the house sheet. */
            const confirmSheet = ({ title, message, confirmLabel = '', onConfirm = null }) => {
                const body = document.createElement('div');
                body.className = 'flex flex-col gap-4';
                body.innerHTML = `
                    <p class="text-sm text-ink-muted">${esc(message)}</p>
                    <div class="${app.ui.alert.danger} hidden" role="alert" data-confirm-error></div>
                    <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                        <button type="button" class="${app.ui.button.secondary}" data-confirm-cancel>${onConfirm ? 'Batal' : 'Mengerti'}</button>
                        ${onConfirm ? `<button type="button" class="${app.ui.button.destructive}" data-confirm-ok>${esc(confirmLabel)}</button>` : ''}
                    </div>`;

                app.openSheet({ title, body });
                body.querySelector('[data-confirm-cancel]').focus();
                body.querySelector('[data-confirm-cancel]').addEventListener('click', () => app.closeSheet());

                const ok = body.querySelector('[data-confirm-ok]');
                ok?.addEventListener('click', async () => {
                    const errorBox = body.querySelector('[data-confirm-error]');

                    if (saving) {
                        return;
                    }

                    errorBox.classList.add('hidden');
                    saving = true;

                    try {
                        await app.withSubmitLoading(ok, async () => {
                            try {
                                await onConfirm();
                            } catch (error) {
                                errorBox.textContent = error.message;
                                errorBox.classList.remove('hidden');
                            }
                        });
                    } finally {
                        saving = false;
                        updateFooter();
                    }
                });
            };

            const requestError = (error, fallback = '') => {
                const errors = error.payload?.errors || {};
                const first = Object.values(errors).flat()[0];

                return new Error([first || error.payload?.message || error.message, fallback].filter(Boolean).join(' '));
            };

            form.addEventListener('click', (event) => {
                const remove = event.target.closest('[data-key-remove]');
                const removeEndpoint = event.target.closest('[data-endpoint-remove]');

                if (remove) {
                    const provider = remove.dataset.keyRemove;
                    const label = LABELS[provider];
                    const state = providerState(provider);
                    const s = settings();
                    const use = usedFor(provider);
                    const mask = maskShort(state.key_preview);
                    const openai = providerState('openai');
                    // An own OpenAI-compatible service cannot run without its key: both go together.
                    const withEndpoint = provider === 'openai' && Boolean(openai.base_url);

                    // The API refuses to leave a provider the student chose without any key (mirrors UpdateNotebookAiSettingsRequest).
                    if (s.provider === provider && !state.server_configured) {
                        confirmSheet({
                            title: `Kunci ${label} masih dipakai`,
                            message: `Notebook-mu memakai ${label} dan server WeCIC tidak punya kuncinya, jadi kunci ini belum bisa dihapus. Pilih penyedia lain dulu, simpan, lalu hapus kuncinya.`,
                        });

                        return;
                    }

                    let host = '';
                    try { host = openai.base_url ? new URL(openai.base_url).host : ''; } catch { host = openai.base_url || ''; }

                    const consequences = [];

                    if (use.chat) {
                        consequences.push(state.server_configured
                            ? (withEndpoint ? 'Notebook akan memakai layanan OpenAI-compatible server WeCIC.' : `Notebook akan memakai kunci server WeCIC untuk ${label}.`)
                            : `Server WeCIC tidak punya kunci ${label}, jadi Notebook tidak bisa memakai AI sampai kamu memilih penyedia lain atau mengisi kunci baru.`);
                    }

                    if (use.ingest) {
                        consequences.push(state.server_configured
                            ? 'PDF pindaian dan gambar akan dibaca dengan kunci server WeCIC.'
                            : `PDF pindaian dan gambar tidak bisa dibaca AI sampai ada kunci ${label} lagi.`);
                    }

                    if (!use.chat && !use.ingest) {
                        consequences.push('Kunci ini sedang tidak dipakai.');
                    }

                    const what = withEndpoint
                        ? `Kunci ${mask}, alamat ${host || 'layananmu'}${openai.model ? `, dan model ${openai.model}` : ''} dihapus permanen.`
                        : `Kunci ${mask} dihapus permanen.`;
                    const payload = withEndpoint
                        ? { clear_openai_api_key: true, openai_base_url: null, openai_model: null }
                        : { [`clear_${provider}_api_key`]: true };
                    const unsavedChoice = selectedProvider();

                    confirmSheet({
                        title: withEndpoint ? 'Hapus layanan dan kunci milikmu?' : `Hapus kunci ${label}?`,
                        message: [what, ...consequences].join(' '),
                        confirmLabel: withEndpoint ? 'Hapus layanan dan kunci' : 'Hapus kunci',
                        onConfirm: async () => {
                            try {
                                const response = await app.request('/api/ai-settings', { method: 'PUT', json: payload });
                                app.closeSheet({ restoreFocus: false });
                                render(response.data, { keepChoice: unsavedChoice });
                                app.showNotice(withEndpoint ? 'Layanan dan kunci OpenAI-compatible milikmu dihapus.' : `Kunci ${label} dihapus.`, 'success');
                                radioOf(provider)?.focus();
                            } catch (error) {
                                throw requestError(error, 'Pilih penyedia lain dulu, simpan, lalu hapus kuncinya.');
                            }
                        },
                    });
                } else if (removeEndpoint) {
                    const openai = providerState('openai');
                    let host = '';
                    try { host = openai.base_url ? new URL(openai.base_url).host : ''; } catch { host = openai.base_url || ''; }

                    confirmSheet({
                        title: 'Hapus layanan milikmu?',
                        message: `${host ? `Alamat ${host}` : 'Alamat layanan'}${openai.model ? ` dan model ${openai.model}` : ''} dihapus. Kunci OpenAI-compatible milikmu tetap tersimpan.`,
                        confirmLabel: 'Hapus layanan',
                        onConfirm: async () => {
                            try {
                                const response = await app.request('/api/ai-settings', { method: 'PUT', json: { openai_base_url: null, openai_model: null } });
                                app.closeSheet({ restoreFocus: false });
                                render(response.data, { keepChoice: selectedProvider() });
                                app.showNotice('Layanan OpenAI-compatible milikmu dihapus.', 'success');
                                radioOf('openai')?.focus();
                            } catch (error) {
                                throw requestError(error);
                            }
                        },
                    });
                }
            });

            btnClear.addEventListener('click', () => {
                const s = settings();

                confirmSheet({
                    title: 'Hapus semua pengaturan AI-mu?',
                    message: s.server_ready && s.server_provider_label
                        ? `Pilihan penyedia, semua API key, dan layanan milikmu dihapus permanen. Notebook kembali memakai ${s.server_provider_label} dengan kunci server WeCIC.`
                        : 'Pilihan penyedia, semua API key, dan layanan milikmu dihapus permanen. Server WeCIC belum siap menjawab, jadi Notebook tidak bisa memakai AI sampai pengelola mengaturnya atau kamu mengisi kunci lagi.',
                    confirmLabel: 'Hapus semua',
                    onConfirm: async () => {
                        try {
                            const response = await app.request('/api/ai-settings', { method: 'DELETE' });
                            app.closeSheet({ restoreFocus: false });
                            render(response.data);
                            toastAfter(response);
                            nowSection.focus();
                        } catch (error) {
                            throw requestError(error);
                        }
                    },
                });
            });

            // ---- load ----
            const showLoadError = (error) => {
                document.getElementById('ai-now-loading').classList.add('hidden');
                document.getElementById('ai-now-content').classList.add('hidden');
                document.getElementById('ai-now-content').classList.remove('flex');
                document.getElementById('ai-now-error-message').textContent = error.payload?.message || error.message || 'Coba lagi sebentar.';
                document.getElementById('ai-now-error').classList.remove('hidden');
                document.getElementById('ai-now-error').classList.add('flex');
                // Nothing below may claim anything about settings that did not load.
                optionOf('').querySelector('[data-option-description]').textContent = 'Pengaturan server belum bisa dimuat.';
                document.getElementById('ai-reset-empty').textContent = 'Pengaturan belum bisa dimuat.';
                document.getElementById('ai-reset-empty').classList.remove('hidden');
                btnClear.hidden = true;
                consequence.innerHTML = '<p class="text-xs text-ink-subtle">Muat ulang pengaturan dulu.</p>';
                btnSave.disabled = true;
            };

            const load = async () => {
                document.getElementById('ai-now-error').classList.add('hidden');
                document.getElementById('ai-now-loading').classList.remove('hidden');

                try {
                    render((await app.request('/api/ai-settings')).data);
                } catch (error) {
                    showLoadError(error);
                }
            };

            document.getElementById('btn-ai-retry').addEventListener('click', load);

            if (await app.ensureAuthenticated()) {
                await load();
            }
        });
    </script>
@endpush
