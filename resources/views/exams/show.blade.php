@extends('layouts.app')

@section('title', 'Detail Ujian')
@section('page_id', 'exam-show')

@section('content')
    <div id="exam-hero">
        {!! '<div class="alert alert-secondary mb-4">Menunggu ringkasan ujian.</div>' !!}
    </div>

    <div class="row row-cards">
        <div class="col-lg-7" id="exam-info">
            {!! '<div class="alert alert-secondary mb-0">Menunggu detail ujian.</div>' !!}
        </div>

        <div class="col-lg-5" id="exam-upload-shell">
            {!! '<div class="alert alert-secondary mb-0">Form unggah akan muncul jika tersedia.</div>' !!}
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', async () => {
            const app = window.portalApp;
            const examId = @json($id);

            const buildOverrideInputs = (fields) => {
                const entries = Object.entries(fields || {});

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

            const render = (payload) => {
                const detail = payload.data?.exam_detail || {};
                const form = detail.form || {};
                const isUploadReady = Boolean(form.available);
                const hero = document.getElementById('exam-hero');

                hero.innerHTML = `
                    ${app.renderPageHeader({
                        pretitle: 'Detail ujian',
                        title: detail.title || 'Detail ujian',
                        description: detail.description || 'Periksa status ujian, kebutuhan unggah, dan tautan terkait sebelum mengirim berkas.',
                        badges: [
                            detail.status ? app.renderBadge(detail.status, 'primary') : '',
                            detail.exam_id ? app.renderBadge(detail.exam_id, 'secondary') : '',
                            app.renderBadge(isUploadReady ? 'Form upload siap' : 'Menunggu form upload', isUploadReady ? 'success' : 'warning'),
                        ],
                        actions: [
                            '<a href="/exam" class="btn btn-outline-secondary">Kembali ke daftar</a>',
                            isUploadReady ? '<a href="#exam-upload-shell" class="btn btn-primary">Buka upload</a>' : '',
                        ],
                    })}
                    ${app.renderStatCards([
                        { label: 'Status', value: detail.status || '-', note: 'Status ujian yang dibaca dari portal.' },
                        { label: 'Exam ID', value: detail.exam_id || '-', note: 'Identifier ujian dari sistem.' },
                        { label: 'Form upload', value: isUploadReady ? 'Siap' : 'Belum siap', note: 'Ketersediaan form pengumpulan.' },
                        { label: 'Field file', value: form.file_field || '-', note: 'Nama field berkas pada form asli.' },
                    ])}
                `;

                document.getElementById('exam-info').innerHTML = `
                    <div class="card h-100">
                        <div class="card-header">
                            <h3 class="card-title">Informasi ujian</h3>
                        </div>
                        <div class="card-body">
                            ${app.renderKeyValueList([
                                { label: 'Judul', value: detail.title },
                                { label: 'Exam ID', value: detail.exam_id },
                                { label: 'Status', value: detail.status },
                                { label: 'Request URL', value: detail.request_url },
                                { label: 'Action URL', value: form.action_url },
                                { label: 'Field file', value: form.file_field },
                                { label: 'Deskripsi', value: detail.description },
                            ])}

                            ${((detail.alerts || []).length || (detail.requirements || []).length) ? `
                                <div class="row row-cards mt-4">
                                    ${(detail.alerts || []).length ? `
                                        <div class="col-12">
                                            <div class="card card-sm">
                                                <div class="card-header">
                                                    <h3 class="card-title">Alert</h3>
                                                </div>
                                                <div class="list-group list-group-flush">
                                                    ${(detail.alerts || []).map((alert) => `
                                                        <div class="list-group-item">${app.escapeHtml(alert)}</div>
                                                    `).join('')}
                                                </div>
                                            </div>
                                        </div>
                                    ` : ''}
                                    ${(detail.requirements || []).length ? `
                                        <div class="col-12">
                                            <div class="card card-sm">
                                                <div class="card-header">
                                                    <h3 class="card-title">Persyaratan</h3>
                                                </div>
                                                <div class="list-group list-group-flush">
                                                    ${(detail.requirements || []).map((requirement) => `
                                                        <div class="list-group-item">${app.escapeHtml(requirement)}</div>
                                                    `).join('')}
                                                </div>
                                            </div>
                                        </div>
                                    ` : ''}
                                </div>
                            ` : ''}
                        </div>
                    </div>
                `;

                document.getElementById('exam-upload-shell').innerHTML = `
                    <div class="card h-100">
                        <div class="card-header">
                            <h3 class="card-title">Upload berkas</h3>
                        </div>
                        <div class="card-body">
                            <div class="alert ${isUploadReady ? 'alert-success' : 'alert-secondary'}">
                                ${isUploadReady ? 'Form unggah tersedia.' : 'Form unggah belum tersedia.'}
                            </div>
                            ${isUploadReady ? `
                                <form id="exam-upload-form">
                                    <div class="mb-3">
                                        <label for="exam-file" class="form-label">Lampiran</label>
                                        <input id="exam-file" type="file" class="form-control" required>
                                    </div>
                                    ${buildOverrideInputs(form.fields)}
                                    <button type="submit" class="btn btn-primary" data-online-required>Kirim berkas</button>
                                </form>
                            ` : ''}

                            ${(detail.related_links || []).length ? `
                                <div class="list-group list-group-flush mt-4">
                                    ${(detail.related_links || []).map((link) => `
                                        <a href="${app.absoluteCampusUrl(link.url)}" target="_blank" rel="noreferrer" class="list-group-item list-group-item-action">
                                            ${app.escapeHtml(link.text || link.url)}
                                        </a>
                                    `).join('')}
                                </div>
                            ` : ''}
                        </div>
                    </div>
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
