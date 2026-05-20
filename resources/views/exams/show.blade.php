@extends('layouts.app')

@section('title', 'Detail Ujian')
@section('page_id', 'exam-show')

@section('content')
    <div id="exam-hero">
        {!! '<div class="alert alert-secondary mb-4">Menunggu ringkasan ujian.</div>' !!}
    </div>

    <div class="row row-cards align-items-start">
        <div class="col-lg-8" id="exam-content">
            {!! '<div class="alert alert-secondary mb-0">Menunggu konten ujian.</div>' !!}
        </div>

        <div class="col-lg-4">
            <div class="d-flex flex-column gap-3" id="exam-actions">
                {!! '<div class="alert alert-secondary mb-0">Form unggah akan muncul jika tersedia.</div>' !!}
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', async () => {
            const app = window.portalApp;
            const examId = @json($id);

            const buildOverrideInputs = (fields) => {
                const entries = Object.entries(fields || {})
                    .filter(([name]) => !['id', 'cmd'].includes(String(name).toLowerCase()));

                if (!entries.length) {
                    return app.renderEmptyState('Tidak ada field tambahan dari form asli.', 'secondary');
                }

                return entries.map(([name, value]) => `
                    <div class="mb-3">
                        <label class="form-label">${app.escapeHtml(name)}</label>
                        <input class="form-control" data-override-field="${app.escapeHtml(name)}" value="${app.escapeHtml(value)}">
                    </div>
                `).join('');
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
                    return `
                        <div class="list-group-item py-4 rounded border" style="border-top-width: 1px;">
                            ${app.renderEmptyState('Belum ada soal atau lampiran ujian.', 'secondary')}
                        </div>
                    `;
                }

                return `
                    <div class="d-flex flex-column gap-3 mb-4">
                        ${links.map((link, index) => {
                            const absoluteUrl = app.absoluteCampusUrl(link.url);
                            const filename = fileNameFromUrl(link.url);
                            const title = linkLabel(link, filename);
                            const downloadUrl = app.buildCampusDownloadUrl(link.url, filename);
                            const badge = /soal/i.test(`${link.url || ''} ${link.text || ''}`) ? 'Soal ujian' : `Lampiran ${index + 1}`;

                            return `
                                <div class="list-group-item px-4 py-3 rounded border" style="border-top-width: 1px;">
                                    <div class="row align-items-center">
                                        <div class="col">
                                            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                                <span class="badge bg-primary-lt">${app.escapeHtml(badge)}</span>
                                                <span class="text-secondary small">${app.escapeHtml(filename)}</span>
                                            </div>
                                            <h3 class="h3 mb-1">${app.escapeHtml(title)}</h3>
                                            <div class="text-secondary small text-break">${app.escapeHtml(link.url || '')}</div>
                                        </div>
                                        <div class="col-12 col-md-auto mt-3 mt-md-0">
                                            <div class="d-flex flex-wrap gap-2 justify-content-md-end">
                                                ${absoluteUrl ? `<a href="${app.escapeHtml(absoluteUrl)}" target="_blank" rel="noreferrer" class="btn btn-primary">Buka</a>` : ''}
                                                ${downloadUrl ? `<a href="${app.escapeHtml(downloadUrl)}" class="btn btn-outline-primary">Download</a>` : ''}
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
                    <div class="card mb-3">
                        <div class="card-header">
                            <h3 class="card-title">Catatan portal</h3>
                        </div>
                        <div class="list-group list-group-flush">
                            ${items.map((item) => `
                                <div class="list-group-item">
                                    <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                                        ${app.renderBadge(item.label, item.tone)}
                                    </div>
                                    <div class="text-body">${app.escapeHtml(item.text)}</div>
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
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">Link portal</h3>
                        </div>
                        <div class="list-group list-group-flush">
                            ${links.map((link) => {
                                const absoluteUrl = app.absoluteCampusUrl(link.url);

                                return `
                                    <a href="${app.escapeHtml(absoluteUrl || link.url)}" target="_blank" rel="noreferrer" class="list-group-item list-group-item-action">
                                        <div class="fw-semibold">${app.escapeHtml(linkLabel(link, 'Buka portal'))}</div>
                                        <div class="text-secondary small text-break">${app.escapeHtml(link.url || '')}</div>
                                    </a>
                                `;
                            }).join('')}
                        </div>
                    </div>
                `;
            };

            const renderUploadPanel = (detail, form, isUploadReady) => {
                const usesDirectSubmit = Boolean(form.available_via_direct_submit);
                const postUrl = form.post_url || detail.submit_url || form.action_url || '';

                return `
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Upload jawaban</h3>
                    </div>
                    <div class="card-body">
                        <div class="alert ${isUploadReady ? (usesDirectSubmit ? 'alert-warning' : 'alert-success') : 'alert-secondary'} mb-3">
                            <div class="fw-semibold">${isUploadReady ? (usesDirectSubmit ? 'Upload langsung siap.' : 'Form upload siap.') : 'Upload belum tersedia.'}</div>
                            <div class="small ${isUploadReady ? (usesDirectSubmit ? 'text-warning' : 'text-success') : 'text-secondary'}">Status: ${app.escapeHtml(detail.status || '-')}</div>
                            ${postUrl ? `<div class="small text-secondary text-break mt-1">POST: ${app.escapeHtml(postUrl)}</div>` : ''}
                        </div>
                        ${isUploadReady ? `
                            <form id="exam-upload-form">
                                <div class="mb-3">
                                    <label for="exam-file" class="form-label">Lampiran jawaban</label>
                                    <input id="exam-file" type="file" class="form-control" required>
                                </div>
                                ${buildOverrideInputs(form.fields)}
                                <button type="submit" class="btn btn-primary w-100" data-online-required>Kirim berkas</button>
                            </form>
                        ` : app.renderDisabledButton('Upload belum tersedia')}
                    </div>
                </div>
                `;
            };

            const renderMetaPanel = (detail, form) => `
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Metadata</h3>
                    </div>
                    <div class="card-body">
                        <div class="d-flex flex-column gap-3">
                            ${[
                                { label: 'Exam ID', value: detail.exam_id },
                                { label: 'Field file', value: form.file_field },
                                { label: 'GET URL', value: form.get_url || detail.metadata_url || detail.request_url },
                                { label: 'POST URL', value: form.post_url || detail.submit_url || form.action_url },
                            ].filter((item) => item.value).map((item) => `
                                <div>
                                    <div class="text-secondary text-uppercase small mb-1">${app.escapeHtml(item.label)}</div>
                                    <div class="fw-semibold text-break">${app.escapeHtml(item.value)}</div>
                                </div>
                            `).join('') || app.renderEmptyState('Metadata belum tersedia.', 'secondary')}
                        </div>
                    </div>
                </div>
            `;

            const render = (payload) => {
                const detail = payload.data?.exam_detail || {};
                const form = detail.form || {};
                const isUploadReady = Boolean(form.available || form.post_url || detail.submit_url || form.action_url || detail.exam_id);
                const relatedLinks = detail.related_links || [];
                const fileLinks = relatedLinks.filter(isDownloadableLink);
                const portalLinks = relatedLinks.filter((link) => !isDownloadableLink(link) && link?.type !== 'action');
                const primaryFile = fileLinks[0] || null;
                const hero = document.getElementById('exam-hero');

                hero.innerHTML = `
                    <div class="mb-4">
                    ${app.renderPageHeader({
                        pretitle: 'Detail ujian',
                        title: detail.title || 'Detail ujian',
                        description: detail.description || 'Soal, lampiran, dan upload jawaban ujian dalam satu halaman.',
                        badges: [
                            detail.status ? app.renderBadge(detail.status, 'primary') : '',
                            detail.exam_id ? app.renderBadge(detail.exam_id, 'secondary') : '',
                            fileLinks.length ? app.renderBadge(`${fileLinks.length} berkas`, 'info') : '',
                        ],
                        actions: [
                            '<a href="/exam" class="btn btn-outline-secondary">Kembali ke daftar</a>',
                            primaryFile ? `<a href="${app.escapeHtml(app.buildCampusDownloadUrl(primaryFile.url, fileNameFromUrl(primaryFile.url)))}" class="btn btn-primary">Download soal</a>` : '',
                        ],
                    })}
                    </div>
                    ${app.renderStatCards([
                        { label: 'Status', value: detail.status || '-', note: 'Status ujian yang dibaca dari portal.' },
                        { label: 'Berkas', value: fileLinks.length || '0', note: 'Soal atau lampiran yang terdeteksi.' },
                        { label: 'Upload', value: isUploadReady ? 'Siap' : 'Belum siap', note: 'Ketersediaan form pengumpulan.' },
                        { label: 'Field file', value: form.file_field || '-', note: 'Nama field berkas pada form asli.' },
                    ])}
                `;

                document.getElementById('exam-content').innerHTML = `
                    ${renderFileLinks(fileLinks)}
                    ${renderNoticeList(detail.alerts || [], detail.requirements || [])}
                `;

                document.getElementById('exam-actions').innerHTML = `
                    ${renderUploadPanel(detail, form, isUploadReady)}
                    ${renderPortalLinks(portalLinks)}
                    ${renderMetaPanel(detail, form)}
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

                        const formData = new FormData();
                        formData.append('id', examId);
                        formData.append('attachment', file);

                        document.querySelectorAll('[data-override-field]').forEach((input) => {
                            const value = input.value.trim();

                            if (value !== '') {
                                formData.append(`form_values[${input.dataset.overrideField}]`, value);
                            }
                        });

                        try {
                            const result = await app.request('/api/exam', {
                                method: 'POST',
                                formData,
                            });

                            app.showNotice(result?.message || 'Berkas ujian berhasil dikirim.', 'success');
                        } catch (error) {
                            app.showNotice(error.payload?.message || error.message, 'error');
                        }
                    });
                }
            };

            app.primeCachedView(`/api/exam/${examId}`, render, 'detail ujian');

            const ready = await app.ensureAuthenticated();

            if (!ready) {
                return;
            }

            const loadExamDetail = async ({ force = false } = {}) => {
                try {
                    const payload = await app.request(`/api/exam/${examId}`, { fresh: force });
                    render(payload);
                    app.markViewSynced('Detail ujian', payload, { force });
                } catch (error) {
                    app.showNotice(error.payload?.message || error.message, 'error');
                }
            };

            app.registerSyncHandler('exam-show', loadExamDetail);

            await loadExamDetail();
        });
    </script>
@endpush
