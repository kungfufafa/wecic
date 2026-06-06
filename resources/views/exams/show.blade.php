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

            const buildOverrideInputs = (fields) => {
                const entries = Object.entries(fields || {})
                    .filter(([name]) => !['id', 'cmd'].includes(String(name).toLowerCase()));

                if (!entries.length) {
                    return app.renderEmptyState('Tidak ada field tambahan dari form asli.', 'secondary');
                }

                return entries.map(([name, value]) => `
                    <div class="mb-3">
                        <label class="mycic-label">${app.escapeHtml(name)}</label>
                        <input class="mycic-input" data-override-field="${app.escapeHtml(name)}" value="${app.escapeHtml(value)}">
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
                                            <h3 class="mb-1 text-subtitle font-[590] text-ink">${app.escapeHtml(title)}</h3>
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
                const usesDirectSubmit = Boolean(form.available_via_direct_submit);
                const postUrl = form.post_url || detail.submit_url || form.action_url || '';
                const uploadTone = isUploadReady ? (usesDirectSubmit ? 'warning' : 'success') : 'secondary';

                return `
                <div class="mycic-card">
                    <div class="mycic-card-header">
                        <h3 class="mycic-card-title">Upload jawaban</h3>
                    </div>
                    <div class="mycic-card-body">
                        <div class="${app.alertTone(uploadTone)} mb-3">
                            <div class="font-medium">${isUploadReady ? (usesDirectSubmit ? 'Upload langsung siap.' : 'Form upload siap.') : 'Upload belum tersedia.'}</div>
                            <div class="text-caption ${app.textToneClass(uploadTone)}">Status: ${app.escapeHtml(detail.status || '-')}</div>
                            ${postUrl ? `<div class="mt-1 break-all text-caption text-ink-subtle">POST: ${app.escapeHtml(postUrl)}</div>` : ''}
                        </div>
                        ${isUploadReady ? `
                            <form id="exam-upload-form">
                                <div class="mb-3">
                                    <label for="exam-file" class="mycic-label">Lampiran jawaban</label>
                                    <input id="exam-file" type="file" class="mycic-input" required>
                                </div>
                                ${buildOverrideInputs(form.fields)}
                                <div class="mycic-sticky-action">
                                    <button type="submit" class="${app.buttonClass('primary')} mycic-btn-block" data-online-required>Kirim jawaban</button>
                                </div>
                            </form>
                        ` : app.renderDisabledButton('Upload belum tersedia')}
                    </div>
                </div>
                `;
            };

            const renderMetaPanel = (detail, form) => `
                <div class="mycic-card">
                    <div class="mycic-card-header">
                        <h3 class="mycic-card-title">Metadata</h3>
                    </div>
                    <div class="mycic-card-body">
                        <div class="flex flex-col gap-3">
                            ${[
                                { label: 'Exam ID', value: detail.exam_id },
                                { label: 'Field file', value: form.file_field },
                                { label: 'GET URL', value: form.get_url || detail.metadata_url || detail.request_url },
                                { label: 'POST URL', value: form.post_url || detail.submit_url || form.action_url },
                            ].filter((item) => item.value).map((item) => `
                                <div>
                                    <div class="mb-1 text-caption uppercase tracking-wide text-ink-subtle">${app.escapeHtml(item.label)}</div>
                                    <div class="break-all font-medium text-ink">${app.escapeHtml(item.value)}</div>
                                </div>
                            `).join('') || app.renderEmptyState('Metadata belum tersedia.', 'secondary')}
                        </div>
                    </div>
                </div>
            `;

            const renderSubmissionMemoryPanel = (detail) => {
                const latest = detail.latest_submission || null;
                const storedSubmissions = detail.stored_submissions || [];
                const currentSubmissions = detail.submissions || [];
                const hasLiveDeleteId = currentSubmissions.some((record) => record?.submission_id);

                if (!latest && !storedSubmissions.length && !currentSubmissions.length) {
                    return '';
                }

                const fields = [
                    { label: 'ID hapus', value: latest?.submission_id || 'Belum tertangkap' },
                    { label: 'File', value: latest?.file_name || (latest?.file_url ? fileNameFromUrl(latest.file_url) : null) },
                    { label: 'Tanggal upload', value: latest?.uploaded_at_label },
                    { label: 'Sumber', value: latest?.is_stored ? 'Tersimpan lokal' : 'Halaman portal' },
                    { label: 'Terakhir terlihat', value: latest?.last_seen_at },
                    { label: 'URL hapus', value: latest?.delete_url },
                ].filter((item) => item.value);

                return `
                    <div class="mycic-card">
                        <div class="mycic-card-header">
                            <h3 class="mycic-card-title">Rekaman jawaban</h3>
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
                                    ID hapus tersimpan, tetapi tombol hapus tidak tampil di halaman portal saat ini.
                                </div>
                            ` : ''}
                        </div>
                    </div>
                `;
            };

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
                            detail.status ? app.renderBadge(detail.status, 'brand') : '',
                            detail.exam_id ? app.renderBadge(detail.exam_id, 'neutral') : '',
                            fileLinks.length ? app.renderBadge(`${fileLinks.length} berkas`, 'info') : '',
                        ],
                        actions: [
                            `<a href="/exam" class="${app.buttonClass('secondary')}">Kembali ke daftar</a>`,
                            primaryFile ? `<a href="${app.escapeHtml(app.buildCampusDownloadUrl(primaryFile.url, fileNameFromUrl(primaryFile.url)))}" class="${app.buttonClass('primary')}">Download soal</a>` : '',
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
                    ${renderSubmissionMemoryPanel(detail)}
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

                        await app.withSubmitLoading(uploadForm.querySelector('button[type="submit"]'), async () => {
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
