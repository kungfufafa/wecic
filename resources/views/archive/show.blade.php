@extends('layouts.app')

@section('title', 'Arsip '.$label)
@section('page_id', 'archive-show')

@section('breadcrumb')
    <x-breadcrumb size="md" :items="[['label' => 'Arsip WeCIC', 'href' => route('archive.index')], 'Riwayat '.$label]" />
@endsection

@section('content')
    <x-page-hero :title="'Riwayat '.$label">
        <div class="mt-4 hidden" id="archive-group-picker">
            <label class="flex max-w-md flex-col gap-1.5">
                <span class="text-sm font-medium text-ink">Pilih data</span>
                <x-select id="archive-group-select"></x-select>
            </label>
        </div>
    </x-page-hero>

    <div class="grid min-w-0 items-start gap-6 xl:grid-cols-[22rem_minmax(0,1fr)] xl:gap-8">
        <x-section title="Versi" title-id="archive-versions-title" class="min-w-0">
            <x-slot:count id="archive-versions-count" class="hidden"></x-slot:count>
            <ul class="flex flex-col" id="archive-versions" aria-busy="true">
                @for ($i = 0; $i < 3; $i++)
                    <li class="group relative flex gap-3 pb-3 last:pb-0">
                        <span class="relative flex w-3 shrink-0 justify-center" aria-hidden="true">
                            <span class="mt-5 size-2.5 rounded-full bg-surface ring-4 ring-panel"></span>
                            <span class="absolute top-8 -bottom-8 w-px bg-border group-last:hidden"></span>
                        </span>
                        <x-card class="flex min-w-0 flex-1 flex-col gap-2 p-4">
                            <x-skeleton class="h-4 w-1/3" />
                            <x-skeleton class="h-3.5 w-2/3" />
                        </x-card>
                    </li>
                @endfor
            </ul>
        </x-section>

        <x-card as="section" class="min-w-0 overflow-hidden" aria-labelledby="archive-detail-title" id="archive-detail">
            <x-card.header>
                <x-card.title id="archive-detail-title" tabindex="-1" class="focus:outline-none">Detail versi</x-card.title>
            </x-card.header>
            <x-card.body id="archive-detail-body">
                <div class="flex flex-col items-center gap-2 px-4 py-8 text-center">
                    <span class="mb-1 flex text-ink-subtle" aria-hidden="true"><x-icon name="history" size="lg" /></span>
                    <p class="text-sm text-ink-subtle">Pilih satu versi untuk melihat isinya.</p>
                </div>
            </x-card.body>
        </x-card>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', async () => {
            const app = window.portalApp;
            const dataset = @json($dataset);
            const versionsHost = document.getElementById('archive-versions');
            const detailBody = document.getElementById('archive-detail-body');
            const detailTitle = document.getElementById('archive-detail-title');
            const groupPicker = document.getElementById('archive-group-picker');
            const groupSelect = document.getElementById('archive-group-select');
            const versionsCount = document.getElementById('archive-versions-count');

            const STATUS = {
                trusted: { label: 'Terpercaya', tone: 'success' },
                suspect: { label: 'Janggal', tone: 'warning' },
                rejected: { label: 'Ditolak', tone: 'danger' },
                dismissed: { label: 'Sempat janggal', tone: 'neutral' },
            };
            const KIND = { added: 'Ditambah', removed: 'Hilang', changed: 'Berubah' };
            const DIFF_LIMIT = 200;

            const params = new URLSearchParams(window.location.search);
            const state = {
                paramsHash: @json($paramsHash ?? null),
                selectedId: Number(params.get('version')) || null,
                versions: [],
            };

            const formatMoment = (value) => app.formatIndonesianDate(value, { time: true, fallback: '–' });

            const renderVersions = () => {
                versionsHost.removeAttribute('aria-busy');
                versionsCount.textContent = String(state.versions.length);
                versionsCount.classList.toggle('hidden', state.versions.length === 0);

                if (!state.versions.length) {
                    versionsHost.innerHTML = `<li>${app.renderEmptyState('Belum ada versi tersimpan.', 'secondary', '', { icon: 'history' })}</li>`;

                    return;
                }

                versionsHost.innerHTML = state.versions.map((version) => {
                    const status = STATUS[version.status] || STATUS.trusted;
                    const selected = version.id === state.selectedId;

                    return `
                        <li class="group relative flex gap-3 pb-3 last:pb-0">
                            <span class="relative flex w-3 shrink-0 justify-center" aria-hidden="true">
                                <span class="mt-5 size-2.5 rounded-full ring-4 ring-panel ${app.ui.fill[status.tone] || app.ui.fill.neutral}"></span>
                                <span class="absolute top-8 -bottom-8 w-px bg-border group-last:hidden"></span>
                            </span>
                            <button type="button" class="${app.ui.cardInteractive} w-full min-w-0 flex-1 p-4 text-start ${selected ? 'border-brand/50! bg-surface-tint/40!' : ''}" data-version-id="${version.id}" aria-pressed="${selected ? 'true' : 'false'}">
                                <span class="flex min-w-0 flex-col gap-1.5">
                                    <span class="flex flex-wrap items-center gap-2">
                                        ${app.renderBadge(status.label, status.tone)}
                                        ${version.is_current ? '<span class="text-xs font-medium text-ink">Dipakai</span>' : ''}
                                        <span class="ms-auto text-xs text-ink-subtle tabular-nums">${app.escapeHtml(`${version.seen_count}× terlihat`)}</span>
                                    </span>
                                    <span class="text-sm font-medium leading-snug text-ink tabular-nums">${app.escapeHtml(formatMoment(version.first_seen_at))}</span>
                                    <span class="text-xs text-ink-subtle">Terakhir terlihat ${app.escapeHtml(app.formatTimeAgo(version.last_seen_at))}</span>
                                    ${version.findings.length ? `<span class="line-clamp-2 text-xs text-warning">${app.escapeHtml(version.findings[0].message)}</span>` : ''}
                                </span>
                            </button>
                        </li>
                    `;
                }).join('');
            };

            const renderGroups = (groups) => {
                groupPicker.classList.toggle('hidden', groups.length <= 1);
                groupSelect.innerHTML = groups.map((group) => `
                    <option value="${app.escapeHtml(group.params_hash)}" ${group.params_hash === state.paramsHash ? 'selected' : ''}>
                        ${app.escapeHtml(`${group.subject || 'Tanpa nama'}${group.open_suspects ? ` (${group.open_suspects} janggal)` : ''}`)}
                    </option>
                `).join('');
            };

            const describeTruncatedDiff = (data, shown) => {
                const total = Number(data.diff_total);

                if (data.diff_truncated === true && total > shown) {
                    return `Menampilkan ${shown} dari ${total} perubahan. Cetak bukti atau unduh JSON untuk lengkapnya.`;
                }

                if (data.diff_truncated === undefined && shown >= DIFF_LIMIT) {
                    return `Menampilkan ${shown} perubahan pertama. Cetak bukti atau unduh JSON untuk lengkapnya.`;
                }

                return '';
            };

            const renderDiff = (data) => {
                const diff = Array.isArray(data.diff) ? data.diff : [];

                if (!diff.length) {
                    return `<div class="${app.ui.inset} text-sm text-ink-subtle">Tidak ada perbedaan isi dengan versi pembanding.</div>`;
                }

                const truncated = describeTruncatedDiff(data, diff.length);

                return `
                    ${truncated ? `<p class="${app.ui.alert.info}" data-diff-truncated>${app.escapeHtml(truncated)}</p>` : ''}
                    <div class="${app.ui.tableWrap}">
                        <table class="${app.ui.table}">
                            <caption class="sr-only">Perbedaan dengan versi pembanding</caption>
                            <thead>
                                <tr>
                                    <th scope="col" class="${app.ui.th}">Bagian</th>
                                    <th scope="col" class="${app.ui.th}">Sebelumnya</th>
                                    <th scope="col" class="${app.ui.th}">Versi ini</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${diff.map((row) => `
                                    <tr class="${app.ui.tr}">
                                        <td class="${app.ui.td} align-top"><span class="mb-0.5 block text-xs text-ink-subtle">${app.escapeHtml(KIND[row.kind] || row.kind)}</span>${app.escapeHtml(row.path)}</td>
                                        <td class="${app.ui.td} align-top ${row.kind === 'removed' || row.kind === 'changed' ? 'text-danger' : 'text-ink-subtle'}">${app.escapeHtml(row.old ?? '–')}</td>
                                        <td class="${app.ui.td} align-top ${row.kind === 'added' || row.kind === 'changed' ? 'text-success' : 'text-ink-subtle'}">${app.escapeHtml(row.new ?? '–')}</td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    </div>
                `;
            };

            const renderDetail = (data) => {
                const version = data.version;
                const status = STATUS[version.status] || STATUS.trusted;
                const pending = version.status === 'suspect' || version.status === 'rejected';

                detailTitle.textContent = `Versi ${formatMoment(version.first_seen_at)}`;
                detailBody.innerHTML = `
                    <div class="flex flex-col gap-4">
                        <div class="flex flex-wrap items-center gap-2">
                            ${app.renderBadge(status.label, status.tone)}
                            ${data.is_current ? app.renderBadge('Dipakai WeCIC', 'secondary') : ''}
                        </div>
                        ${app.renderKeyValueList([
                            { label: 'Pertama terlihat', value: formatMoment(version.first_seen_at) },
                            { label: 'Terakhir terlihat', value: formatMoment(version.last_seen_at) },
                            { label: 'Terlihat', value: `${version.seen_count} kali sinkronisasi` },
                            { label: 'Data', value: version.subject },
                        ])}
                        ${version.findings.length ? `
                            <div class="${app.ui.alert.warning} flex gap-3">
                                <svg xmlns="http://www.w3.org/2000/svg" class="${app.ui.alertIcon.warning}" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 9v4" /><path d="M10.363 3.591l-8.106 13.534a1.914 1.914 0 0 0 1.636 2.871h16.214a1.914 1.914 0 0 0 1.636 -2.87l-8.106 -13.536a1.914 1.914 0 0 0 -3.274 0z" /><path d="M12 16h.01" /></svg>
                                <div class="min-w-0 flex-1">
                                    <p class="font-medium">Kenapa ditahan</p>
                                    <ul class="mt-1 flex list-disc flex-col gap-1 ps-5">${version.findings.map((finding) => `<li>${app.escapeHtml(finding.message)}</li>`).join('')}</ul>
                                    ${version.status === 'suspect' ? '<p class="mt-2 text-ink-muted">Terima jika revisi resmi, tolak jika keliru.</p>' : ''}
                                </div>
                            </div>
                        ` : ''}
                        ${pending ? `
                            <div class="flex flex-wrap gap-2">
                                <button type="button" class="${app.ui.button.primary}" data-archive-action="accept">Pakai versi ini</button>
                                ${version.status === 'suspect' ? `<button type="button" class="${app.ui.button.secondary}" data-archive-action="reject">Tolak versi ini</button>` : ''}
                            </div>
                        ` : ''}
                        <div class="flex flex-wrap gap-2">
                            <a class="${app.ui.button.secondarySm}" href="/archive/versions/${version.id}/print" target="_blank" rel="noopener">
                                <svg xmlns="http://www.w3.org/2000/svg" class="size-4 shrink-0" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 17h2a2 2 0 0 0 2 -2v-4a2 2 0 0 0 -2 -2h-14a2 2 0 0 0 -2 2v4a2 2 0 0 0 2 2h2" /><path d="M17 9v-4a2 2 0 0 0 -2 -2h-6a2 2 0 0 0 -2 2v4" /><path d="M7 15a2 2 0 0 1 2 -2h6a2 2 0 0 1 2 2v4a2 2 0 0 1 -2 2h-6a2 2 0 0 1 -2 -2z" /></svg>
                                Cetak bukti
                            </a>
                            <a class="${app.ui.button.ghostSm}" href="/api/archive/versions/${version.id}/export">
                                <svg xmlns="http://www.w3.org/2000/svg" class="size-4 shrink-0" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2 -2v-2" /><path d="M7 11l5 5l5 -5" /><path d="M12 4v12" /></svg>
                                Unduh JSON
                            </a>
                        </div>
                        <section class="flex flex-col gap-3 border-t border-border-light pt-4">
                            <h3 class="${app.ui.cardTitle}">${data.baseline ? `Dibanding versi ${app.escapeHtml(formatMoment(data.baseline.first_seen_at))}` : 'Versi pertama'}</h3>
                            ${data.baseline ? renderDiff(data) : `<div class="${app.ui.inset} text-sm text-ink-subtle">Versi pertama — belum ada pembanding.</div>`}
                        </section>
                    </div>
                `;
            };

            const loadDetail = async (versionId, { focus = false, background = false } = {}) => {
                state.selectedId = versionId;
                renderVersions();
                detailBody.innerHTML = `<div class="flex flex-col gap-2" aria-busy="true"><div class="${app.ui.skeleton} h-4 w-1/2" data-slot="skeleton"></div><div class="${app.ui.skeleton} h-4 w-2/3" data-slot="skeleton"></div></div>`;

                try {
                    const url = new URL(window.location.href);
                    url.searchParams.set('version', String(versionId));
                    window.history.replaceState(null, '', url);
                } catch (error) {
                    // Only the shareable URL is lost.
                }

                try {
                    const payload = await app.request(`/api/archive/versions/${versionId}`, { background });

                    if (payload?.data) {
                        renderDetail(payload.data);

                        if (focus) {
                            detailTitle.focus();
                        }
                    }
                } catch (error) {
                    app.mountErrorState(detailBody, 'Versi gagal dimuat.', () => loadDetail(versionId), { subtitle: app.describeError(error) });
                }
            };

            const loadVersions = async ({ background = false } = {}) => {
                const query = state.paramsHash ? `?params_hash=${encodeURIComponent(state.paramsHash)}` : '';

                try {
                    const payload = await app.request(`/api/archive/${encodeURIComponent(dataset)}${query}`, { background });
                    const data = payload?.data || {};
                    const groups = Array.isArray(data.groups) ? data.groups : [];

                    state.versions = Array.isArray(data.versions) ? data.versions : [];
                    state.paramsHash ??= data.selected_params_hash || groups[0]?.params_hash || null;
                    renderGroups(groups);

                    if (!state.versions.some((version) => version.id === state.selectedId)) {
                        state.selectedId = (state.versions.find((version) => version.status === 'suspect') || state.versions[0])?.id || null;
                    }

                    renderVersions();
                    app.setSyncStatus('Riwayat dibaca dari arsip WeCIC.', 'info');

                    if (state.selectedId) {
                        await loadDetail(state.selectedId, { background });
                    }
                } catch (error) {
                    versionsHost.removeAttribute('aria-busy');
                    app.mountErrorState(versionsHost, 'Riwayat gagal dimuat.', () => loadVersions(), { subtitle: app.describeError(error) });
                }
            };

            versionsHost.addEventListener('click', (event) => {
                const button = event.target instanceof Element ? event.target.closest('[data-version-id]') : null;

                if (button) {
                    loadDetail(Number(button.dataset.versionId), { focus: window.matchMedia('(max-width: 1279px)').matches });
                }
            });

            groupSelect.addEventListener('change', () => {
                state.paramsHash = groupSelect.value || null;
                state.selectedId = null;

                try {
                    const url = new URL(window.location.href);
                    url.searchParams.set('params_hash', state.paramsHash || '');
                    url.searchParams.delete('version');
                    window.history.replaceState(null, '', url);
                } catch (error) {
                    // Only the shareable URL is lost.
                }

                loadVersions();
            });

            detailBody.addEventListener('click', (event) => {
                const button = event.target instanceof Element ? event.target.closest('[data-archive-action]') : null;

                if (!button || !state.selectedId) {
                    return;
                }

                const action = button.dataset.archiveAction;

                app.withSubmitLoading(button, async () => {
                    try {
                        const payload = await app.request(`/api/archive/versions/${state.selectedId}/${action}`, { method: 'POST' });

                        app.showNotice(payload?.message || 'Tersimpan.', 'success');
                        app.clearCachedResponses();
                        await loadVersions();
                    } catch (error) {
                        app.showNotice(`Gagal menyimpan pilihan. ${app.describeError(error)}`, 'error');
                    }
                });
            });

            const ready = await app.ensureAuthenticated();

            if (!ready) {
                return;
            }

            app.registerSyncHandler('archive-show', loadVersions);

            await loadVersions();
        });
    </script>
@endpush
