@extends('layouts.app')

@section('title', 'Skripsi')
@section('page_id', 'thesis-index')

@php
    $portalThesisUrl = \App\Services\PortalEndpointRegistry::url('student_skripsi');
@endphp

@section('content')
    <header class="mb-4">
        <h1 class="mycic-page-heading">Skripsi</h1>
        <p class="mycic-page-subheading">Status e-Skripsi, kesiapan kamu, serta syarat dan SOP Tugas Akhir dari SiMASITA di Portal Kampus.</p>
        <p class="mt-1 min-h-4 text-caption text-ink-subtle" data-thesis-updated></p>
    </header>

    <div class="grid items-start gap-5 lg:grid-cols-12" id="thesis-page" data-portal-thesis-url="{{ $portalThesisUrl }}">
        <div class="flex min-w-0 flex-col gap-5 lg:col-span-7">
            <section id="thesis-status" aria-labelledby="thesis-status-title" aria-busy="true">
                <div class="mycic-card">
                    <div class="mycic-card-body flex flex-col gap-3">
                        <h2 class="sr-only" id="thesis-status-title">Status e-Skripsi</h2>
                        <span class="sr-only">Memuat status skripsi…</span>
                        <div class="mycic-skeleton h-5 w-40"></div>
                        <div class="mycic-skeleton h-4 w-3/4"></div>
                    </div>
                </div>
            </section>

            <section class="mycic-card scroll-mt-24" id="kesiapan" aria-labelledby="thesis-readiness-title">
                <div class="mycic-card-header flex flex-wrap items-start justify-between gap-2">
                    <div class="min-w-0">
                        <h2 class="mycic-card-title" id="thesis-readiness-title" tabindex="-1">Kesiapan skripsi</h2>
                        <p class="mt-0.5 text-caption text-ink-subtle" id="thesis-readiness-caption">Dihitung dari nilai dan tagihan yang sudah tersinkron.</p>
                    </div>
                    <span id="thesis-readiness-badge"></span>
                </div>
                <div class="mycic-card-body flex flex-col gap-5" id="thesis-readiness" aria-busy="true">
                    <span class="sr-only">Memuat kesiapan skripsi…</span>
                    <div class="flex items-center gap-4">
                        <div class="mycic-skeleton h-24 w-24 rounded-full"></div>
                        <div class="flex flex-1 flex-col gap-2">
                            <div class="mycic-skeleton h-5 w-32"></div>
                            <div class="mycic-skeleton h-4 w-48"></div>
                        </div>
                    </div>
                    @for ($i = 0; $i < 4; $i++)
                        <div class="mycic-skeleton h-11 w-full"></div>
                    @endfor
                </div>
            </section>

            <section class="mycic-card" aria-labelledby="thesis-prep-title">
                <details class="group" id="thesis-prep">
                    <summary class="mycic-card-header flex min-h-11 cursor-pointer list-none items-center justify-between gap-3 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand">
                        <span class="min-w-0">
                            <span class="mycic-card-title block" id="thesis-prep-title">Persiapan ujian TA</span>
                            <span class="mt-0.5 block text-caption text-ink-subtle" id="thesis-prep-caption">Centang sendiri; tersimpan di perangkat ini saja.</span>
                        </span>
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 shrink-0 text-ink-subtle transition-transform group-open:rotate-180" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6l6 -6" /></svg>
                    </summary>
                    <div class="mycic-card-body flex flex-col gap-1" id="thesis-prep-list">
                        <div class="mycic-skeleton h-11 w-full"></div>
                    </div>
                </details>
            </section>
        </div>

        <aside class="flex min-w-0 flex-col gap-5 lg:sticky lg:top-24 lg:col-span-5" aria-label="Persyaratan dan SOP skripsi">
            <section class="mycic-card scroll-mt-24" id="persyaratan" aria-labelledby="thesis-requirements-title">
                <div class="mycic-card-header">
                    <h2 class="mycic-card-title" id="thesis-requirements-title">Persyaratan &amp; SOP</h2>
                    <p class="mt-0.5 text-caption text-ink-subtle">Dari SiMASITA di Portal Kampus.</p>
                </div>
                <div class="mycic-card-body flex flex-col gap-5" id="thesis-requirements" aria-busy="true">
                    <div class="mycic-skeleton h-4 w-2/3"></div>
                    <div class="mycic-skeleton h-4 w-full"></div>
                    <div class="mycic-skeleton h-4 w-5/6"></div>
                </div>
            </section>

            <section class="mycic-card" aria-labelledby="thesis-documents-title">
                <div class="mycic-card-header">
                    <h2 class="mycic-card-title" id="thesis-documents-title">Dokumen</h2>
                </div>
                <div class="mycic-card-body" id="thesis-documents">
                    <div class="mycic-skeleton h-11 w-full"></div>
                </div>
            </section>
        </aside>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', async () => {
            const app = window.portalApp;
            const page = document.getElementById('thesis-page');
            const statusHost = document.getElementById('thesis-status');
            const readinessHost = document.getElementById('thesis-readiness');
            const readinessBadge = document.getElementById('thesis-readiness-badge');
            const readinessCaption = document.getElementById('thesis-readiness-caption');
            const readinessTitle = document.getElementById('thesis-readiness-title');
            const prepList = document.getElementById('thesis-prep-list');
            const prepCaption = document.getElementById('thesis-prep-caption');
            const requirementsHost = document.getElementById('thesis-requirements');
            const documentsHost = document.getElementById('thesis-documents');
            const updatedLabel = document.querySelector('[data-thesis-updated]');
            const portalThesisUrl = app.safeHref(page.dataset.portalThesisUrl) || '#';
            const CHECKLIST_PREFIX = 'mycic-skripsi-checklist:';
            const STATUS_STYLE = {
                passed: { tone: 'success', label: 'Terpenuhi', icon: '<path d="M5 12l5 5l10 -10" />' },
                failed: { tone: 'danger', label: 'Belum terpenuhi', icon: '<path d="M18 6l-12 12" /><path d="M6 6l12 12" />' },
                unknown: { tone: 'secondary', label: 'Perlu dicek', icon: '<path d="M8 8a3.5 3 0 0 1 3.5 -3h1a3.5 3 0 0 1 3.5 3a3 3 0 0 1 -2 3a3 4 0 0 0 -2 4" /><path d="M12 19l0 .01" />' },
            };
            const ICON_BG = { success: 'bg-success-soft text-success', danger: 'bg-danger-soft text-danger', secondary: 'bg-surface text-ink-subtle' };
            const numberFormatter = new Intl.NumberFormat('id-ID', { maximumFractionDigits: 2 });
            const ipkFormatter = new Intl.NumberFormat('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            let thesisData = null;
            let thesisPayload = null;
            let readinessData = null;
            let thesisRendered = false;
            let readinessRendered = false;
            let ownerKey = 'anon';

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
                        // The manual checklist is a per-device convenience; losing it is harmless.
                    }
                },
            };

            // FNV-1a: separates students on a shared device without storing the NIM itself.
            const hashOwner = (value) => {
                let hash = 0x811c9dc5;

                for (const character of String(value)) {
                    hash ^= character.codePointAt(0);
                    hash = Math.imul(hash, 0x01000193) >>> 0;
                }

                return hash.toString(16).padStart(8, '0');
            };

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

            const formatValue = (key, value) => {
                if (value === null || value === undefined || value === '') {
                    return '–';
                }

                if (key === 'ipk') {
                    return ipkFormatter.format(Number(value));
                }

                return typeof value === 'number' ? numberFormatter.format(value) : String(value);
            };

            const statusIcon = (status) => {
                const style = STATUS_STYLE[status] || STATUS_STYLE.unknown;

                return `<span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full ${ICON_BG[style.tone]}" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">${style.icon}</svg></span>`;
            };

            const renderStatus = (data) => {
                statusHost.removeAttribute('aria-busy');
                const status = data?.status || {};
                const hint = data?.krs_hint || {};
                const awaiting = hint.awaiting_status === true;
                const courseName = hint.course?.name || 'Skripsi';
                let body = '';

                if (status.state === 'not_enrolled') {
                    body = `
                        <div class="flex flex-wrap items-center gap-2">
                            ${app.renderBadge('Belum mengambil skripsi', 'secondary')}
                        </div>
                        <p class="text-sm text-ink-muted">${app.escapeHtml(status.message || 'Portal mencatat kamu belum mengambil mata kuliah skripsi semester ini.')}</p>
                        ${awaiting ? `<div class="${app.alertTone('warning')} mb-0" role="status">KRS-mu memuat <strong>${app.escapeHtml(courseName)}</strong>, tetapi status e-Skripsi belum aktif. Biasanya menunggu validasi KRS oleh DPA atau sinkron portal.</div>` : ''}
                        <a href="#kesiapan" class="${app.buttonClass('secondary', { small: true })} self-start" data-thesis-jump>Cek kesiapan kamu</a>
                    `;
                } else if (status.state === 'active_unparsed') {
                    const fields = (Array.isArray(status.raw_sections) ? status.raw_sections : [])
                        .flatMap((section) => (Array.isArray(section?.fields) ? section.fields : []))
                        .slice(0, 12);

                    body = `
                        <div class="flex flex-wrap items-center gap-2">${app.renderBadge('Skripsi aktif', 'brand')}</div>
                        ${status.message ? `<p class="text-sm text-ink-muted">${app.escapeHtml(status.message)}</p>` : ''}
                        ${fields.length ? app.renderKeyValueList(fields.map((field) => ({ label: field.label, value: field.value }))) : '<p class="text-sm text-ink-muted">Portal menampilkan data skripsi yang belum bisa dibaca MyCIC. Detailnya ada di Portal Kampus.</p>'}
                        <a href="${app.escapeHtml(portalThesisUrl)}" target="_blank" rel="noopener noreferrer" class="${app.buttonClass('secondary', { small: true })} self-start" data-online-required>Buka di Portal Kampus<span class="sr-only"> (tab baru)</span></a>
                    `;
                } else {
                    body = `
                        <div class="flex flex-wrap items-center gap-2">${app.renderBadge('Status belum terbaca', 'secondary')}</div>
                        <p class="text-sm text-ink-muted">${status.available === false ? 'Halaman e-Skripsi portal belum bisa dibuka. Syarat dan kesiapan di bawah tetap bisa dipakai.' : 'Halaman e-Skripsi portal kosong.'}</p>
                    `;
                }

                statusHost.innerHTML = `
                    <div class="mycic-card">
                        <div class="mycic-card-header"><h2 class="mycic-card-title" id="thesis-status-title">Status e-Skripsi</h2></div>
                        <div class="mycic-card-body flex flex-col gap-3">${body}</div>
                    </div>
                `;
            };

            const renderRequirements = (data) => {
                requirementsHost.removeAttribute('aria-busy');
                const requirements = data?.requirements || {};
                const thesisItems = Array.isArray(requirements.thesis) ? requirements.thesis : [];
                const examItems = Array.isArray(requirements.exam) ? requirements.exam : [];
                const thresholds = data?.thresholds || {};
                const jenjang = data?.student?.jenjang || null;
                const minSks = jenjang ? thresholds.min_sks?.[jenjang] : null;
                const isDefault = data?.threshold_source && data.threshold_source !== 'portal';

                if (!thesisItems.length && !examItems.length) {
                    requirementsHost.innerHTML = app.renderEmptyState('Syarat belum terbaca dari portal.', 'secondary', 'Buka SOP di bawah atau SiMASITA di Portal Kampus.');

                    return;
                }

                const summary = [
                    minSks ? `minimal ${numberFormatter.format(minSks)} SKS` : null,
                    thresholds.min_ipk !== undefined ? `IPK ≥ ${ipkFormatter.format(Number(thresholds.min_ipk))}` : null,
                    thresholds.max_d !== undefined ? `maks. ${thresholds.max_d} nilai D` : null,
                    thresholds.allow_e === false ? 'tanpa nilai E' : null,
                ].filter(Boolean);

                requirementsHost.innerHTML = `
                    ${summary.length ? `
                        <div class="${app.statusPanelClass('brand')}">
                            <p class="text-caption font-semibold uppercase tracking-wide text-ink-subtle">Ambang untuk jenjang ${app.escapeHtml(jenjang || 'kamu')}</p>
                            <p class="mt-1 text-sm font-semibold text-ink">${app.escapeHtml(summary.join(' · '))}</p>
                            ${isDefault ? '<p class="mt-1 text-caption text-ink-subtle">Sebagian ambang tidak tertulis di portal; MyCIC memakai nilai bawaan. Pastikan lagi di SOP.</p>' : ''}
                        </div>
                    ` : ''}
                    ${thesisItems.length ? `
                        <div>
                            <h3 class="text-sm font-bold text-ink">Syarat pengajuan skripsi</h3>
                            <ul class="mt-2 flex flex-col gap-2">
                                ${thesisItems.map((item) => `<li class="flex gap-2 text-sm text-ink-muted"><span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-brand" aria-hidden="true"></span><span class="min-w-0">${app.escapeHtml(item)}</span></li>`).join('')}
                            </ul>
                        </div>
                    ` : ''}
                    ${examItems.length ? `
                        <div>
                            <h3 class="text-sm font-bold text-ink">Menuju ujian TA</h3>
                            <ol class="mt-3 flex flex-col">
                                ${examItems.map((item, index) => `
                                    <li class="relative flex gap-3 pb-4 last:pb-0">
                                        ${index < examItems.length - 1 ? '<span class="absolute start-3.5 top-7 bottom-0 w-px bg-border" aria-hidden="true"></span>' : ''}
                                        <span class="relative z-10 flex h-7 w-7 shrink-0 items-center justify-center rounded-full border-2 border-brand/40 bg-panel text-xs font-bold text-brand" aria-hidden="true">${index + 1}</span>
                                        <span class="min-w-0 pt-1 text-sm text-ink-muted"><span class="sr-only">Langkah ${index + 1}: </span>${app.escapeHtml(item)}</span>
                                    </li>
                                `).join('')}
                            </ol>
                        </div>
                    ` : ''}
                `;
            };

            const renderDocuments = (data) => {
                const documents = Array.isArray(data?.documents) ? data.documents : [];

                if (!documents.length) {
                    documentsHost.innerHTML = app.renderEmptyState('Portal belum menyediakan dokumen SOP.', 'secondary');

                    return;
                }

                documentsHost.innerHTML = `
                    <div class="flex flex-col">
                        ${documents.map((documentItem) => {
                            const href = app.buildCampusDownloadUrl(documentItem.path, documentItem.filename);

                            return href ? `
                                <a href="${app.escapeHtml(href)}" target="_blank" rel="noopener" class="mycic-list-row min-h-11 items-center rounded-md transition-colors hover:bg-surface/60 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand" data-online-required>
                                    <span class="flex min-w-0 items-center gap-3">
                                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-surface-tint text-brand-pressed" aria-hidden="true">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3v4a1 1 0 0 0 1 1h4" /><path d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2z" /><path d="M9 17h6" /><path d="M9 13h6" /></svg>
                                        </span>
                                        <span class="min-w-0">
                                            <span class="block text-sm font-semibold text-ink wrap-break-word">${app.escapeHtml(documentItem.label || documentItem.filename || 'Dokumen')}</span>
                                            <span class="block text-caption text-ink-subtle">PDF · butuh koneksi internet</span>
                                        </span>
                                    </span>
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0 self-center text-ink-subtle" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 6h-6a2 2 0 0 0 -2 2v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-6" /><path d="M11 13l9 -9" /><path d="M15 4h5v5" /></svg>
                                </a>
                            ` : '';
                        }).join('')}
                    </div>
                `;
                app.refreshOnlineRequiredState();
            };

            const renderThesis = (payload) => {
                const data = payload?.data;

                if (!data || typeof data !== 'object') {
                    return;
                }

                thesisData = data;
                thesisPayload = payload;
                thesisRendered = true;
                ownerKey = data.student?.nim ? hashOwner(data.student.nim) : ownerKey;
                renderStatus(data);
                renderRequirements(data);
                renderDocuments(data);
                renderPrep();
                updatedLabel.textContent = describeFreshness(payload);
            };

            const sksRing = (progress) => {
                const percent = Number.isFinite(Number(progress?.percent)) ? Math.max(0, Math.min(100, Number(progress.percent))) : 0;
                const known = progress?.current !== null && progress?.current !== undefined;
                const circumference = 2 * Math.PI * 42;
                const tone = percent >= 100 ? 'text-success' : 'text-brand';
                const label = known
                    ? `SKS lulus ${progress.current} dari minimal ${progress.target}`
                    : `SKS lulus belum tersinkron, minimal ${progress?.target ?? '–'}`;

                return `
                    <div class="relative h-24 w-24 shrink-0" role="img" aria-label="${app.escapeHtml(label)}">
                        <svg viewBox="0 0 100 100" class="h-24 w-24 -rotate-90" aria-hidden="true">
                            <circle cx="50" cy="50" r="42" fill="none" stroke="currentColor" stroke-width="10" class="text-surface"></circle>
                            ${known ? `<circle cx="50" cy="50" r="42" fill="none" stroke="currentColor" stroke-width="10" stroke-linecap="round" class="${tone}" stroke-dasharray="${circumference.toFixed(2)}" stroke-dashoffset="${(circumference * (1 - percent / 100)).toFixed(2)}"></circle>` : ''}
                        </svg>
                        <span class="absolute inset-0 flex flex-col items-center justify-center" aria-hidden="true">
                            <span class="text-lg font-extrabold leading-none text-ink tabular-nums">${known ? app.escapeHtml(String(progress.current)) : '–'}</span>
                            <span class="mt-0.5 text-[0.7rem] font-semibold text-ink-subtle tabular-nums">/ ${app.escapeHtml(String(progress?.target ?? '–'))} SKS</span>
                        </span>
                    </div>
                `;
            };

            const rowValue = (item) => {
                if (item.key === 'nilai_d') {
                    return item.current === null ? `maks. ${item.target}` : `${item.current} / maks. ${item.target}`;
                }

                if (item.key === 'nilai_e') {
                    return item.current === null ? 'harus 0' : `${item.current} (harus 0)`;
                }

                if (item.key === 'ipk') {
                    return `${formatValue('ipk', item.current)} / min. ${formatValue('ipk', item.target)}`;
                }

                if (item.key === 'keuangan') {
                    return item.status === 'failed' ? (item.current || 'Ada tagihan') : 'Perlu dicek';
                }

                return `${formatValue(item.key, item.current)} / ${formatValue(item.key, item.target)}`;
            };

            const renderReadiness = (payload) => {
                const data = payload?.data;

                if (!data || typeof data !== 'object' || !Array.isArray(data.checklist_items)) {
                    return;
                }

                readinessData = data;
                readinessRendered = true;
                readinessHost.removeAttribute('aria-busy');
                const items = data.checklist_items.filter((item) => item?.kind === 'auto');
                const sksItem = items.find((item) => item.key === 'sks');
                const rows = items.filter((item) => item.key !== 'sks');
                const summary = data.summary || {};
                const sources = payload?.meta?.sources || {};
                const gradesPending = !data.grades_available;
                const ready = summary.academic_ready;

                readinessBadge.innerHTML = ready === true
                    ? app.renderBadge('Syarat akademik terpenuhi', 'success')
                    : (ready === false ? app.renderBadge(`${summary.failed || 0} syarat belum terpenuhi`, 'danger') : app.renderBadge('Belum lengkap', 'secondary'));
                readinessCaption.textContent = data.threshold_source === 'portal'
                    ? `Ambang jenjang ${data.jenjang} dari SiMASITA.`
                    : `Ambang jenjang ${data.jenjang}${data.jenjang_source === 'default' ? ' (jenjang belum terbaca)' : ''}; sebagian memakai nilai bawaan MyCIC.`;

                const sksHint = sksItem?.status === 'passed'
                    ? 'SKS minimal sudah tercapai.'
                    : (sksItem?.hint || '');
                const warnings = Array.isArray(data.insights?.warnings) ? data.insights.warnings : [];

                readinessHost.innerHTML = `
                    <div class="flex items-center gap-4">
                        ${sksRing(data.sks_progress)}
                        <div class="min-w-0">
                            <p class="text-sm font-bold text-ink">SKS lulus</p>
                            <p class="mt-0.5 text-sm text-ink-muted">${app.escapeHtml(sksHint)}</p>
                        </div>
                    </div>
                    ${gradesPending ? `
                        <div class="${app.alertTone('info')} mb-0 flex flex-wrap items-center justify-between gap-3" role="status">
                            <span>${sources.grades === 'failed'
                                ? 'Nilai belum bisa dibaca dari portal.'
                                : (sources.grades === 'synced' ? 'Portal belum mencatat nilai (KHS) untukmu.' : 'Nilai sedang disinkronkan dari portal.')}</span>
                            <button type="button" class="${app.buttonClass('secondary', { small: true })}" data-thesis-sync-grades>Sinkronkan nilai</button>
                        </div>
                    ` : ''}
                    <ul class="flex flex-col divide-y divide-border-light">
                        ${rows.map((item) => {
                            const style = STATUS_STYLE[item.status] || STATUS_STYLE.unknown;
                            const courseList = item.key === 'nilai_d' ? data.courses?.d : (item.key === 'nilai_e' ? data.courses?.e : null);
                            const interactive = Array.isArray(courseList) && courseList.length > 0;
                            const inner = `
                                ${statusIcon(item.status)}
                                <span class="min-w-0 flex-1">
                                    <span class="flex flex-wrap items-baseline justify-between gap-x-3">
                                        <span class="text-sm font-semibold text-ink">${app.escapeHtml(item.label)}</span>
                                        <span class="text-sm font-semibold tabular-nums ${app.textToneClass(style.tone === 'secondary' ? 'muted' : style.tone)}">${app.escapeHtml(rowValue(item))}</span>
                                    </span>
                                    <span class="sr-only">${app.escapeHtml(style.label)}.</span>
                                    ${item.hint ? `<span class="mt-0.5 block text-caption text-ink-subtle">${app.escapeHtml(item.hint)}</span>` : ''}
                                </span>
                                ${interactive ? '<svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0 self-center text-ink-subtle" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6l-6 6" /></svg>' : ''}
                            `;

                            return interactive
                                ? `<li><button type="button" class="flex min-h-11 w-full items-start gap-3 py-3 text-start transition-colors hover:bg-surface/60 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand" data-thesis-courses="${app.escapeHtml(item.key)}" aria-haspopup="dialog">${inner}</button></li>`
                                : `<li class="flex items-start gap-3 py-3">${inner}</li>`;
                        }).join('')}
                    </ul>
                    ${warnings.length ? `
                        <div class="${app.alertTone('warning')} mb-0" role="status">
                            <ul class="flex flex-col gap-1">${warnings.map((warning) => `<li>${app.escapeHtml(warning.message)}</li>`).join('')}</ul>
                        </div>
                    ` : ''}
                    <p class="text-caption text-ink-subtle">Perkiraan dari data portal, bukan keputusan resmi program studi.</p>
                `;
                renderPrep();
            };

            const openCourses = (key) => {
                const courses = key === 'nilai_d' ? readinessData?.courses?.d : readinessData?.courses?.e;
                const isD = key === 'nilai_d';

                if (!Array.isArray(courses) || !courses.length) {
                    return;
                }

                app.openSheet({
                    pretitle: 'Kesiapan skripsi',
                    title: isD ? `Mata kuliah bernilai D (${courses.length})` : `Mata kuliah bernilai E (${courses.length})`,
                    body: `
                        <ul class="flex flex-col divide-y divide-border-light">
                            ${courses.map((course) => `
                                <li class="flex items-start justify-between gap-3 py-3">
                                    <span class="min-w-0">
                                        <span class="block text-sm font-semibold text-ink">${app.escapeHtml(course.name || course.code || 'Mata kuliah')}</span>
                                        <span class="block text-caption text-ink-subtle">${app.escapeHtml([course.code, course.sks ? `${course.sks} SKS` : null, course.semester ? `Semester ${course.semester}` : null].filter(Boolean).join(' · '))}</span>
                                    </span>
                                    ${app.renderBadge(course.letter || (isD ? 'D' : 'E'), isD ? 'warning' : 'danger')}
                                </li>
                            `).join('')}
                        </ul>
                        <p class="mt-3 text-sm text-ink-muted">${isD ? 'Ulang di Semester Pendek atau semester berikutnya bila nilai D melebihi batas.' : 'Nilai E harus diulang sampai lulus sebelum mendaftar skripsi.'}</p>
                        <a href="/transcript" class="${app.buttonClass('secondary', { small: true })} mt-4">Lihat transkrip</a>
                    `,
                });
            };

            const PREP_ITEMS = [
                { key: 'lembar_pengesahan', label: 'Lembar pengesahan ditandatangani pembimbing' },
                { key: 'naskah', label: 'Naskah skripsi (hardcopy dan softcopy)' },
                { key: 'jadwal_ujian', label: 'Jadwal ujian akhir dari program studi' },
            ];

            const renderPrep = () => {
                const manual = (readinessData?.checklist_items || []).filter((item) => item?.kind === 'manual');
                const items = manual.length ? manual.map((item) => ({ key: item.key, label: item.label })) : PREP_ITEMS;
                const checked = storage.read(`${CHECKLIST_PREFIX}${ownerKey}`) || {};
                const done = items.filter((item) => checked[item.key] === true).length;

                prepCaption.textContent = `${done} dari ${items.length} siap · tersimpan di perangkat ini saja.`;
                prepList.innerHTML = items.map((item) => `
                    <label class="flex min-h-11 cursor-pointer items-center gap-3 rounded-md px-1 py-2 hover:bg-surface/60">
                        <input type="checkbox" class="h-5 w-5 shrink-0 cursor-pointer accent-brand" data-thesis-prep="${app.escapeHtml(item.key)}" ${checked[item.key] === true ? 'checked' : ''}>
                        <span class="text-sm text-ink">${app.escapeHtml(item.label)}</span>
                    </label>
                `).join('');
            };

            prepList.addEventListener('change', (event) => {
                const input = event.target instanceof HTMLInputElement ? event.target : null;

                if (!input?.dataset.thesisPrep) {
                    return;
                }

                const key = `${CHECKLIST_PREFIX}${ownerKey}`;
                const checked = storage.read(key) || {};

                checked[input.dataset.thesisPrep] = input.checked;
                storage.write(key, checked);
                renderPrep();
                prepList.querySelector(`[data-thesis-prep="${input.dataset.thesisPrep}"]`)?.focus();
            });

            readinessHost.addEventListener('click', async (event) => {
                const target = event.target instanceof Element ? event.target : null;
                const coursesButton = target?.closest('[data-thesis-courses]');

                if (coursesButton) {
                    openCourses(coursesButton.dataset.thesisCourses);

                    return;
                }

                const syncButton = target?.closest('[data-thesis-sync-grades]');

                if (syncButton) {
                    syncButton.disabled = true;
                    syncButton.textContent = 'Menyinkronkan…';

                    try {
                        await app.request('/api/academic/grades', { fresh: true });
                    } catch (error) {
                        app.showNotice(`Nilai belum bisa disinkronkan. ${app.describeError(error)}`, 'warning');
                    }

                    await loadReadiness();
                }
            });

            statusHost.addEventListener('click', (event) => {
                if (event.target instanceof Element && event.target.closest('[data-thesis-jump]')) {
                    event.preventDefault();
                    document.getElementById('kesiapan')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    readinessTitle.focus({ preventScroll: true });
                }
            });

            document.addEventListener('visibilitychange', () => {
                if (!document.hidden && thesisPayload) {
                    updatedLabel.textContent = describeFreshness(thesisPayload);
                }
            });

            try {
                app.primeCachedView('/api/thesis', renderThesis, 'syarat & status skripsi');
                app.primeCachedView('/api/thesis/readiness', renderReadiness, 'kesiapan skripsi');
            } catch (error) {
                // A blocked device cache only means the page waits for the server.
            }

            const ready = await app.ensureAuthenticated();

            if (!ready) {
                return;
            }

            const loadReadiness = async () => {
                try {
                    const payload = await app.request('/api/thesis/readiness');

                    if (payload) {
                        renderReadiness(payload);
                    }
                } catch (error) {
                    if (readinessRendered) {
                        app.setSyncStatus(`Kesiapan skripsi belum bisa diperbarui. ${app.describeError(error)}`, 'warning');

                        return;
                    }

                    readinessHost.removeAttribute('aria-busy');
                    app.mountErrorState(readinessHost, 'Kesiapan skripsi gagal dihitung.', () => loadReadiness(), {
                        subtitle: app.describeError(error),
                    });
                }
            };

            const loadThesis = async ({ force = false } = {}) => {
                try {
                    const payload = await app.request('/api/thesis', { fresh: force });

                    if (payload) {
                        renderThesis(payload);
                        app.markViewSynced('Skripsi', payload, { force });
                    }
                } catch (error) {
                    if (thesisRendered) {
                        app.setSyncStatus(`Data skripsi belum bisa diperbarui. ${app.describeError(error)}`, 'warning');
                    } else {
                        statusHost.removeAttribute('aria-busy');
                        app.mountErrorState(statusHost, 'Data skripsi gagal dimuat.', () => loadThesis({ force }), {
                            subtitle: `${app.describeError(error)} Syarat lengkap juga ada di SiMASITA pada Portal Kampus.`,
                        });
                        requirementsHost.removeAttribute('aria-busy');
                        requirementsHost.innerHTML = app.renderEmptyState('Syarat akan tampil setelah data termuat.', 'secondary');
                        documentsHost.innerHTML = app.renderEmptyState('Dokumen akan tampil setelah data termuat.', 'secondary');
                        app.setSyncStatus('Data skripsi gagal dimuat.', 'error');
                    }
                }

                // Readiness reads the snapshots the thesis request just refreshed.
                await loadReadiness();
            };

            app.registerSyncHandler('thesis-index', loadThesis);

            await loadThesis();
        });
    </script>
@endpush
