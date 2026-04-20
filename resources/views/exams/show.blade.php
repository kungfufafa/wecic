@extends('layouts.app')

@section('title', 'Detail Ujian')
@section('page_id', 'exam-show')

@section('content')
    <section class="page-shell">
        <section id="exam-hero" class="page-hero">
            <div class="empty-shell">Menunggu ringkasan ujian.</div>
        </section>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(24rem,0.9fr)]">
            <section id="exam-info" class="section-shell">
                <div class="empty-shell">Menunggu detail ujian.</div>
            </section>

            <aside id="exam-upload-shell" class="section-shell">
                <div class="empty-shell">Form unggah akan muncul jika tersedia.</div>
            </aside>
        </div>
    </section>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', async () => {
            const app = window.portalApp;
            const examId = @json($id);

            const buildOverrideInputs = (fields) => {
                const entries = Object.entries(fields || {});

                if (!entries.length) {
                    return '<div class="empty-shell">Tidak ada field tambahan dari form asli.</div>';
                }

                return entries.map(([name, value]) => `
                    <label class="grid gap-2">
                        <span class="section-label">${app.escapeHtml(name)}</span>
                        <input class="field-shell" data-override-field="${app.escapeHtml(name)}" value="${app.escapeHtml(value)}">
                    </label>
                `).join('');
            };

            const render = (payload) => {
                const detail = payload.data?.exam_detail || {};
                const form = detail.form || {};
                const isUploadReady = Boolean(form.available);
                const hero = document.getElementById('exam-hero');

                hero.innerHTML = `
                    <div class="page-hero-shell">
                        <div class="page-hero-main">
                            <p class="section-label">Detail ujian</p>
                            <h1 data-display class="page-hero-title is-wide">${app.escapeHtml(detail.title || 'Detail ujian')}</h1>
                            <p class="page-hero-copy">${app.escapeHtml(detail.description || 'Periksa status ujian, kebutuhan unggah, dan tautan terkait sebelum mengirim berkas.')}</p>
                            <div class="page-meta-row">
                                ${detail.status ? `<span class="meta-pill is-accent">${app.escapeHtml(detail.status)}</span>` : ''}
                                ${detail.exam_id ? `<span class="meta-pill">${app.escapeHtml(detail.exam_id)}</span>` : ''}
                                <span class="meta-pill">${isUploadReady ? 'Form upload siap' : 'Menunggu form upload'}</span>
                            </div>
                        </div>
                        <div class="page-hero-actions">
                            <a href="/exam" class="ghost-button">Kembali ke daftar</a>
                            ${isUploadReady ? '<a href="#exam-upload-shell" class="primary-button">Buka upload</a>' : ''}
                        </div>
                    </div>
                `;

                document.getElementById('exam-info').innerHTML = `
                    <div class="section-head">
                        <div class="section-head-main">
                            <h2 data-display class="section-title">Informasi ujian</h2>
                            <p class="section-copy">Rangkuman metadata ujian, endpoint form asli, dan catatan penting yang dibaca dari portal kampus.</p>
                            <div class="page-meta-row">
                                ${detail.status ? `<span class="meta-pill is-accent">${app.escapeHtml(detail.status)}</span>` : ''}
                                ${detail.exam_id ? `<span class="meta-pill">${app.escapeHtml(detail.exam_id)}</span>` : ''}
                            </div>
                        </div>
                        <div class="page-action-row">
                            <a href="/exam" class="ghost-button">Kembali ke daftar</a>
                        </div>
                    </div>
                    <div class="property-grid mt-6">
                        <div class="property-panel">
                            ${app.renderKeyValueList([
                                { label: 'Judul', value: detail.title },
                                { label: 'Exam ID', value: detail.exam_id },
                                { label: 'Status', value: detail.status },
                                { label: 'Request URL', value: detail.request_url },
                            ])}
                        </div>
                        <div class="property-panel">
                            ${app.renderKeyValueList([
                                { label: 'Action URL', value: form.action_url },
                                { label: 'Field file', value: form.file_field },
                                { label: 'Deskripsi', value: detail.description },
                            ])}
                        </div>
                    </div>
                    ${((detail.alerts || []).length || (detail.requirements || []).length) ? `
                        <div class="grid gap-4 mt-6">
                            ${(detail.alerts || []).length ? `
                                <div class="surface-card">
                                    <p class="section-label">Alert</p>
                                    <div class="surface-stack mt-4">
                                        ${(detail.alerts || []).map((alert) => `
                                            <div class="surface-card-soft">
                                                <p class="surface-card-copy">${app.escapeHtml(alert)}</p>
                                            </div>
                                        `).join('')}
                                    </div>
                                </div>
                            ` : ''}
                            ${(detail.requirements || []).length ? `
                                <div class="surface-card">
                                    <p class="section-label">Persyaratan</p>
                                    <div class="surface-stack mt-4">
                                        ${(detail.requirements || []).map((requirement) => `
                                            <div class="surface-card-soft">
                                                <p class="surface-card-copy">${app.escapeHtml(requirement)}</p>
                                            </div>
                                        `).join('')}
                                    </div>
                                </div>
                            ` : ''}
                        </div>
                    ` : ''}
                `;

                document.getElementById('exam-upload-shell').innerHTML = `
                    <div class="section-head">
                        <div class="section-head-main">
                            <h2 data-display class="section-title">Upload berkas</h2>
                            <p class="section-copy">Gunakan panel ini hanya ketika form dari portal kampus menyatakan unggah tersedia.</p>
                        </div>
                    </div>
                    <div class="surface-card mt-6">
                        <p class="section-label">Kesiapan form</p>
                        <p class="surface-card-title mt-3">${isUploadReady ? 'Form unggah tersedia.' : 'Form unggah belum tersedia.'}</p>
                        <p class="surface-card-copy">Gunakan file PDF, ZIP, atau RAR sesuai kebutuhan ujian.</p>
                    </div>
                    <div class="mt-4 grid gap-4">
                        ${isUploadReady ? `
                            <form id="exam-upload-form" class="surface-card grid gap-3">
                                <label class="grid gap-2">
                                    <span class="section-label">Lampiran</span>
                                    <input id="exam-file" type="file" class="field-shell" required>
                                </label>
                                <div class="grid gap-3">
                                    ${buildOverrideInputs(form.fields)}
                                </div>
                                <button type="submit" class="primary-button" data-online-required>Kirim berkas</button>
                            </form>
                        ` : ''}
                        ${(detail.related_links || []).length ? `
                            <div class="surface-card">
                                <p class="section-label">Tautan terkait</p>
                                <div class="surface-stack mt-4">
                                    ${(detail.related_links || []).map((link) => `
                                        <a href="${app.absoluteCampusUrl(link.url)}" target="_blank" rel="noreferrer" class="secondary-button justify-between">
                                            ${app.escapeHtml(link.text || link.url)}
                                            <span aria-hidden="true">/</span>
                                        </a>
                                    `).join('')}
                                </div>
                            </div>
                        ` : ''}
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

            const loadExamDetail = async () => {
                try {
                    const payload = await app.request(`/api/exam/${examId}`);
                    render(payload);
                    app.markViewSynced('Detail ujian');
                } catch (error) {
                    app.showNotice(error.payload?.message || error.message, 'error');
                }
            };

            app.registerSyncHandler('exam-show', loadExamDetail);

            await loadExamDetail();
        });
    </script>
@endpush
