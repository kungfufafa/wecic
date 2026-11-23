@extends('layouts.app')

@section('title', 'SKKM')
@section('page_id', 'skkm-index')

{{-- The reviewer and demo accounts never get a link to the real portal: their portal is the fixtures. --}}
@php
    $skkmAddUrl = \App\Services\PortalEndpointRegistry::url('student_skkm_add_form');
    $showSkkmAdd = ! \App\Services\PortalReviewerAccount::isSandboxSession(session('campus_session_token'));
@endphp

@section('content')
    {{-- The hero's isolate traps the floating Tambah bar below lg; z-29 keeps it above the content yet under the top bar (z-30). --}}
    <x-page-hero class="max-lg:z-29" title="SKKM">
        <x-slot:meta>
            <span class="min-h-4" data-skkm-updated></span>
        </x-slot:meta>
        @if ($showSkkmAdd)
        <x-slot:actions class="max-sm:-mt-4 lg:max-w-64 lg:flex-col lg:items-end">
            <x-sticky-action class="lg:text-end">
                <x-button
                    :href="$skkmAddUrl"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="w-full lg:w-auto"
                    data-skkm-add
                    aria-describedby="skkm-add-hint"
                >
                    <x-icon name="plus" size="sm" />
                    Tambah SKKM di portal
                    <x-icon name="external-link" size="sm" class="opacity-80" />
                    <span class="sr-only">(membuka portal kampus di tab baru)</span>
                </x-button>
                <p class="mt-1.5 text-center text-xs text-ink-subtle lg:text-end" id="skkm-add-hint">Mungkin perlu masuk lagi di portal.</p>
            </x-sticky-action>
        </x-slot:actions>
        @endif
    </x-page-hero>

    <div class="flex flex-col gap-6" data-portal-url="{{ config('portal.base_url') }}" id="skkm-page">
        <div id="skkm-notice" class="empty:hidden"></div>

        <div class="grid gap-6 lg:grid-cols-2 lg:has-[>.hidden]:grid-cols-1">
            <section id="skkm-hero" class="min-w-0" aria-label="Ringkasan poin SKKM" aria-busy="true">
                <x-card class="h-full">
                    <x-card.body class="flex flex-col gap-3">
                        <h2 class="sr-only" id="skkm-hero-title">Poin SKKM valid</h2>
                        <span class="sr-only">Memuat poin SKKM…</span>
                        <x-skeleton class="h-3.5 w-24" />
                        <x-skeleton class="h-9 w-40" />
                        <x-skeleton class="h-5 w-48" />
                        <x-skeleton class="h-2.5 w-full rounded-full!" />
                        <x-skeleton class="h-3.5 w-2/3" />
                    </x-card.body>
                </x-card>
            </section>

            <x-card as="section" class="hidden min-w-0" aria-labelledby="skkm-breakdown-title" id="skkm-breakdown">
                <x-card.header>
                    <x-card.title id="skkm-breakdown-title">Sebaran poin per kategori</x-card.title>
                </x-card.header>
                <x-card.body class="flex flex-col gap-4" id="skkm-breakdown-body"></x-card.body>
            </x-card>
        </div>

        <div id="skkm-message" class="empty:hidden"></div>

        <x-split>
            <x-card as="section" aria-labelledby="skkm-list-title">
                <x-card.header class="flex-col items-stretch! sm:flex-row sm:items-center!">
                    <x-card.title id="skkm-list-title" tabindex="-1">Daftar kegiatan</x-card.title>
                    <div class="min-w-0">
                        <x-view-tabs as="div" class="[scrollbar-width:none] [&::-webkit-scrollbar]:hidden" role="group" aria-label="Saring status kegiatan" id="skkm-tabs" />
                    </div>
                </x-card.header>
                <p class="sr-only" aria-live="polite" id="skkm-filter-status"></p>
                <div id="skkm-entries" aria-busy="true">
                    <div class="divide-y divide-border-light">
                        @for ($i = 0; $i < 3; $i++)
                            <div class="flex items-start gap-3 px-4 py-3 sm:px-5">
                                <div class="flex flex-1 flex-col gap-2">
                                    <x-skeleton class="h-4 w-2/3" />
                                    <x-skeleton class="h-3.5 w-1/2" />
                                </div>
                                <x-skeleton class="h-6 w-16" />
                            </div>
                        @endfor
                    </div>
                </div>
            </x-card>

            <x-slot:aside aria-label="Pedoman dan validasi SKKM">
                <x-card as="section" aria-labelledby="skkm-guide-title">
                    <x-card.header>
                        <x-card.title id="skkm-guide-title">Pedoman SKKM</x-card.title>
                    </x-card.header>
                    <x-card.body class="flex flex-col gap-3" id="skkm-guide">
                        <x-skeleton class="h-12 w-full" />
                    </x-card.body>
                </x-card>

                <x-card as="section" aria-labelledby="skkm-dpa-title">
                    <x-card.header>
                        <x-card.title id="skkm-dpa-title">Validasi oleh DPA</x-card.title>
                    </x-card.header>
                    <x-card.body class="flex flex-col gap-3" id="skkm-dpa">
                        <x-skeleton class="h-4 w-full" />
                        <x-skeleton class="h-4 w-2/3" />
                    </x-card.body>
                </x-card>
            </x-slot:aside>
        </x-split>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', async () => {
            const app = window.portalApp;
            const page = document.getElementById('skkm-page');
            const heroHost = document.getElementById('skkm-hero');
            const tabsHost = document.getElementById('skkm-tabs');
            const entriesHost = document.getElementById('skkm-entries');
            const noticeHost = document.getElementById('skkm-notice');
            const messageHost = document.getElementById('skkm-message');
            const guideHost = document.getElementById('skkm-guide');
            const dpaHost = document.getElementById('skkm-dpa');
            const breakdownSection = document.getElementById('skkm-breakdown');
            const breakdownHost = document.getElementById('skkm-breakdown-body');
            const filterStatus = document.getElementById('skkm-filter-status');
            const updatedLabel = document.querySelector('[data-skkm-updated]');
            const portalUrl = app.safeHref(page.dataset.portalUrl) || '#';
            const DISMISSED_KEY = 'mycic-dismissed-notices';
            const STATUS = {
                valid: { label: 'Valid', tone: 'success' },
                pending: { label: 'Menunggu validasi', tone: 'warning' },
                invalid: { label: 'Ditolak', tone: 'danger' },
                unknown: { label: 'Status tidak terbaca', tone: 'secondary' },
            };
            const FILTERS = [
                { key: 'all', label: 'Semua' },
                { key: 'pending', label: 'Menunggu' },
                { key: 'valid', label: 'Valid' },
                { key: 'invalid', label: 'Ditolak' },
            ];
            const ORDER = { invalid: 0, pending: 1, unknown: 2, valid: 3 };
            const requestedFilter = new URLSearchParams(window.location.search).get('status');
            let filter = FILTERS.some((option) => option.key === requestedFilter) ? requestedFilter : 'all';
            let latest = null;
            let lastPayload = null;
            let rendered = false;

            const storage = {
                read(key) {
                    try {
                        const raw = window.localStorage.getItem(key);

                        return raw ? JSON.parse(raw) : null;
                    } catch (error) {
                        return null;
                    }
                },
                write(key, value) {
                    try {
                        window.localStorage.setItem(key, JSON.stringify(value));
                    } catch (error) {
                        // A dismissed note may come back; nothing else depends on it.
                    }
                },
            };

            const numberFormatter = new Intl.NumberFormat('id-ID', { maximumFractionDigits: 2 });
            const isNumber = (value) => value !== null && value !== undefined && value !== '' && Number.isFinite(Number(value));
            const formatPoints = (value) => (isNumber(value) ? numberFormatter.format(Number(value)) : '–');

            const describeFreshness = (payload) => {
                const meta = payload?.meta || {};
                const ago = app.formatTimeAgo(meta.synced_at || meta.cached_at || payload?.data?.extracted_at);

                if (!ago) {
                    return '';
                }

                if (meta.data_origin === 'device_cache') {
                    return `Diperbarui ${ago} · data tersimpan di perangkat${meta.cache_is_stale ? ', mungkin sudah lama' : ''}`;
                }

                if (meta.status === 'syncing') {
                    return `Diperbarui ${ago} · sedang diperbarui dari portal`;
                }

                if (meta.status === 'failed') {
                    return `Diperbarui ${ago} · portal belum bisa dihubungi, ini data terakhir`;
                }

                return `Diperbarui ${ago}`;
            };

            const entriesOf = (data) => (Array.isArray(data?.entries) ? data.entries : []).filter((entry) => entry && typeof entry === 'object');

            const countEntries = (data) => {
                const counts = { total: 0, valid: 0, pending: 0, invalid: 0, unknown: 0 };

                entriesOf(data).forEach((entry) => {
                    const status = STATUS[entry.status] ? entry.status : 'unknown';

                    counts.total += 1;
                    counts[status] += 1;
                });

                return counts;
            };

            const syncQuery = () => {
                try {
                    const url = new URL(window.location.href);

                    if (filter === 'all') {
                        url.searchParams.delete('status');
                    } else {
                        url.searchParams.set('status', filter);
                    }

                    window.history.replaceState(window.history.state, '', url);
                } catch (error) {
                    // The address bar is only a convenience for sharing the filter.
                }
            };

            const renderNotice = (notice) => {
                noticeHost.innerHTML = '';

                if (!notice?.text) {
                    return;
                }

                const key = `skkm:${String(notice.text).slice(0, 160)}`;
                const dismissed = storage.read(DISMISSED_KEY);

                if (Array.isArray(dismissed) && dismissed.includes(key)) {
                    return;
                }

                noticeHost.innerHTML = `
                    <div class="${app.alertTone('info')} flex items-start gap-3" role="note">
                        <div class="min-w-0 flex-1">
                            <p class="font-medium">Pesan dari SIREKA</p>
                            <p class="mt-1 text-sm text-ink-muted">${app.escapeHtml(notice.text)}</p>
                        </div>
                        <button type="button" class="${app.ui.modalClose} -my-1 -me-2 min-h-11 min-w-11 sm:min-h-9 sm:min-w-9" aria-label="Tutup pesan SIREKA" data-dismiss-notice>
                            <svg xmlns="http://www.w3.org/2000/svg" class="size-4" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6l-12 12" /><path d="M6 6l12 12" /></svg>
                        </button>
                    </div>
                `;

                noticeHost.querySelector('[data-dismiss-notice]')?.addEventListener('click', () => {
                    const stored = storage.read(DISMISSED_KEY);
                    const next = Array.isArray(stored) ? stored : [];

                    next.push(key);
                    storage.write(DISMISSED_KEY, next.slice(-30));
                    noticeHost.innerHTML = '';
                    document.getElementById('skkm-list-title')?.focus({ preventScroll: true });
                });
            };

            const renderMessage = (message) => {
                messageHost.innerHTML = message
                    ? `<div class="${app.alertTone('info')}" role="status">${app.escapeHtml(message)}</div>`
                    : '';
            };

            const renderHero = (data) => {
                heroHost.removeAttribute('aria-busy');

                if (data.found === false) {
                    heroHost.innerHTML = `
                        <div class="${app.ui.card} h-full" data-slot="card">
                            <div class="${app.ui.cardBody}">
                                <h2 class="sr-only" id="skkm-hero-title">Poin SKKM</h2>
                                <div class="${app.alertTone('warning')}" role="status">
                                    <p class="font-medium">SIREKA tidak bisa diakses</p>
                                    <p class="mt-1 text-sm text-ink-muted">Halaman SKKM portal tidak terbaca. Ini bukan berarti poinmu nol.</p>
                                    <div class="mt-3 flex flex-wrap gap-2">
                                        <button type="button" class="${app.buttonClass('secondary', { small: true })}" data-skkm-retry data-online-required>Coba lagi</button>
                                        <a href="${app.escapeHtml(portalUrl)}" target="_blank" rel="noopener noreferrer" class="${app.buttonClass('ghost', { small: true })}">Buka portal kampus<span class="sr-only"> (tab baru)</span></a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;

                    return;
                }

                const summary = data.summary || {};
                const stats = data.stats || {};
                const counts = countEntries(data);
                const valid = summary.valid_points ?? stats.total_points?.valid ?? null;
                const pending = summary.pending_points ?? stats.total_points?.pending ?? null;
                const invalid = summary.invalid_points ?? stats.total_points?.invalid ?? null;
                const otherPoints = [
                    isNumber(pending) && Number(pending) > 0 ? `${formatPoints(pending)} menunggu` : '',
                    isNumber(invalid) && Number(invalid) > 0 ? `${formatPoints(invalid)} ditolak` : '',
                ].filter(Boolean);
                const required = isNumber(stats.required_points) && Number(stats.required_points) > 0 ? Number(stats.required_points) : null;
                const remaining = isNumber(summary.remaining_points)
                    ? Number(summary.remaining_points)
                    : (required !== null && isNumber(valid) ? Math.max(0, required - Number(valid)) : null);
                const graduation = data.graduation_status || {};
                const graduationBadge = graduation.is_met === true
                    ? app.renderBadge('Memenuhi syarat kelulusan', 'success')
                    : (graduation.is_met === false
                        ? app.renderBadge('Belum memenuhi syarat kelulusan', 'warning')
                        : app.renderBadge('Status kelulusan tidak diketahui', 'secondary'));
                const summaryNotes = [
                    required !== null && remaining !== null && remaining > 0 ? `Kurang ${formatPoints(remaining)} poin` : '',
                    required === null ? 'Target poin belum tercantum di portal' : '',
                    counts.invalid > 0 ? `${counts.invalid} ditolak (cek bukti di portal)` : '',
                ].filter(Boolean);

                const progress = required !== null
                    ? app.renderProgressBar({
                        max: required,
                        label: 'Progres poin SKKM',
                        valueLabel: `${formatPoints(valid)} dari ${formatPoints(required)} poin`,
                        size: 'lg',
                        segments: [
                            { value: isNumber(valid) ? Number(valid) : null, tone: 'success', label: 'Valid', valueLabel: formatPoints(valid) },
                            { value: isNumber(pending) ? Number(pending) : null, tone: 'warning', striped: true, label: 'Menunggu validasi', valueLabel: formatPoints(pending) },
                        ],
                    })
                    : '';

                heroHost.innerHTML = `
                    <div class="${app.ui.card} h-full" data-slot="card">
                        <div class="${app.ui.cardBody} flex flex-col gap-5">
                            <div class="min-w-0">
                                <h2 class="text-xs font-medium text-ink-muted" id="skkm-hero-title">Poin SKKM valid</h2>
                                <p class="mt-1 flex flex-wrap items-baseline gap-x-2">
                                    <span class="text-4xl font-semibold tracking-tight text-ink tabular-nums">${formatPoints(valid)}</span>
                                    <span class="text-sm font-medium text-ink-subtle tabular-nums">${required !== null ? `dari ${formatPoints(required)} poin` : 'poin'}${otherPoints.map((text) => ` · ${text}`).join('')}</span>
                                </p>
                                <div class="mt-3">${graduationBadge}</div>
                            </div>
                            ${progress}
                            ${graduation.is_met == null && graduation.text ? `<p class="text-sm text-ink-muted">Status portal: <span class="font-medium text-ink">“${app.escapeHtml(graduation.text)}”</span></p>` : ''}
                            ${summaryNotes.length ? `<p class="text-sm text-ink-muted">${summaryNotes.join(' · ')}</p>` : ''}
                        </div>
                    </div>
                `;
            };

            const renderTabs = (data) => {
                const counts = countEntries(data);

                tabsHost.innerHTML = FILTERS.map((option) => {
                    const active = filter === option.key;
                    const count = option.key === 'all' ? counts.total : counts[option.key];

                    return `
                        <button type="button" class="${app.ui.tab} disabled:pointer-events-none disabled:opacity-50" aria-pressed="${active ? 'true' : 'false'}" data-skkm-filter="${option.key}" ${!data || data.found === false ? 'disabled' : ''}>
                            ${option.label}
                            <span class="text-xs tabular-nums ${active ? 'text-ink-muted' : 'text-ink-subtle'}">${count}</span>
                        </button>
                    `;
                }).join('');
            };

            const statusHint = (entry) => ({
                invalid: 'Perbaiki di portal.',
                unknown: entry.status_label || '',
            })[entry.status] || '';

            const renderEntry = (entry) => {
                const status = STATUS[entry.status] || STATUS.unknown;
                const attachmentName = entry.attachment_path ? String(entry.attachment_path).split('/').pop() : null;
                const attachmentUrl = entry.attachment_path ? app.buildCampusDownloadUrl(entry.attachment_path, attachmentName) : null;
                const category = [entry.activity, entry.level].filter(Boolean).join(' · ');
                const hint = statusHint(entry);

                return `
                    <li class="flex flex-col gap-2.5 border-b border-border-light px-4 py-3 last:border-b-0 sm:px-5">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0 flex-1">
                                <h3 class="text-sm font-medium leading-snug text-ink wrap-break-word">${app.escapeHtml(entry.name || 'Kegiatan')}</h3>
                                ${category ? `<p class="mt-0.5 text-xs text-ink-muted wrap-break-word">${app.escapeHtml(category)}</p>` : ''}
                                ${entry.type ? `<p class="mt-0.5 line-clamp-2 text-xs text-ink-subtle">${app.escapeHtml(entry.type)}</p>` : ''}
                            </div>
                            <div class="flex shrink-0 flex-col items-end gap-1.5 text-end">
                                <span class="text-sm font-semibold text-ink tabular-nums">${app.escapeHtml(entry.points_label || formatPoints(entry.points))} <span class="text-xs font-medium text-ink-subtle">poin</span></span>
                                ${app.renderBadge(status.label, status.tone)}
                            </div>
                        </div>
                        ${attachmentUrl || entry.has_attachment || hint ? `
                            <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                                ${attachmentUrl
                                    ? `<a href="${app.escapeHtml(attachmentUrl)}" target="_blank" rel="noopener" class="${app.buttonClass('secondary', { small: true })}" data-online-required>Lihat bukti<span class="sr-only"> ${app.escapeHtml(entry.name || '')} (tab baru)</span></a>`
                                    : (entry.has_attachment ? '<span class="text-xs text-ink-subtle">Bukti hanya bisa dibuka di portal.</span>' : '')}
                                ${hint ? `<span class="min-w-0 text-xs text-ink-subtle">${app.escapeHtml(hint)}</span>` : ''}
                            </div>
                        ` : ''}
                    </li>
                `;
            };

            const renderEntries = (data) => {
                entriesHost.removeAttribute('aria-busy');

                if (!data || data.found === false) {
                    filterStatus.textContent = '';
                    entriesHost.innerHTML = `<div class="${app.ui.cardBody}">${app.renderEmptyState('Daftar kegiatan belum bisa dibaca.', 'secondary', 'Buka SIREKA di portal kampus.', { icon: 'skkm' })}</div>`;

                    return;
                }

                const entries = entriesOf(data)
                    .map((entry, index) => ({ entry, index }))
                    .sort((first, second) => (ORDER[first.entry.status] ?? 2) - (ORDER[second.entry.status] ?? 2) || first.index - second.index)
                    .map(({ entry }) => entry);

                if (!entries.length) {
                    filterStatus.textContent = '';
                    entriesHost.innerHTML = `<div class="${app.ui.cardBody}">${app.renderEmptyState('Belum ada kegiatan SKKM.', 'secondary', 'Unggah kegiatan lewat portal.', { icon: 'skkm' })}</div>`;

                    return;
                }

                const shown = filter === 'all' ? entries : entries.filter((entry) => (STATUS[entry.status] ? entry.status : 'unknown') === filter);

                filterStatus.textContent = filter === 'all'
                    ? `Menampilkan ${entries.length} kegiatan.`
                    : `Menampilkan ${shown.length} dari ${entries.length} kegiatan.`;
                entriesHost.innerHTML = shown.length
                    ? `<ul class="flex flex-col">${shown.map(renderEntry).join('')}</ul>`
                    : `<div class="${app.ui.cardBody}">${app.renderEmptyState('Tidak ada kegiatan dengan status ini.', 'secondary', '', { icon: 'filter' })}</div>`;
                app.refreshOnlineRequiredState();
            };

            const renderGuide = (data) => {
                const path = data?.guide_path;
                const href = path ? app.buildCampusDownloadUrl(path, String(path).split('/').pop()) : null;

                guideHost.innerHTML = href
                    ? `
                        <a href="${app.escapeHtml(href)}" target="_blank" rel="noopener" class="${app.ui.sheetAction}" data-online-required data-skkm-guide>
                            <span class="${app.ui.sheetActionIcon}">
                                <svg xmlns="http://www.w3.org/2000/svg" class="size-4" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 3v4a1 1 0 0 0 1 1h4" /><path d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2z" /><path d="M9 17h6" /><path d="M9 13h6" /></svg>
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block font-medium text-ink">Pedoman SKKM${data.guide_year ? ` ${app.escapeHtml(data.guide_year)}` : ''}</span>
                                <span class="block text-xs text-ink-subtle">PDF</span>
                            </span>
                        </a>
                    `
                    : app.renderEmptyState('Pedoman belum terbaca dari portal.', 'secondary', '', { icon: 'file' });
                app.refreshOnlineRequiredState();
            };

            const cachedDpa = () => {
                try {
                    const advising = app.getCachedResponse('/api/academic/advising')?.payload?.data?.dpa;

                    if (advising?.name) {
                        return advising;
                    }

                    const krs = app.getCachedResponse('/api/academic/krs')?.payload?.data;

                    return [krs?.insights?.dpa, krs?.dpa].find((dpa) => dpa?.name) || null;
                } catch (error) {
                    return null;
                }
            };

            const renderDpa = (data) => {
                const dpa = cachedDpa();
                const pending = countEntries(data).pending;
                const message = pending > 0
                    ? `Halo Bapak/Ibu, saya sudah mengunggah ${pending} kegiatan SKKM di SIREKA dan mohon bantuan validasinya. Terima kasih.`
                    : 'Halo Bapak/Ibu, saya ingin bertanya tentang SKKM saya di SIREKA. Terima kasih.';

                dpaHost.innerHTML = `
                    ${pending > 0 ? `<p class="text-sm text-ink-muted"><strong class="font-medium text-ink">${pending} kegiatan</strong> menunggu validasi.</p>` : ''}
                    ${dpa
                        ? `
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <span class="min-w-0">
                                    <span class="block text-sm font-medium leading-snug text-ink">${app.escapeHtml(dpa.name)}</span>
                                    <span class="block text-xs text-ink-subtle">DPA kamu</span>
                                </span>
                                ${app.renderContactButton({ name: dpa.name, role: 'Dosen Pembimbing Akademik', phone: dpa.phone, whatsapp: dpa.whatsapp, email: dpa.email, message }, { label: 'Hubungi DPA' })}
                            </div>
                        `
                        : `<a href="/krs?tab=riwayat" class="${app.ui.link} inline-flex min-h-11 items-center text-sm sm:min-h-9">Lihat kontak DPA di halaman KRS</a>`}
                `;
            };

            const renderBreakdown = (data) => {
                const rows = Array.isArray(data?.summary?.points_by_activity) ? data.summary.points_by_activity.filter((row) => row?.activity) : [];

                breakdownSection.classList.toggle('hidden', !rows.length || data?.found === false);

                if (!rows.length) {
                    breakdownHost.innerHTML = '';

                    return;
                }

                const total = (row) => (Number(row.valid_points) || 0) + (Number(row.pending_points) || 0) + (Number(row.invalid_points) || 0);
                const max = Math.max(1, ...rows.map(total));
                const legend = [['success', 'Valid'], ['warning', 'Menunggu'], ['danger', 'Ditolak']];
                const pointsCaption = (row) => [
                    [row.valid_points, 'valid'],
                    [row.pending_points, 'menunggu'],
                    [row.invalid_points, 'ditolak'],
                ].filter(([value]) => Number(value) > 0).map(([value, label]) => `${formatPoints(value)} ${label}`).join(' · ') || '0 poin';

                breakdownHost.innerHTML = `
                    <ul class="flex flex-wrap gap-x-4 gap-y-1.5 text-xs text-ink-muted" aria-hidden="true">
                        ${legend.map(([tone, label]) => `<li class="flex items-center gap-1.5"><span class="size-2.5 rounded-sm ${app.fillToneClass(tone)}"></span>${label}</li>`).join('')}
                    </ul>
                    <ul class="flex flex-col gap-4">
                        ${rows.map((row) => {
                            const parts = [
                                ['success', Number(row.valid_points) || 0],
                                ['warning', Number(row.pending_points) || 0],
                                ['danger', Number(row.invalid_points) || 0],
                            ].filter(([, value]) => value > 0);
                            const description = `${row.activity}: ${formatPoints(row.valid_points)} poin valid, ${formatPoints(row.pending_points)} menunggu, ${formatPoints(row.invalid_points)} ditolak`;

                            return `
                                <li class="min-w-0">
                                    <div class="mb-1.5 flex items-baseline justify-between gap-3">
                                        <span class="min-w-0 text-sm font-medium leading-snug text-ink wrap-break-word">${app.escapeHtml(row.activity)}</span>
                                        <span class="shrink-0 text-xs text-ink-subtle tabular-nums">${app.escapeHtml(pointsCaption(row))}</span>
                                    </div>
                                    <div class="${app.ui.progressTrack} h-2" role="img" aria-label="${app.escapeHtml(description)}">
                                        ${parts.map(([tone, value]) => `<div class="${app.ui.progressFill} ${app.fillToneClass(tone)}" style="width: ${((value / max) * 100).toFixed(2)}%"></div>`).join('')}
                                    </div>
                                </li>
                            `;
                        }).join('')}
                    </ul>
                `;
            };

            const renderSkkm = (payload) => {
                const data = payload?.data;

                if (!data || typeof data !== 'object') {
                    return;
                }

                latest = data;
                lastPayload = payload;
                rendered = true;
                renderNotice(data.found === false ? null : data.notice);
                renderMessage(data.message);
                renderHero(data);
                renderTabs(data);
                renderEntries(data);
                renderGuide(data);
                renderDpa(data);
                renderBreakdown(data);
                updatedLabel.textContent = describeFreshness(payload);
            };

            const setFilter = (next, source, focusKey) => {
                filter = FILTERS.some((option) => option.key === next) ? next : 'all';
                syncQuery();
                renderTabs(latest);
                renderEntries(latest);
                source.querySelector(`[data-skkm-filter="${focusKey}"]`)?.focus();
            };

            tabsHost.addEventListener('click', (event) => {
                const button = event.target instanceof Element ? event.target.closest('[data-skkm-filter]') : null;

                if (!button || button.disabled || !latest) {
                    return;
                }

                setFilter(button.dataset.skkmFilter, tabsHost, button.dataset.skkmFilter);
            });

            document.addEventListener('visibilitychange', () => {
                if (!document.hidden && lastPayload) {
                    updatedLabel.textContent = describeFreshness(lastPayload);
                }
            });

            try {
                app.primeCachedView('/api/skkm', renderSkkm, 'poin SKKM');
            } catch (error) {
                // A blocked device cache only means the page waits for the server.
            }

            const ready = await app.ensureAuthenticated();

            if (!ready) {
                return;
            }

            const loadSkkm = async ({ force = false, background = false } = {}) => {
                try {
                    const payload = await app.request('/api/skkm', { fresh: force, background });

                    if (!payload) {
                        return;
                    }

                    renderSkkm(payload);
                    app.markViewSynced('SKKM', payload, { force });
                } catch (error) {
                    if (rendered) {
                        app.setSyncStatus(`Data SKKM belum bisa diperbarui. ${app.describeError(error)}`, 'warning');

                        return;
                    }

                    heroHost.removeAttribute('aria-busy');
                    app.mountErrorState(heroHost, 'Data SKKM gagal dimuat.', () => loadSkkm({ force }), {
                        subtitle: `${app.describeError(error)} Poinmu tidak hilang; data akan tampil lagi setelah portal bisa dibaca.`,
                    });
                    renderTabs(null);
                    entriesHost.removeAttribute('aria-busy');
                    entriesHost.innerHTML = `<div class="${app.ui.cardBody}">${app.renderEmptyState('Daftar kegiatan akan tampil setelah data termuat.', 'secondary')}</div>`;
                    guideHost.innerHTML = app.renderEmptyState('Pedoman belum terbaca dari portal.', 'secondary');
                    renderDpa(null);
                    app.setSyncStatus('Data SKKM gagal dimuat.', 'error');
                }
            };

            heroHost.addEventListener('click', (event) => {
                if (event.target instanceof Element && event.target.closest('[data-skkm-retry]')) {
                    void loadSkkm({ force: true });
                }
            });

            app.registerSyncHandler('skkm-index', loadSkkm);

            await loadSkkm();
        });
    </script>
@endpush
