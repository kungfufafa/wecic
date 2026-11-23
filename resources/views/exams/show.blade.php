@extends('layouts.app')

@section('title', 'Detail Ujian')
@section('page_id', 'exam-show')

{{--
    Detail Ujian: a loading header and KPI strip in #exam-hero that the
    script replaces with the exam's own (app.ui.pageHero…, app.ui.statGridKpi),
    then the soal and portal notes beside the upload column.
--}}
@section('content')
    <div id="exam-hero">
        <x-page-hero title="Detail ujian" back="{{ route('exam.index') }}" back-label="Kembali ke daftar" aria-busy="true">
            <span class="sr-only">Memuat detail ujian…</span>
            <x-skeleton class="mt-2 h-4 w-64 max-w-full" />
        </x-page-hero>
        <x-stat-strip kpi class="mb-6" aria-hidden="true">
            @for ($i = 0; $i < 4; $i++)
                <div class="flex min-w-0 flex-col gap-2 bg-panel p-4 sm:p-5">
                    <x-skeleton class="h-4 w-16" />
                    <x-skeleton class="h-7 w-20" />
                </div>
            @endfor
        </x-stat-strip>
    </div>

    {{-- An answer queued for this exam while offline: not at the portal yet. --}}
    <div class="mb-6 hidden" data-outbox-list data-outbox-match="exam:{{ e($id) }}" data-outbox-heading="Antrean offline ujian ini"></div>

    <x-split :sticky="false">
        <div class="flex flex-col gap-6" id="exam-content">
            <x-card aria-busy="true">
                <x-card.body class="flex flex-col gap-3">
                    <x-skeleton class="h-5 w-36" />
                    <x-skeleton class="h-4 w-full" />
                    <x-skeleton class="h-4 w-3/4" />
                </x-card.body>
            </x-card>
        </div>

        <x-slot:aside id="exam-actions" aria-label="Pengumpulan jawaban">
            <x-card aria-busy="true">
                <x-card.header>
                    <x-skeleton class="h-5 w-32" />
                </x-card.header>
                <x-card.body class="flex flex-col gap-3">
                    <x-skeleton class="h-14 w-full" />
                    <x-skeleton class="h-10 w-full" />
                </x-card.body>
            </x-card>
        </x-slot:aside>
    </x-split>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', async () => {
            const app = window.portalApp;
            const examId = @json($id);

            // The portal form's raw field names mean nothing to a student.
            const FIELD_LABELS = {
                'data[tipe]': 'Jenis ujian (UTS/UAS)',
                'data[kelas]': 'Kelas',
                'data[name]': 'Nama',
                'data[nama]': 'Nama',
                'data[keterangan]': 'Catatan',
                keterangan: 'Catatan',
            };
            const STATUS_LABELS = {
                uploaded: ['Sudah dikumpulkan', 'success'],
                already_uploaded: ['Sudah dikumpulkan', 'success'],
                not_uploaded: ['Belum dikumpulkan', 'warning'],
                upload_unavailable: ['Upload belum dibuka', 'neutral'],
                unknown: ['Status belum diketahui', 'neutral'],
            };
            const svgIcon = (paths, size = 'size-4') => `<svg xmlns="http://www.w3.org/2000/svg" class="${size} shrink-0" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${paths}</svg>`;
            const ICONS = {
                back: svgIcon('<path d="M15 6l-6 6l6 6" />'),
                download: svgIcon('<path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2 -2v-2" /><path d="M7 11l5 5l5 -5" /><path d="M12 4l0 12" />'),
            };

            /**
             * A KPI cell (app.ui.metric) of the divider strip (app.ui.statGridKpi).
             * `text` values (a status, a schedule) are set smaller than a number.
             */
            const renderMetric = ({ label, value, note = '', text = false }) => `
                <div class="${app.ui.metric}" data-slot="metric">
                    <div class="${app.ui.metricLabel}" data-slot="metric-label">${app.escapeHtml(label)}</div>
                    <div class="${app.ui.metricValue}${text ? ' text-base! sm:text-lg!' : ''}" data-slot="metric-value">${app.escapeHtml(value === null || value === '' ? '-' : value)}</div>
                    ${note ? `<div class="${app.ui.metricNote}" data-slot="metric-note">${app.escapeHtml(note)}</div>` : ''}
                </div>
            `;
            const statusBadge = (status) => STATUS_LABELS[String(status || '').toLowerCase()] || [status || 'Belum diketahui', 'neutral'];
            // The server takes PDF, ZIP or RAR up to 20 MB.
            const EXAM_FILE_TYPES = /\.(pdf|zip|rar)$/i;
            const EXAM_FILE_MAX_BYTES = 20 * 1024 * 1024;

            /**
             * Only the fields a student fills in on the portal (a name or note);
             * the hidden exam type, class and id are the portal's own.
             */
            const buildOverrideInputs = (form) => {
                const fields = form.fields || {};
                const editable = Array.isArray(form.editable_fields)
                    ? form.editable_fields
                    : Object.keys(fields).filter((name) => !/^(id|cmd|data\[(id|tipe|kelas)\])$/i.test(String(name)));
                const entries = editable
                    .filter((name) => Object.prototype.hasOwnProperty.call(fields, name))
                    .map((name) => [name, fields[name]]);
                const missingTipe = !String(fields['data[tipe]'] || '').trim();
                const missingKelas = !String(fields['data[kelas]'] || '').trim();

                if (!entries.length && !missingTipe && !missingKelas) {
                    return '';
                }

                return `
                    ${missingTipe ? `
                        <div class="${app.ui.field}">
                            <label class="${app.ui.label}" for="exam-field-tipe">${app.escapeHtml(FIELD_LABELS['data[tipe]'])}</label>
                            <select id="exam-field-tipe" class="${app.ui.select}" data-override-field="data[tipe]">
                                <option value="UTS">UTS</option>
                                <option value="UAS">UAS</option>
                                <option value="SP">SP</option>
                            </select>
                        </div>
                    ` : ''}
                    ${missingKelas ? `
                        <div class="${app.ui.field}">
                            <label class="${app.ui.label}" for="exam-field-kelas">${app.escapeHtml(FIELD_LABELS['data[kelas]'])}</label>
                            <select id="exam-field-kelas" class="${app.ui.select}" data-override-field="data[kelas]">
                                <option value="TEORI">TEORI</option>
                                <option value="PRAKTIKUM">PRAKTIKUM</option>
                            </select>
                        </div>
                    ` : ''}
                    ${entries.map(([name, value], index) => `
                        <div class="${app.ui.field}">
                            <label class="${app.ui.label}" for="exam-field-${index}">${app.escapeHtml(FIELD_LABELS[name] || name.replace(/^data\[(.+)\]$/, '$1'))}</label>
                            <input id="exam-field-${index}" class="${app.ui.input}" data-override-field="${app.escapeHtml(name)}" value="${app.escapeHtml(value)}">
                        </div>
                    `).join('')}
                `;
            };

            const fileNameFromUrl = (value) => {
                const rawValue = String(value || '').trim();

                if (!rawValue) {
                    return 'Lampiran ujian';
                }

                try {
                    const parsed = new URL(rawValue, app.campusBase);
                    const filename = parsed.pathname.split('/').filter(Boolean).pop();

                    return filename ? decodeURIComponent(filename) : 'Lampiran ujian';
                } catch (error) {
                    const filename = rawValue.split(/[/?#]/).filter(Boolean).pop();

                    return filename || 'Lampiran ujian';
                }
            };

            const isDownloadableLink = (link) => {
                const haystack = `${link?.url || ''} ${link?.text || ''}`.toLowerCase();

                return Boolean(link?.downloadable)
                    || link?.type === 'file'
                    || /\/portal\/files\//i.test(haystack)
                    || /\.(pdf|zip|rar|docx?|xlsx?|pptx?)($|[?#])/i.test(haystack);
            };

            const linkLabel = (link, fallback = 'Lampiran ujian') => {
                const text = String(link?.text || '').trim();

                if (text && !/^(https?:)?\/?\//i.test(text) && !text.includes('/')) {
                    return text;
                }

                return fileNameFromUrl(link?.url || text || fallback);
            };

            /**
             * The soal and attachments as rows of one card: its name, kind and
             * link, and Buka / Download.
             */
            const renderFileLinks = (links) => {
                if (!links.length) {
                    return app.renderEmptyState('Belum ada soal atau lampiran ujian.', 'secondary', '', { icon: 'file' });
                }

                return `
                    <section class="${app.ui.card} overflow-hidden" data-slot="card" aria-labelledby="exam-files-title">
                        <div class="${app.ui.cardHeader}">
                            <div class="${app.ui.sectionTitleRow}">
                                <h2 class="${app.ui.cardTitle}" id="exam-files-title">Soal &amp; lampiran</h2>
                                <span class="${app.ui.sectionCount}">${links.length}</span>
                            </div>
                        </div>
                        <ul class="${app.ui.list}">
                        ${links.map((link, index) => {
                            const absoluteUrl = app.absoluteCampusUrl(link.url);
                            const filename = fileNameFromUrl(link.url);
                            const title = linkLabel(link, filename);
                            const downloadUrl = app.buildCampusDownloadUrl(link.url, filename);
                            const kind = /soal/i.test(`${link.url || ''} ${link.text || ''}`) ? 'Soal ujian' : `Lampiran ${index + 1}`;

                            return `
                                <li class="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:px-5">
                                    <div class="min-w-0 flex-1">
                                        <h3 class="text-sm font-medium leading-snug text-ink">${app.escapeHtml(title)}</h3>
                                        <div class="mt-0.5 flex min-w-0 flex-wrap items-center gap-x-1.5 text-xs text-ink-muted">
                                            <span>${app.escapeHtml(kind)}</span>
                                            ${filename !== title ? `<span class="min-w-0 truncate before:me-1.5 before:content-['·']">${app.escapeHtml(filename)}</span>` : ''}
                                        </div>
                                    </div>
                                    <div class="flex shrink-0 flex-wrap gap-2 sm:justify-end">
                                        ${absoluteUrl ? `<a href="${app.escapeHtml(absoluteUrl)}" target="_blank" rel="noreferrer" class="${app.buttonClass('secondary', { small: true })}">Buka</a>` : ''}
                                        ${downloadUrl ? `<a href="${app.escapeHtml(downloadUrl)}" class="${app.buttonClass('secondary', { small: true })}">Download</a>` : ''}
                                    </div>
                                </li>
                            `;
                        }).join('')}
                        </ul>
                    </section>
                `;
            };

            const renderNoticeList = (alerts, requirements) => {
                const items = [
                    ...(alerts || []).map((text) => ({ label: 'Alert', text, labelClass: app.ui.text.warning })),
                    ...(requirements || []).map((text) => ({ label: 'Persyaratan', text, labelClass: app.ui.text.muted })),
                ];

                if (!items.length) {
                    return '';
                }

                return `
                    <div class="${app.ui.card}" data-slot="card">
                        <div class="${app.ui.cardHeader}">
                            <h3 class="${app.ui.cardTitle}">Catatan portal</h3>
                        </div>
                        <div class="${app.ui.cardBody}">
                            ${items.map((item) => `
                                <div class="${app.ui.listRow}">
                                    <div class="min-w-0 flex-1">
                                        <div class="mb-0.5 text-xs font-medium ${item.labelClass}">${app.escapeHtml(item.label)}</div>
                                        <div class="text-sm leading-relaxed text-ink">${app.escapeHtml(item.text)}</div>
                                    </div>
                                </div>
                            `).join('')}
                        </div>
                    </div>
                `;
            };

            const renderPortalLinks = (links) => {
                if (!links.length) {
                    return '';
                }

                return `
                    <div class="${app.ui.card} overflow-hidden" data-slot="card">
                        <div class="${app.ui.cardHeader}">
                            <h3 class="${app.ui.cardTitle}">Link portal</h3>
                        </div>
                        <div class="divide-y divide-border-light">
                            ${links.map((link) => {
                                const absoluteUrl = app.absoluteCampusUrl(link.url);

                                return `
                                    <a href="${app.escapeHtml(absoluteUrl || link.url)}" target="_blank" rel="noreferrer" class="flex items-start justify-between gap-3 px-4 py-3 transition-colors hover:bg-surface/50 focus-visible:bg-surface/50 focus-visible:outline-none sm:px-5">
                                        <div class="min-w-0">
                                        <div class="text-sm font-medium leading-snug text-ink">${app.escapeHtml(linkLabel(link, 'Buka portal'))}</div>
                                        <div class="mt-0.5 break-all text-xs text-ink-subtle">${app.escapeHtml(link.url || '')}</div>
                                        </div>
                                        <svg xmlns="http://www.w3.org/2000/svg" class="mt-0.5 size-4 shrink-0 text-ink-subtle" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 6h-6a2 2 0 0 0 -2 2v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-6" /><path d="M11 13l9 -9" /><path d="M15 4h5v5" /></svg>
                                    </a>
                                `;
                            }).join('')}
                        </div>
                    </div>
                `;
            };

            const renderUploadPanel = (detail, form, isUploadReady, isPortalFormOpen) => {
                const uploadTone = isPortalFormOpen ? 'success' : 'secondary';

                return `
                <div class="${app.ui.card}" data-slot="card">
                    <div class="${app.ui.cardHeader}">
                        <h3 class="${app.ui.cardTitle}">Upload jawaban</h3>
                    </div>
                    <div class="${app.ui.cardBody} flex flex-col gap-4">
                        ${!isPortalFormOpen ? `
                            <div class="${app.alertTone('secondary')}">
                                <div class="font-medium leading-snug text-ink">Form portal belum terbuka</div>
                                <div class="mt-0.5 text-xs ${app.textToneClass('secondary')}">${app.escapeHtml(statusBadge(detail.status)[0])} · Kirim langsung — portal menentukan hasilnya.</div>
                            </div>
                        ` : ''}
                        ${isUploadReady ? `
                            <form id="exam-upload-form" class="flex flex-col gap-4">
                                <div class="${app.ui.field}">
                                    <label for="exam-file" class="${app.ui.label}">Lampiran jawaban</label>
                                    <input id="exam-file" type="file" class="${app.ui.fileInput}" accept=".pdf,.zip,.rar" aria-describedby="exam-file-hint" required>
                                    <p id="exam-file-hint" class="${app.ui.hint}">PDF, ZIP, atau RAR, maks. 20 MB.</p>
                                </div>
                                ${buildOverrideInputs(form)}
                                <div class="${app.ui.stickyAction}" data-sticky-action>
                                    <button type="submit" class="${app.buttonClass('primary')} w-full" data-online-required data-outbox-capable>Kirim jawaban</button>
                                </div>
                            </form>
                        ` : `<div class="flex">${app.renderDisabledButton('Upload belum tersedia')}</div>`}
                    </div>
                </div>
                `;
            };

            const renderSubmissionMemoryPanel = (detail) => {
                const latest = detail.latest_submission || null;
                const storedSubmissions = detail.stored_submissions || [];
                const currentSubmissions = detail.submissions || [];
                const hasLiveDeleteId = currentSubmissions.some((record) => record?.submission_id);

                if (!latest && !storedSubmissions.length && !currentSubmissions.length) {
                    return '';
                }

                const fields = [
                    { label: 'File', value: latest?.file_name || (latest?.file_url ? fileNameFromUrl(latest.file_url) : null) },
                    { label: 'Tanggal upload', value: latest?.uploaded_at_label },
                ].filter((item) => item.value);

                return `
                    <div class="${app.ui.card}" data-slot="card">
                        <div class="${app.ui.cardHeader}">
                            <h3 class="${app.ui.cardTitle}">Jawaban terkirim</h3>
                        </div>
                        <div class="${app.ui.cardBody}">
                            <div class="flex flex-col gap-3">
                                ${fields.map((item) => `
                                    <div>
                                        <div class="mb-1 text-xs font-medium text-ink-subtle">${app.escapeHtml(item.label)}</div>
                                        <div class="break-all text-sm font-medium leading-snug text-ink">${app.escapeHtml(item.value)}</div>
                                    </div>
                                `).join('')}
                            </div>
                            ${latest?.submission_id && !hasLiveDeleteId ? `
                                <div class="${app.alertTone('warning')} mt-3">
                                    Jawaban ini tercatat pernah dikirim, tetapi portal sedang tidak menampilkannya.
                                </div>
                            ` : ''}
                        </div>
                    </div>
                `;
            };

            const render = (payload) => {
                const detail = payload.data?.exam_detail || {};
                const form = detail.form || {};
                const isPortalFormOpen = form.portal_form_open ?? (form.available === true && form.available_via_direct_submit !== true);
                const isUploadReady = form.available !== false;
                const relatedLinks = detail.related_links || [];
                const fileLinks = relatedLinks.filter(isDownloadableLink);
                const portalLinks = relatedLinks.filter((link) => !isDownloadableLink(link) && link?.type !== 'action');
                const primaryFile = fileLinks[0] || null;
                const hero = document.getElementById('exam-hero');

                const course = detail.course || {};
                const [statusLabel] = statusBadge(detail.status);
                const portalTitle = String(detail.title || '');
                const isGenericTitle = /sipandai|portal/i.test(portalTitle);

                const heroTitle = course.name || (isGenericTitle ? 'Detail ujian' : portalTitle) || 'Detail ujian';
                const heroEyebrow = course.code ? `Ujian · ${course.code}` : 'Detail ujian';
                const heroDescription = course.lecturer || (!isGenericTitle ? detail.description : '') || '';
                const heroActions = [
                    primaryFile ? `<a href="${app.escapeHtml(app.buildCampusDownloadUrl(primaryFile.url, fileNameFromUrl(primaryFile.url)))}" class="${app.buttonClass('primary')}">${ICONS.download}Download soal</a>` : '',
                ].filter(Boolean).join('');

                hero.innerHTML = `
                    <header class="${app.ui.pageHero}" data-slot="page-hero">
                        <a href="/exam" class="${app.ui.backLink} mb-2">${ICONS.back}<span>Kembali ke daftar</span></a>
                        <div class="${app.ui.pageHeroRow}">
                            <div class="min-w-0">
                                <p class="${app.ui.pageEyebrow}">${app.escapeHtml(heroEyebrow)}</p>
                                <h1 class="${app.ui.pageHeroTitle}">${app.escapeHtml(heroTitle)}</h1>
                                ${heroDescription ? `<p class="${app.ui.pageHeroSubtitle}">${app.escapeHtml(heroDescription)}</p>` : ''}
                            </div>
                            ${heroActions ? `<div class="${app.ui.pageHeroActions}" data-slot="page-hero-actions">${heroActions}</div>` : ''}
                        </div>
                    </header>
                    <div class="${app.ui.statGridKpi} mb-6" data-slot="stat-strip">
                        ${renderMetric({ label: 'Status', value: statusLabel, text: true })}
                        ${renderMetric({ label: 'Soal', value: fileLinks.length || '0', note: fileLinks.length ? '' : 'Belum diunggah' })}
                        ${renderMetric({ label: 'Pengumpulan', value: isPortalFormOpen ? 'Dibuka' : 'Kirim langsung', text: true })}
                        ${renderMetric({ label: 'Jadwal', value: course.schedule || 'Belum ada', text: true })}
                    </div>
                `;

                document.getElementById('exam-content').innerHTML = `
                    ${renderFileLinks(fileLinks)}
                    ${renderNoticeList(detail.alerts || [], detail.requirements || [])}
                `;

                document.getElementById('exam-actions').innerHTML = `
                    ${renderUploadPanel(detail, form, isUploadReady, isPortalFormOpen)}
                    ${renderSubmissionMemoryPanel(detail)}
                    ${renderPortalLinks(portalLinks)}
                `;
                app.refreshOnlineRequiredState();

                const uploadForm = document.getElementById('exam-upload-form');

                if (uploadForm) {
                    uploadForm.addEventListener('submit', async (event) => {
                        event.preventDefault();

                        if (!app.requireOnlineAction('Upload ujian butuh koneksi internet.', { queueable: true })) {
                            return;
                        }

                        const file = document.getElementById('exam-file').files[0];

                        if (!file) {
                            app.showNotice('Pilih file ujian terlebih dahulu.', 'warning');

                            return;
                        }

                        // Checked here so a wrong file is not uploaded in full before the server refuses it.
                        if (!EXAM_FILE_TYPES.test(file.name)) {
                            app.showNotice('File ujian harus berupa PDF, ZIP, atau RAR.', 'warning');

                            return;
                        }

                        if (file.size > EXAM_FILE_MAX_BYTES) {
                            app.showNotice('Ukuran file ujian maksimal 20 MB.', 'warning');

                            return;
                        }

                        const formData = new FormData();
                        formData.append('id', examId);
                        formData.append('attachment', file);

                        const fields = form.fields || {};
                        ['data[tipe]', 'data[kelas]'].forEach((fieldName) => {
                            const fieldValue = String(fields[fieldName] || '').trim();

                            if (fieldValue !== '') {
                                formData.set(`form_values[${fieldName}]`, fieldValue);
                            }
                        });

                        document.querySelectorAll('[data-override-field]').forEach((input) => {
                            const value = input.value.trim();

                            if (value !== '') {
                                formData.set(`form_values[${input.dataset.overrideField}]`, value);
                            }
                        });

                        await app.guardFormSubmit(uploadForm, () => app.withSubmitLoading(uploadForm.querySelector('button[type="submit"]'), async () => {
                            try {
                                const result = await app.request('/api/exam', {
                                    method: 'POST',
                                    formData,
                                });

                                // Offline session: the answer waits in the outbox, not at the portal.
                                if (app.handleQueuedActionResult(result)) {
                                    uploadForm.reset();

                                    return;
                                }

                                const operationId = result?.data?.operation_id;

                                if (operationId) {
                                    // Keep the form locked until the portal has the file.
                                    await app.trackOperation(operationId, 'Upload ujian');
                                } else {
                                    app.showNotice(result?.message || 'Berkas ujian berhasil dikirim.', 'success');
                                }
                            } catch (error) {
                                // An upload of this exam is still running (another tab, a
                                // reload): follow it instead of sending the answer again.
                                if (error.status === 409 && error.payload?.data?.operation_id) {
                                    app.showNotice(error.payload.message, 'warning');
                                    await app.trackOperation(error.payload.data.operation_id, 'Upload ujian', { announce: false });

                                    return;
                                }

                                app.showNotice(error.payload?.message || error.message, 'error');
                            }
                        }));
                    });
                }
            };

            app.primeCachedView(`/api/exam/${examId}`, render, 'detail ujian');

            const ready = await app.ensureAuthenticated();

            if (!ready) {
                return;
            }

            const loadExamDetail = async ({ force = false, background = false } = {}) => {
                try {
                    const payload = await app.request(`/api/exam/${examId}`, { fresh: force, background });
                    render(payload);
                    app.markViewSynced('Detail ujian', payload, { force });
                } catch (error) {
                    const notFound = error.status === 404;
                    app.setSyncStatus(notFound ? 'Ujian tidak ditemukan.' : 'Detail ujian gagal dimuat.', 'error');
                    app.mountErrorState(
                        document.getElementById('exam-hero'),
                        notFound ? 'Ujian tidak ditemukan.' : 'Detail ujian gagal dimuat.',
                        notFound ? null : () => loadExamDetail({ force }),
                        { subtitle: notFound ? 'Link yang kamu buka tidak cocok dengan ujian mana pun di daftar ujianmu.' : app.describeError(error) }
                    );
                    document.getElementById('exam-hero').firstElementChild?.classList.add('mb-6');
                    document.getElementById('exam-content').innerHTML = notFound ? `<a href="/exam" class="${app.buttonClass('primary')}">Ke daftar ujian</a>` : '';
                    document.getElementById('exam-actions').innerHTML = '';
                }
            };

            app.registerSyncHandler('exam-show', loadExamDetail);

            await loadExamDetail();
        });
    </script>
@endpush
