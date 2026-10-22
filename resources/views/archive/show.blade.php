@extends('layouts.app')

@section('title', 'Arsip '.$label)
@section('page_id', 'archive-show')

@section('content')
    <header class="mb-4">
        <a href="{{ route('archive.index') }}" class="mycic-link text-sm">Arsip MyCIC</a>
        <h1 class="mycic-page-heading">Riwayat {{ $label }}</h1>
        <p class="mycic-page-subheading">Setiap versi berbeda yang pernah ditampilkan portal kampus, dari yang terbaru. Versi bertanda "Dipakai" adalah yang sedang ditampilkan MyCIC.</p>
    </header>

    <div class="flex min-w-0 flex-col gap-4">
        <div class="hidden" id="archive-group-picker">
            <label class="mycic-field max-w-md">
                <span class="mycic-label">Pilih data</span>
                <select class="mycic-input" id="archive-group-select"></select>
            </label>
        </div>

        <div class="grid min-w-0 gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]">
            <section class="flex min-w-0 flex-col gap-2" aria-labelledby="archive-versions-title">
                <h2 class="mycic-section-title" id="archive-versions-title">Versi</h2>
                <ul class="flex flex-col gap-2" id="archive-versions" aria-busy="true">
                    @for ($i = 0; $i < 3; $i++)
                        <li class="mycic-card p-4">
                            <div class="mycic-skeleton h-4 w-1/3"></div>
                            <div class="mycic-skeleton mt-2 h-3.5 w-2/3"></div>
                        </li>
                    @endfor
                </ul>
            </section>

            <section class="mycic-card min-w-0" aria-labelledby="archive-detail-title" id="archive-detail">
                <div class="mycic-card-header">
                    <h2 class="mycic-card-title" id="archive-detail-title" tabindex="-1">Detail versi</h2>
                </div>
                <div class="mycic-card-body" id="archive-detail-body">
                    <p class="text-sm text-ink-subtle">Pilih satu versi untuk melihat isinya.</p>
                </div>
            </section>
        </div>
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

            const STATUS = {
                trusted: { label: 'Terpercaya', tone: 'success' },
                suspect: { label: 'Janggal', tone: 'warning' },
                rejected: { label: 'Ditolak', tone: 'danger' },
                dismissed: { label: 'Sempat janggal', tone: 'neutral' },
            };
            const KIND = { added: 'Ditambah', removed: 'Hilang', changed: 'Berubah' };

            const params = new URLSearchParams(window.location.search);
            const state = {
                paramsHash: params.get('params_hash'),
                selectedId: Number(params.get('version')) || null,
                versions: [],
            };

            const formatMoment = (value) => app.formatIndonesianDate(value, { time: true, fallback: '–' });

            const renderVersions = () => {
                versionsHost.removeAttribute('aria-busy');

                if (!state.versions.length) {
                    versionsHost.innerHTML = `<li>${app.renderEmptyState('Belum ada versi tersimpan.', 'secondary', 'Arsip terisi otomatis saat halaman data ini dibuka dan disinkronkan dari portal.')}</li>`;

                    return;
                }

                versionsHost.innerHTML = state.versions.map((version) => {
                    const status = STATUS[version.status] || STATUS.trusted;
                    const selected = version.id === state.selectedId;

                    return `
                        <li>
                            <button type="button" class="mycic-card flex w-full min-w-0 flex-col gap-1.5 p-4 text-start transition-colors hover:border-brand/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand ${selected ? 'border-brand/60 bg-surface-tint/40' : ''}" data-version-id="${version.id}" aria-pressed="${selected ? 'true' : 'false'}">
                                <span class="flex flex-wrap items-center gap-2">
                                    ${app.renderBadge(status.label, status.tone)}
                                    ${version.is_current ? app.renderBadge('Dipakai', 'brand') : ''}
                                    <span class="text-caption text-ink-subtle">${app.escapeHtml(`${version.seen_count}× terlihat`)}</span>
                                </span>
                                <span class="text-sm font-semibold text-ink">${app.escapeHtml(formatMoment(version.first_seen_at))}</span>
                                <span class="text-caption text-ink-subtle">Terakhir terlihat ${app.escapeHtml(app.formatTimeAgo(version.last_seen_at))}</span>
                                ${version.findings.length ? `<span class="line-clamp-2 text-caption text-warning">${app.escapeHtml(version.findings[0].message)}</span>` : ''}
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

            const renderDiff = (diff) => {
                if (!diff.length) {
                    return '<p class="text-sm text-ink-subtle">Tidak ada perbedaan isi dengan versi pembanding.</p>';
                }

                return `
                    <div class="mycic-table-wrap">
                        <table class="mycic-table">
                            <caption class="sr-only">Perbedaan dengan versi pembanding</caption>
                            <thead>
                                <tr>
                                    <th scope="col">Bagian</th>
                                    <th scope="col">Sebelumnya</th>
                                    <th scope="col">Versi ini</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${diff.map((row) => `
                                    <tr>
                                        <td class="align-top"><span class="block text-caption text-ink-subtle">${app.escapeHtml(KIND[row.kind] || row.kind)}</span>${app.escapeHtml(row.path)}</td>
                                        <td class="align-top ${row.kind === 'removed' || row.kind === 'changed' ? 'text-danger' : 'text-ink-subtle'}">${app.escapeHtml(row.old ?? '–')}</td>
                                        <td class="align-top ${row.kind === 'added' || row.kind === 'changed' ? 'text-success' : 'text-ink-subtle'}">${app.escapeHtml(row.new ?? '–')}</td>
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
                            ${data.is_current ? app.renderBadge('Dipakai MyCIC', 'brand') : ''}
                        </div>
                        ${app.renderKeyValueList([
                            { label: 'Pertama terlihat', value: formatMoment(version.first_seen_at) },
                            { label: 'Terakhir terlihat', value: formatMoment(version.last_seen_at) },
                            { label: 'Terlihat', value: `${version.seen_count} kali sinkronisasi` },
                            { label: 'Data', value: version.subject },
                        ])}
                        ${version.findings.length ? `
                            <div class="mycic-alert-warning mb-0">
                                <p class="font-bold">Kenapa ditahan</p>
                                <ul class="mt-1 flex list-disc flex-col gap-1 ps-5">${version.findings.map((finding) => `<li>${app.escapeHtml(finding.message)}</li>`).join('')}</ul>
                                ${version.status === 'suspect' ? '<p class="mt-2">Kalau perubahan ini memang benar (misalnya nilai direvisi resmi), terima versinya. Kalau salah, tolak dan simpan bukti untuk dilaporkan ke kampus.</p>' : ''}
                            </div>
                        ` : ''}
                        ${pending ? `
                            <div class="flex flex-wrap gap-2">
                                <button type="button" class="mycic-btn-primary" data-archive-action="accept">Data portal benar, pakai versi ini</button>
                                ${version.status === 'suspect' ? '<button type="button" class="mycic-btn-secondary" data-archive-action="reject">Data portal salah, tetap pakai yang lama</button>' : ''}
                            </div>
                        ` : ''}
                        <div class="flex flex-wrap gap-2">
                            <a class="mycic-btn-ghost-sm" href="/archive/versions/${version.id}/print" target="_blank" rel="noopener">Cetak bukti</a>
                            <a class="mycic-btn-ghost-sm" href="/api/archive/versions/${version.id}/export">Unduh JSON</a>
                        </div>
                        <section class="flex flex-col gap-2">
                            <h3 class="mycic-section-title">${data.baseline ? `Dibanding versi ${app.escapeHtml(formatMoment(data.baseline.first_seen_at))}` : 'Versi pertama'}</h3>
                            ${data.baseline ? renderDiff(data.diff) : '<p class="text-sm text-ink-subtle">Ini versi pertama yang tersimpan, jadi belum ada pembanding.</p>'}
                        </section>
                    </div>
                `;
            };

            const loadDetail = async (versionId, { focus = false } = {}) => {
                state.selectedId = versionId;
                renderVersions();
                detailBody.innerHTML = '<div class="flex flex-col gap-2" aria-busy="true"><div class="mycic-skeleton h-4 w-1/2"></div><div class="mycic-skeleton h-4 w-2/3"></div></div>';

                try {
                    const url = new URL(window.location.href);
                    url.searchParams.set('version', String(versionId));
                    window.history.replaceState(null, '', url);
                } catch (error) {
                    // Only the shareable URL is lost.
                }

                try {
                    const payload = await app.request(`/api/archive/versions/${versionId}`);

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

            const loadVersions = async () => {
                const query = state.paramsHash ? `?params_hash=${encodeURIComponent(state.paramsHash)}` : '';

                try {
                    const payload = await app.request(`/api/archive/${encodeURIComponent(dataset)}${query}`);
                    const data = payload?.data || {};
                    const groups = Array.isArray(data.groups) ? data.groups : [];

                    state.versions = Array.isArray(data.versions) ? data.versions : [];
                    state.paramsHash ??= groups[0]?.params_hash || null;
                    renderGroups(groups);

                    if (!state.versions.some((version) => version.id === state.selectedId)) {
                        state.selectedId = (state.versions.find((version) => version.status === 'suspect') || state.versions[0])?.id || null;
                    }

                    renderVersions();
                    app.setSyncStatus('Riwayat dibaca dari arsip MyCIC.', 'info');

                    if (state.selectedId) {
                        await loadDetail(state.selectedId);
                    }
                } catch (error) {
                    versionsHost.removeAttribute('aria-busy');
                    app.mountErrorState(versionsHost, 'Riwayat gagal dimuat.', () => loadVersions(), { subtitle: app.describeError(error) });
                }
            };

            versionsHost.addEventListener('click', (event) => {
                const button = event.target instanceof Element ? event.target.closest('[data-version-id]') : null;

                if (button) {
                    loadDetail(Number(button.dataset.versionId), { focus: window.matchMedia('(max-width: 1023px)').matches });
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
