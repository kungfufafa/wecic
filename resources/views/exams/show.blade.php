@extends('layouts.app')

@section('title', 'Detail Ujian')
@section('page_id', 'exam-show')

@section('content')
    <div id="exam-hero">
        {!! '<div class="mycic-alert-neutral mb-4">Menunggu ringkasan ujian.</div>' !!}
    </div>

    <div class="grid items-start gap-4 lg:grid-cols-12">
        <div class="lg:col-span-8" id="exam-content">
            {!! '<div class="mycic-alert-neutral mb-0">Menunggu konten ujian.</div>' !!}
        </div>

        <div class="flex flex-col gap-4 lg:col-span-4" id="exam-actions">
            {!! '<div class="mycic-alert-neutral mb-0">Form unggah akan muncul jika tersedia.</div>' !!}
        </div>
    </div>
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

                if (!entries.length) {
                    return '';
                }

                return `
                    <p class="mb-2 text-caption text-ink-subtle">Isian tambahan dari form portal (opsional).</p>
                    ${entries.map(([name, value], index) => `
                        <div class="mb-3">
                            <label class="mycic-label" for="exam-field-${index}">${app.escapeHtml(FIELD_LABELS[name] || name.replace(/^data\[(.+)\]$/, '$1'))}</label>
                            <input id="exam-field-${index}" class="mycic-input" data-override-field="${app.escapeHtml(name)}" value="${app.escapeHtml(value)}">
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

            const renderFileLinks = (links) => {
                if (!links.length) {
                    return app.renderEmptyState('Belum ada soal atau lampiran ujian.', 'secondary');
                }

                return `
                    <div class="mb-4 flex flex-col gap-3">
                        ${links.map((link, index) => {
                            const absoluteUrl = app.absoluteCampusUrl(link.url);
                            const filename = fileNameFromUrl(link.url);
                            const title = linkLabel(link, filename);
                            const downloadUrl = app.buildCampusDownloadUrl(link.url, filename);
                            const badge = /soal/i.test(`${link.url || ''} ${link.text || ''}`) ? 'Soal ujian' : `Lampiran ${index + 1}`;

                            return `
                                <div class="mycic-card-interactive">
                                    <div class="flex flex-col gap-3 md:flex-row md:items-center">
                                        <div class="min-w-0 flex-1">
                                            <div class="mb-2 flex flex-wrap items-center gap-2">
                                                ${app.renderBadge(badge, 'brand')}
                                                <span class="text-caption text-ink-subtle">${app.escapeHtml(filename)}</span>
                                            </div>
                                            <h3 class="mb-1 text-subtitle font-bold text-ink">${app.escapeHtml(title)}</h3>
                                            <div class="break-all text-caption text-ink-subtle">${app.escapeHtml(link.url || '')}</div>
                                        </div>
                                        <div class="shrink-0">
                                            <div class="flex flex-wrap justify-end gap-2">
                                                ${absoluteUrl ? `<a href="${app.escapeHtml(absoluteUrl)}" target="_blank" rel="noreferrer" class="${app.buttonClass('primary')}">Buka</a>` : ''}
                                                ${downloadUrl ? `<a href="${app.escapeHtml(downloadUrl)}" class="${app.buttonClass('secondary')}">Download</a>` : ''}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            `;
                        }).join('')}
                    </div>
                `;
            };

            const renderNoticeList = (alerts, requirements) => {
                const items = [
                    ...(alerts || []).map((text) => ({ label: 'Alert', text, tone: 'warning' })),
                    ...(requirements || []).map((text) => ({ label: 'Persyaratan', text, tone: 'secondary' })),
                ];

                if (!items.length) {
                    return '';
                }

                return `
                    <div class="mycic-card mb-3">
                        <div class="mycic-card-header">
                            <h3 class="mycic-card-title">Catatan portal</h3>
                        </div>
                        <div class="mycic-card-body">
                            ${items.map((item) => `
                                <div class="mycic-list-row">
                                    <div class="min-w-0 flex-1">
                                        <div class="mb-1 flex flex-wrap items-center gap-2">
                                            ${app.renderBadge(item.label, item.tone)}
                                        </div>
                                        <div class="text-ink">${app.escapeHtml(item.text)}</div>
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
                    <div class="mycic-card">
                        <div class="mycic-card-header">
                            <h3 class="mycic-card-title">Link portal</h3>
                        </div>
                        <div class="divide-y divide-border-light">
                            ${links.map((link) => {
                                const absoluteUrl = app.absoluteCampusUrl(link.url);

                                return `
                                    <a href="${app.escapeHtml(absoluteUrl || link.url)}" target="_blank" rel="noreferrer" class="block px-4 py-3 transition-colors hover:bg-surface md:px-5">
                                        <div class="font-medium text-ink">${app.escapeHtml(linkLabel(link, 'Buka portal'))}</div>
                                        <div class="break-all text-caption text-ink-subtle">${app.escapeHtml(link.url || '')}</div>
                                    </a>
                                `;
                            }).join('')}
                        </div>
                    </div>
                `;
            };

            const renderUploadPanel = (detail, form, isUploadReady) => {
                const uploadTone = isUploadReady ? 'success' : 'secondary';

                return `
                <div class="mycic-card">
                    <div class="mycic-card-header">
                        <h3 class="mycic-card-title">Upload jawaban</h3>
                    </div>
                    <div class="mycic-card-body">
                        <div class="${app.alertTone(uploadTone)} mb-3">
                            <div class="font-medium">${isUploadReady ? 'Jawaban bisa dikirim dari sini.' : 'Portal tidak menampilkan form upload saat ini.'}</div>
                            <div class="text-caption ${app.textToneClass(uploadTone)}">${app.escapeHtml(statusBadge(detail.status)[0])}</div>
                        </div>
                        ${isUploadReady ? `
                            <form id="exam-upload-form">
                                <div class="mb-3">
                                    <label for="exam-file" class="mycic-label">Lampiran jawaban</label>
                                    <input id="exam-file" type="file" class="mycic-input" accept=".pdf,.zip,.rar" aria-describedby="exam-file-hint" required>
                                    <p id="exam-file-hint" class="mt-1 text-caption text-ink-subtle">PDF, ZIP, atau RAR, maks. 20 MB.</p>
                                </div>
                                ${buildOverrideInputs(form)}
                                <div class="mycic-sticky-action">
                                    <button type="submit" class="${app.buttonClass('primary')} mycic-btn-block" data-online-required>Kirim jawaban</button>
                                </div>
                            </form>
                        ` : app.renderDisabledButton('Upload belum tersedia')}
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
                    <div class="mycic-card">
                        <div class="mycic-card-header">
                            <h3 class="mycic-card-title">Jawaban terkirim</h3>
                        </div>
                        <div class="mycic-card-body">
                            <div class="flex flex-col gap-3">
                                ${fields.map((item) => `
                                    <div>
                                        <div class="mb-1 text-caption uppercase tracking-wide text-ink-subtle">${app.escapeHtml(item.label)}</div>
                                        <div class="break-all font-medium text-ink">${app.escapeHtml(item.value)}</div>
                                    </div>
                                `).join('')}
                            </div>
                            ${latest?.submission_id && !hasLiveDeleteId ? `
                                <div class="${app.alertTone('warning')} mt-3 mb-0">
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
                const isUploadReady = form.available === true;
                const relatedLinks = detail.related_links || [];
                const fileLinks = relatedLinks.filter(isDownloadableLink);
                const portalLinks = relatedLinks.filter((link) => !isDownloadableLink(link) && link?.type !== 'action');
                const primaryFile = fileLinks[0] || null;
                const hero = document.getElementById('exam-hero');

                const course = detail.course || {};
                const [statusLabel, statusTone] = statusBadge(detail.status);
                const portalTitle = String(detail.title || '');
                const isGenericTitle = /sipandai|portal/i.test(portalTitle);

                hero.innerHTML = `
                    <div class="mb-4">
                    ${app.renderPageHeader({
                        pretitle: course.code ? `Ujian · ${course.code}` : 'Detail ujian',
                        title: course.name || (isGenericTitle ? 'Detail ujian' : portalTitle) || 'Detail ujian',
                        description: [course.lecturer, course.schedule].filter(Boolean).join(' · ') || (isGenericTitle ? 'Soal, lampiran, dan pengumpulan jawaban ujian.' : (detail.description || 'Soal, lampiran, dan pengumpulan jawaban ujian.')),
                        badges: [
                            app.renderBadge(statusLabel, statusTone),
                            fileLinks.length ? app.renderBadge(`${fileLinks.length} berkas`, 'info') : '',
                        ],
                        actions: [
                            `<a href="/exam" class="${app.buttonClass('secondary')}">Kembali ke daftar</a>`,
                            primaryFile ? `<a href="${app.escapeHtml(app.buildCampusDownloadUrl(primaryFile.url, fileNameFromUrl(primaryFile.url)))}" class="${app.buttonClass('primary')}">Download soal</a>` : '',
                        ],
                    })}
                    </div>
                    ${app.renderStatCards([
                        { label: 'Status', value: statusLabel, note: 'Dibaca dari halaman ujian di portal.' },
                        { label: 'Soal', value: fileLinks.length || '0', note: fileLinks.length ? 'Soal atau lampiran tersedia.' : 'Belum ada soal yang diunggah dosen.' },
                        { label: 'Pengumpulan', value: isUploadReady ? 'Dibuka' : 'Tertutup', note: isUploadReady ? 'Kirim jawaban dari halaman ini.' : 'Form upload belum dibuka atau sudah ditutup portal.' },
                        { label: 'Jadwal', value: course.schedule || 'Belum ada', note: course.schedule ? 'Sesuai jadwal kelas.' : 'Jadwal ujian belum diumumkan.' },
                    ])}
                `;

                document.getElementById('exam-content').innerHTML = `
                    ${renderFileLinks(fileLinks)}
                    ${renderNoticeList(detail.alerts || [], detail.requirements || [])}
                `;

                document.getElementById('exam-actions').innerHTML = `
                    ${renderUploadPanel(detail, form, isUploadReady)}
                    ${renderSubmissionMemoryPanel(detail)}
                    ${renderPortalLinks(portalLinks)}
                `;
                app.refreshOnlineRequiredState();

                const uploadForm = document.getElementById('exam-upload-form');

                if (uploadForm) {
                    uploadForm.addEventListener('submit', async (event) => {
                        event.preventDefault();

                        if (!app.requireOnlineAction('Upload ujian butuh koneksi internet.')) {
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

                        document.querySelectorAll('[data-override-field]').forEach((input) => {
                            const value = input.value.trim();

                            if (value !== '') {
                                formData.append(`form_values[${input.dataset.overrideField}]`, value);
                            }
                        });

                        await app.guardFormSubmit(uploadForm, () => app.withSubmitLoading(uploadForm.querySelector('button[type="submit"]'), async () => {
                            try {
                                const result = await app.request('/api/exam', {
                                    method: 'POST',
                                    formData,
                                });

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
                    document.getElementById('exam-content').innerHTML = notFound ? `<a href="/exam" class="${app.buttonClass('primary')}">Ke daftar ujian</a>` : '';
                    document.getElementById('exam-actions').innerHTML = '';
                }
            };

            app.registerSyncHandler('exam-show', loadExamDetail);

            await loadExamDetail();
        });
    </script>
@endpush
