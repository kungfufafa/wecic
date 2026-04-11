@extends('layouts.app')

@section('title', 'Detail Ujian')
@section('page_id', 'exam-show')

@section('content')
    <section class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(24rem,0.9fr)]">
        <div class="grid gap-6">
            <div class="app-panel">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="section-label">Detail ujian</p>
                    <h1 id="exam-title" data-display class="mt-4 text-4xl font-semibold text-[color:var(--ink)]">Memuat ujian</h1>
                    <p id="exam-subtitle" class="mt-3 text-sm leading-7 text-[color:var(--ink-soft)]">Mengambil metadata form unggah ujian dari LMS.</p>
                </div>
                <div class="flex gap-2">
                    <a href="/exam" class="ghost-button">Kembali</a>
                </div>
            </div>
        </div>

            <div id="exam-info" class="app-panel">
                <div class="empty-shell">Menunggu detail ujian.</div>
            </div>

        </div>

        <aside class="grid gap-6">
            <div id="exam-upload-shell" class="app-panel">
                <div class="empty-shell">Form unggah akan muncul jika tersedia.</div>
            </div>
        </aside>
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

                document.getElementById('exam-title').textContent = detail.title || `Ujian ${examId}`;
                document.getElementById('exam-subtitle').textContent = `${detail.status || 'Status tidak tersedia'} / ${detail.description || 'Tanpa deskripsi tambahan'}`;

                document.getElementById('exam-info').innerHTML = `
                    <p class="section-label">Informasi utama</p>
                    <div class="mt-4 grid gap-4">
                        <div class="app-panel-muted">
                            ${app.renderKeyValueList([
                                { label: 'Exam ID', value: detail.exam_id },
                                { label: 'Status', value: detail.status },
                                { label: 'Request URL', value: detail.request_url },
                                { label: 'Action URL', value: form.action_url },
                                { label: 'Field file', value: form.file_field },
                            ])}
                        </div>
                        ${(detail.alerts || []).length ? `
                            <div class="app-panel-muted">
                                <p class="section-label">Alert</p>
                                <ul class="mt-4 grid gap-2 text-sm text-[color:var(--ink)]">
                                    ${(detail.alerts || []).map((alert) => `<li class="rounded-xl border border-[color:var(--line)] bg-white px-3 py-3">${app.escapeHtml(alert)}</li>`).join('')}
                                </ul>
                            </div>
                        ` : ''}
                        ${(detail.requirements || []).length ? `
                            <div class="app-panel-muted">
                                <p class="section-label">Persyaratan</p>
                                <ul class="mt-4 grid gap-2 text-sm text-[color:var(--ink)]">
                                    ${(detail.requirements || []).map((requirement) => `<li class="rounded-xl border border-[color:var(--line)] bg-white px-3 py-3">${app.escapeHtml(requirement)}</li>`).join('')}
                                </ul>
                            </div>
                        ` : ''}
                    </div>
                `;

                document.getElementById('exam-upload-shell').innerHTML = `
                    <p class="section-label">Upload berkas</p>
                    <div class="mt-4 grid gap-4">
                        <div class="app-panel-muted">
                            <p class="text-sm font-semibold text-[color:var(--ink)]">${isUploadReady ? 'Form unggah tersedia.' : 'Form unggah belum tersedia.'}</p>
                            <p class="mt-2 text-sm leading-7 text-[color:var(--ink-soft)]">Gunakan file PDF, ZIP, atau RAR sesuai kebutuhan ujian.</p>
                        </div>
                        ${isUploadReady ? `
                            <form id="exam-upload-form" class="grid gap-3">
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
                            <div class="grid gap-2">
                                ${(detail.related_links || []).map((link) => `
                                    <a href="${app.absoluteCampusUrl(link.url)}" target="_blank" rel="noreferrer" class="secondary-button justify-between">
                                        ${app.escapeHtml(link.text || link.url)}
                                        <span aria-hidden="true">/</span>
                                    </a>
                                `).join('')}
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
