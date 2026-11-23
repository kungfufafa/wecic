@extends('layouts.app')

@section('title', 'Skripsi')
@section('page_id', 'thesis-index')

@php
    $portalThesisUrl = \App\Services\PortalEndpointRegistry::url('student_skripsi');
@endphp

@section('content')
    <x-page-hero title="Skripsi">
        @if (str_starts_with($portalThesisUrl, 'https://') || str_starts_with($portalThesisUrl, 'http://'))
            <x-slot:actions>
                <x-button variant="secondary" size="sm" href="{{ $portalThesisUrl }}" target="_blank" rel="noopener noreferrer" data-online-required>
                    <x-icon name="external-link" size="sm" />
                    SiMASITA<span class="sr-only"> di Portal Kampus (tab baru)</span>
                </x-button>
            </x-slot:actions>
        @endif
    </x-page-hero>

    <x-split at="xl" id="thesis-page" data-portal-thesis-url="{{ $portalThesisUrl }}">
        <section id="thesis-status" aria-labelledby="thesis-status-title" aria-busy="true">
            <x-card>
                <x-card.header class="flex-wrap">
                    <x-card.title id="thesis-status-title">Status e-Skripsi</x-card.title>
                    <x-skeleton class="h-5 w-24" />
                </x-card.header>
                <x-card.body class="flex flex-col gap-3">
                    <span class="sr-only">Memuat status skripsi…</span>
                    <x-skeleton class="h-4 w-3/4" />
                    <x-skeleton class="h-4 w-1/2" />
                </x-card.body>
            </x-card>
        </section>

        <x-card as="section" class="scroll-mt-20" id="kesiapan" aria-labelledby="thesis-readiness-title">
            <x-card.header class="flex-wrap">
                <div class="min-w-0">
                    <x-card.title id="thesis-readiness-title" tabindex="-1">Kesiapan skripsi</x-card.title>
                    <x-card.subtitle id="thesis-readiness-caption" class="empty:hidden"></x-card.subtitle>
                </div>
                <span id="thesis-readiness-badge"></span>
            </x-card.header>
            <x-card.body class="flex flex-col gap-5" id="thesis-readiness" aria-busy="true">
                <span class="sr-only">Memuat kesiapan skripsi…</span>
                <div class="flex items-center gap-4 rounded-lg bg-surface p-4">
                    <x-skeleton class="size-24 rounded-full!" />
                    <div class="flex flex-1 flex-col gap-2">
                        <x-skeleton class="h-5 w-32" />
                        <x-skeleton class="h-4 w-48 max-w-full" />
                    </div>
                </div>
                @for ($i = 0; $i < 4; $i++)
                    <x-skeleton class="h-11 w-full" />
                @endfor
            </x-card.body>
        </x-card>

        <x-card as="section" aria-labelledby="thesis-prep-title">
            <details class="group" id="thesis-prep">
                <summary class="flex min-h-11 cursor-pointer list-none items-center justify-between gap-3 rounded-xl px-4 py-3.5 transition-colors hover:bg-surface/50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40 group-open:rounded-b-none group-open:border-b group-open:border-border-light sm:px-5 sm:py-4 [&::-webkit-details-marker]:hidden">
                    <span class="min-w-0">
                        <span class="block text-sm font-semibold tracking-tight text-ink" id="thesis-prep-title">Persiapan ujian TA</span>
                        <span class="mt-0.5 block text-[13px] text-ink-muted" id="thesis-prep-caption">Daftar pribadi</span>
                    </span>
                    <svg xmlns="http://www.w3.org/2000/svg" class="size-5 shrink-0 text-ink-subtle transition-transform group-open:rotate-180" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6l6 -6" /></svg>
                </summary>
                <x-card.body class="flex flex-col gap-1" id="thesis-prep-list">
                    <x-skeleton class="h-11 w-full" />
                </x-card.body>
            </details>
        </x-card>

        <x-slot:aside aria-label="Persyaratan dan SOP skripsi">
            <x-card as="section" class="scroll-mt-20" id="persyaratan" aria-labelledby="thesis-requirements-title" title="Persyaratan & SOP" title-id="thesis-requirements-title">
                <x-card.body class="flex flex-col gap-5" id="thesis-requirements" aria-busy="true">
                    <x-skeleton class="h-4 w-2/3" />
                    <x-skeleton class="h-4 w-full" />
                    <x-skeleton class="h-4 w-5/6" />
                </x-card.body>
            </x-card>

            <x-card as="section" aria-labelledby="thesis-documents-title" title="Dokumen" title-id="thesis-documents-title">
                <x-card.body class="has-[>ul]:p-0" id="thesis-documents">
                    <x-skeleton class="h-11 w-full" />
                </x-card.body>
            </x-card>
        </x-slot:aside>
    </x-split>
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
            const portalThesisUrl = app.safeHref(page.dataset.portalThesisUrl) || '#';
            const CHECKLIST_PREFIX = 'mycic-skripsi-checklist:';
            const STATUS_STYLE = {
                passed: { tone: 'success', label: 'Terpenuhi', icon: '<path d="M5 12l5 5l10 -10" />' },
                failed: { tone: 'danger', label: 'Belum terpenuhi', icon: '<path d="M18 6l-12 12" /><path d="M6 6l12 12" />' },
                unknown: { tone: 'secondary', label: 'Perlu dicek', icon: '<path d="M8 8a3.5 3 0 0 1 3.5 -3h1a3.5 3 0 0 1 3.5 3a3 3 0 0 1 -2 3a3 4 0 0 0 -2 4" /><path d="M12 19l0 .01" />' },
            };
            // Round step markers of the readiness stepper, in each status's tone.
            const STEP_TONE = { success: 'bg-success-soft text-success', danger: 'bg-danger-soft text-danger', secondary: 'bg-surface text-ink-muted' };
            // Numbered step markers of the "Menuju ujian TA" list: neutral, the number is the information.
            const STEP_NUMBER = 'relative z-10 flex size-7 shrink-0 items-center justify-center rounded-full border border-border bg-panel text-xs font-semibold text-ink-muted tabular-nums';
            const numberFormatter = new Intl.NumberFormat('id-ID', { maximumFractionDigits: 2 });
            // IPK is written with a dot decimal (3.45), as on Nilai, Transkrip and KRS.
            const formatIpk = (value) => (Number.isFinite(Number(value)) ? Number(value).toFixed(2) : '–');
            let thesisData = null;
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

            const formatValue = (key, value) => {
                if (value === null || value === undefined || value === '') {
                    return '–';
                }

                if (key === 'ipk') {
                    return formatIpk(value);
                }

                return typeof value === 'number' ? numberFormatter.format(value) : String(value);
            };

            const statusIcon = (status) => {
                const style = STATUS_STYLE[status] || STATUS_STYLE.unknown;

                return `<span class="${app.ui.iconTileBaseFull} ${STEP_TONE[style.tone]} relative z-10 size-8 ring-2 ring-panel" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" class="size-4" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">${style.icon}</svg></span>`;
            };

            const renderStatus = (data) => {
                statusHost.removeAttribute('aria-busy');
                const status = data?.status || {};
                const hint = data?.krs_hint || {};
                const awaiting = hint.awaiting_status === true;
                const courseName = hint.course?.name || 'Skripsi';
                let badge = '';
                let body = '';

                if (status.state === 'not_enrolled') {
                    badge = app.renderBadge('Belum mengambil skripsi', 'secondary');
                    body = `
                        <p class="text-sm text-ink-muted">${app.escapeHtml(status.message || 'Belum mengambil mata kuliah skripsi semester ini.')}</p>
                        ${awaiting ? `<div class="${app.ui.alert.warning}" role="status">KRS memuat <strong class="font-medium">${app.escapeHtml(courseName)}</strong>, menunggu status e-Skripsi aktif.</div>` : ''}
                        <a href="#kesiapan" class="${app.ui.button.secondarySm} self-start" data-thesis-jump>Cek kesiapan kamu</a>
                    `;
                } else if (status.state === 'active_unparsed') {
                    const fields = (Array.isArray(status.raw_sections) ? status.raw_sections : [])
                        .flatMap((section) => (Array.isArray(section?.fields) ? section.fields : []))
                        .slice(0, 12);

                    badge = app.renderBadge('Skripsi aktif', 'brand');
                    body = `
                        ${status.message ? `<p class="text-sm text-ink-muted">${app.escapeHtml(status.message)}</p>` : ''}
                        ${fields.length ? app.renderKeyValueList(fields.map((field) => ({ label: field.label, value: field.value }))) : '<p class="text-sm text-ink-muted">Data belum terbaca lengkap. Lihat di Portal Kampus.</p>'}
                        <a href="${app.escapeHtml(portalThesisUrl)}" target="_blank" rel="noopener noreferrer" class="${app.ui.button.secondarySm} self-start" data-online-required>Buka di Portal Kampus<span class="sr-only"> (tab baru)</span></a>
                    `;
                } else {
                    badge = app.renderBadge('Status belum terbaca', 'secondary');
                    body = `
                        <p class="text-sm text-ink-muted">${status.available === false ? 'Halaman e-Skripsi portal belum bisa dibuka.' : 'Halaman e-Skripsi portal kosong.'}</p>
                    `;
                }

                statusHost.innerHTML = `
                    <div class="${app.ui.card}" data-slot="card">
                        <div class="${app.ui.cardHeader} flex-wrap">
                            <h2 class="${app.ui.cardTitle}" id="thesis-status-title">Status e-Skripsi</h2>
                            ${badge}
                        </div>
                        <div class="${app.ui.cardBody} flex flex-col gap-3">${body}</div>
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
                    thresholds.min_ipk !== undefined ? `IPK ≥ ${formatIpk(thresholds.min_ipk)}` : null,
                    thresholds.max_d !== undefined ? `maks. ${thresholds.max_d} nilai D` : null,
                    thresholds.allow_e === false ? 'tanpa nilai E' : null,
                ].filter(Boolean);

                requirementsHost.innerHTML = `
                    ${summary.length ? `
                        <div class="rounded-lg bg-surface p-3">
                            <p class="text-xs font-medium text-ink-muted">Ambang untuk jenjang ${app.escapeHtml(jenjang || 'kamu')}</p>
                            <p class="mt-1 text-sm font-medium leading-snug text-ink">${app.escapeHtml(summary.join(' · '))}</p>
                            ${isDefault ? '<p class="mt-1 text-xs text-ink-subtle">Sebagian memakai nilai bawaan · cek SOP.</p>' : ''}
                        </div>
                    ` : ''}
                    ${thesisItems.length ? `
                        <div>
                            <h3 class="text-sm font-medium leading-snug text-ink">Syarat pengajuan skripsi</h3>
                            <ul class="mt-2 flex flex-col gap-2">
                                ${thesisItems.map((item) => `<li class="flex gap-2.5 text-sm text-ink-muted"><span class="mt-2 size-1.5 shrink-0 rounded-full ${app.ui.fill.neutral}" aria-hidden="true"></span><span class="min-w-0">${app.escapeHtml(item)}</span></li>`).join('')}
                            </ul>
                        </div>
                    ` : ''}
                    ${examItems.length ? `
                        <div>
                            <h3 class="text-sm font-medium leading-snug text-ink">Menuju ujian TA</h3>
                            <ol class="mt-3 flex flex-col">
                                ${examItems.map((item, index) => `
                                    <li class="relative flex gap-3 pb-4 last:pb-0">
                                        ${index < examItems.length - 1 ? '<span class="absolute start-3.5 top-7 bottom-0 w-px bg-border" aria-hidden="true"></span>' : ''}
                                        <span class="${STEP_NUMBER}" aria-hidden="true">${index + 1}</span>
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
                    <ul class="${app.ui.list}">
                        ${documents.map((documentItem) => {
                            const href = app.buildCampusDownloadUrl(documentItem.path, documentItem.filename);

                            return href ? `
                                <li>
                                    <a href="${app.escapeHtml(href)}" target="_blank" rel="noopener" class="${app.ui.listRowFlushInteractive}" data-online-required>
                                        <span class="${app.ui.iconTile.neutral} size-9" aria-hidden="true">${app.renderIcon('file', 'md')}</span>
                                        <span class="${app.ui.listRowBody}">
                                            <span class="block text-sm font-medium leading-snug text-ink wrap-break-word">${app.escapeHtml(documentItem.label || documentItem.filename || 'Dokumen')}</span>
                                            <span class="mt-0.5 block text-xs text-ink-subtle">PDF</span>
                                        </span>
                                        ${app.renderIcon('external-link', 'sm', app.ui.listRowChevron)}
                                    </a>
                                </li>
                            ` : '';
                        }).join('')}
                    </ul>
                `;
                app.refreshOnlineRequiredState();
            };

            const renderThesis = (payload) => {
                const data = payload?.data;

                if (!data || typeof data !== 'object') {
                    return;
                }

                thesisData = data;
                thesisRendered = true;
                ownerKey = data.student?.nim ? hashOwner(data.student.nim) : ownerKey;
                renderStatus(data);
                renderRequirements(data);
                renderDocuments(data);
                renderPrep();
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
                    <div class="relative size-24 shrink-0" role="img" aria-label="${app.escapeHtml(label)}">
                        <svg viewBox="0 0 100 100" class="size-24 -rotate-90" aria-hidden="true">
                            <circle cx="50" cy="50" r="42" fill="none" stroke="currentColor" stroke-width="10" class="text-border"></circle>
                            ${known ? `<circle cx="50" cy="50" r="42" fill="none" stroke="currentColor" stroke-width="10" stroke-linecap="round" class="${tone}" stroke-dasharray="${circumference.toFixed(2)}" stroke-dashoffset="${(circumference * (1 - percent / 100)).toFixed(2)}"></circle>` : ''}
                        </svg>
                        <span class="absolute inset-0 flex flex-col items-center justify-center" aria-hidden="true">
                            <span class="text-lg font-semibold leading-none tracking-tight text-ink tabular-nums">${known ? app.escapeHtml(String(progress.current)) : '–'}</span>
                            <span class="mt-0.5 text-[11px] font-medium text-ink-subtle tabular-nums">/ ${app.escapeHtml(String(progress?.target ?? '–'))} SKS</span>
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
                readinessCaption.textContent = `Jenjang ${data.jenjang}${data.threshold_source !== 'portal' ? ' · ambang bawaan' : ''}`;

                const sksHint = sksItem?.status === 'passed'
                    ? 'SKS minimal sudah tercapai.'
                    : (sksItem?.hint || '');
                const warnings = Array.isArray(data.insights?.warnings) ? data.insights.warnings : [];

                readinessHost.innerHTML = `
                    <div class="flex items-center gap-4 rounded-lg bg-surface p-4">
                        ${sksRing(data.sks_progress)}
                        <div class="min-w-0">
                            <p class="text-sm font-medium leading-snug text-ink">SKS lulus</p>
                            <p class="mt-0.5 text-sm text-ink-muted">${app.escapeHtml(sksHint)}</p>
                        </div>
                    </div>
                    ${gradesPending ? `
                        <div class="${app.ui.alert.info} flex flex-wrap items-center justify-between gap-3" role="status">
                            <span>${sources.grades === 'failed'
                                ? 'Nilai belum bisa dibaca dari portal.'
                                : (sources.grades === 'synced' ? 'Portal belum mencatat nilai (KHS) untukmu.' : 'Nilai sedang disinkronkan dari portal.')}</span>
                            <button type="button" class="${app.ui.button.secondarySm}" data-thesis-sync-grades>Sinkronkan nilai</button>
                        </div>
                    ` : ''}
                    <ol class="flex flex-col" aria-label="Syarat kesiapan">
                        ${rows.map((item, index) => {
                            const style = STATUS_STYLE[item.status] || STATUS_STYLE.unknown;
                            const rail = index < rows.length - 1 ? '<span class="absolute start-4 top-8 -bottom-2 w-px bg-border" aria-hidden="true"></span>' : '';
                            const courseList = item.key === 'nilai_d' ? data.courses?.d : (item.key === 'nilai_e' ? data.courses?.e : null);
                            const interactive = Array.isArray(courseList) && courseList.length > 0;
                            const inner = `
                                ${statusIcon(item.status)}
                                <span class="min-w-0 flex-1 pt-1.5">
                                    <span class="flex flex-wrap items-baseline justify-between gap-x-3">
                                        <span class="text-sm font-medium leading-snug text-ink">${app.escapeHtml(item.label)}</span>
                                        <span class="text-sm font-medium tabular-nums ${app.textToneClass(style.tone === 'secondary' ? 'muted' : style.tone)}">${app.escapeHtml(rowValue(item))}</span>
                                    </span>
                                    <span class="sr-only">${app.escapeHtml(style.label)}.</span>
                                    ${item.hint ? `<span class="mt-0.5 block text-xs text-ink-subtle">${app.escapeHtml(item.hint)}</span>` : ''}
                                </span>
                                ${interactive ? '<svg xmlns="http://www.w3.org/2000/svg" class="size-4 shrink-0 self-center text-ink-subtle" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6l-6 6" /></svg>' : ''}
                            `;

                            return interactive
                                ? `<li class="relative">${rail}<button type="button" class="-mx-2 flex min-h-11 w-[calc(100%+1rem)] cursor-pointer items-start gap-3 rounded-lg px-2 py-2 text-start transition-colors hover:bg-surface/60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40" data-thesis-courses="${app.escapeHtml(item.key)}" aria-haspopup="dialog">${inner}</button></li>`
                                : `<li class="relative flex items-start gap-3 py-2">${rail}${inner}</li>`;
                        }).join('')}
                    </ol>
                    ${warnings.length ? `
                        <div class="${app.ui.alert.warning}" role="status">
                            <ul class="flex flex-col gap-1">${warnings.map((warning) => `<li>${app.escapeHtml(warning.message)}</li>`).join('')}</ul>
                        </div>
                    ` : ''}
                    <p class="text-xs text-ink-subtle">Estimasi dari data portal.</p>
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
                                        <span class="block text-sm font-medium leading-snug text-ink">${app.escapeHtml(course.name || course.code || 'Mata kuliah')}</span>
                                        <span class="block text-xs text-ink-subtle">${app.escapeHtml([course.code, course.sks ? `${course.sks} SKS` : null, course.semester ? `Semester ${course.semester}` : null].filter(Boolean).join(' · '))}</span>
                                    </span>
                                    ${app.renderBadge(course.letter || (isD ? 'D' : 'E'), isD ? 'warning' : 'danger')}
                                </li>
                            `).join('')}
                        </ul>
                        <p class="mt-3 text-sm text-ink-muted">${isD ? 'Ulang bila melebihi batas nilai D.' : 'Nilai E wajib diulang sebelum skripsi.'}</p>
                        <a href="/transcript" class="${app.ui.button.secondarySm} mt-4">Lihat transkrip</a>
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

                prepCaption.textContent = `${done}/${items.length} siap`;
                prepList.innerHTML = items.map((item) => `
                    <label class="-mx-2 flex min-h-11 cursor-pointer items-center gap-3 rounded-lg px-2 py-2 transition-colors hover:bg-surface/60">
                        <input type="checkbox" class="size-5 shrink-0 cursor-pointer rounded border-border accent-brand" data-thesis-prep="${app.escapeHtml(item.key)}" ${checked[item.key] === true ? 'checked' : ''}>
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

            const loadReadiness = async ({ background = false } = {}) => {
                try {
                    const payload = await app.request('/api/thesis/readiness', { background });

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

            const loadThesis = async ({ force = false, background = false } = {}) => {
                try {
                    const payload = await app.request('/api/thesis', { fresh: force, background });

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
                await loadReadiness({ background });
            };

            app.registerSyncHandler('thesis-index', loadThesis);

            await loadThesis();
        });
    </script>
@endpush
