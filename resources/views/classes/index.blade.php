@extends('layouts.app')

@section('title', 'Kelas')
@section('page_id', 'class-index')

@section('content')
    <section class="page-shell">
        <section class="page-hero">
            <div class="page-hero-shell">
                <div class="page-hero-main">
                    <p class="section-label">Ruang kelas</p>
                    <h1 data-display class="page-hero-title is-wide">Semua mata kuliah dalam satu alur kerja.</h1>
                    <p id="class-hero-copy" class="page-hero-copy">
                        Memuat daftar kelas aktif, identitas semester, dan akses cepat ke detail materi serta pertemuan.
                    </p>
                    <div class="page-meta-row">
                        <span class="meta-pill is-accent">Daftar utama</span>
                        <span id="class-source-pill" class="meta-pill">Utama</span>
                        <span id="class-semester-pill" class="meta-pill">Semester</span>
                    </div>
                </div>
                <div class="page-hero-actions">
                    <a href="/dashboard" class="ghost-button">Kembali ke dashboard</a>
                    <a href="/exam" class="primary-button">Lanjut ke ujian</a>
                </div>
            </div>
        </section>

        <div id="class-summary" class="dense-metric-grid">
            <article class="dense-metric">
                <span class="dense-metric-label">Total kelas</span>
                <strong class="dense-metric-value" data-summary-value data-summary-mode="metric">-</strong>
                <p class="dense-metric-copy">Jumlah kelas pada sumber aktif.</p>
            </article>
            <article class="dense-metric">
                <span class="dense-metric-label">Mahasiswa</span>
                <strong class="dense-metric-value is-compact" data-summary-value data-summary-mode="compact">-</strong>
                <p class="dense-metric-copy">Identitas mahasiswa yang sedang aktif.</p>
            </article>
            <article class="dense-metric">
                <span class="dense-metric-label">Semester</span>
                <strong class="dense-metric-value is-compact" data-summary-value data-summary-mode="compact">-</strong>
                <p class="dense-metric-copy">Semester yang berhasil terdeteksi.</p>
            </article>
            <article class="dense-metric">
                <span class="dense-metric-label">Sumber</span>
                <strong class="dense-metric-value is-compact" data-summary-value data-summary-mode="compact">-</strong>
                <p class="dense-metric-copy">Asal data yang sedang ditampilkan.</p>
            </article>
        </div>

        <section class="section-shell">
            <div class="section-head">
                <div class="section-head-main">
                    <h2 data-display class="section-title">Daftar kelas</h2>
                    <p class="section-copy">Masuk ke detail kelas untuk melihat materi, histori tugas, dan daftar pertemuan yang tersedia.</p>
                </div>
            </div>
            <div id="class-list" class="mt-6 stack-list">
                <div class="empty-shell">Memuat daftar kelas.</div>
            </div>
        </section>
    </section>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', async () => {
            const app = window.portalApp;
            const sourceConfig = { label: 'Utama', endpoint: '/api/class' };
            const summaryCards = document.querySelectorAll('[data-summary-value]');
            const listHost = document.getElementById('class-list');

            const renderSummary = (payload) => {
                const classes = payload.classes || [];
                const identity = payload.user_info || payload.student_info || {};
                const semester = payload.semester_info?.semester || payload.semester_info?.label || payload.academic_summary?.semester || '-';
                const heroCopy = document.getElementById('class-hero-copy');
                const sourcePill = document.getElementById('class-source-pill');
                const semesterPill = document.getElementById('class-semester-pill');

                summaryCards[0].textContent = classes.length || '0';
                summaryCards[1].textContent = identity.nim || identity.student_id || '-';
                summaryCards[2].textContent = semester;
                summaryCards[3].textContent = sourceConfig.label;

                sourcePill.textContent = `Sumber ${sourceConfig.label}`;
                semesterPill.textContent = semester === '-' ? 'Semester belum ada' : semester;
                heroCopy.textContent = classes.length
                    ? `${classes.length} kelas berhasil dimuat untuk ${semester === '-' ? 'semester aktif' : semester}. Gunakan daftar ini untuk berpindah cepat antara materi, presensi, dan meeting.`
                    : 'Belum ada kelas yang tampil pada sumber ini. Coba segarkan data saat portal kampus kembali merespons.';
            };

            const renderCard = (klass) => {
                const params = klass.join_link?.params || {};
                const courseInfo = klass.course_info || {};
                const lecturerInfo = klass.lecturer_info || {};
                const schedule = klass.schedule || {};
                const classRoomInfo = klass.class_room_info || {};
                const scheduleTime = schedule.formatted_time || schedule.time_range || schedule.raw || '-';
                const className = classRoomInfo.class_name || courseInfo.class_name || '-';
                const roomName = classRoomInfo.room || '-';
                const lecturerName = lecturerInfo.name || '-';

                const classUrl = app.buildClassUrl(params.id, params.dosen_id, params.course_id);
                const detailApiUrl = params.id && params.dosen_id && params.course_id
                    ? `/api/class/${params.id}?dosen_id=${params.dosen_id}&course_id=${params.course_id}`
                    : null;

                return `
                    <article class="stack-item">
                        <div class="stack-item-shell">
                            <div class="stack-item-main">
                                <div class="space-y-3">
                                    <div class="page-meta-row">
                                        <span class="stack-item-kicker">${app.escapeHtml(courseInfo.code || '-')}</span>
                                        <span class="meta-pill">${app.escapeHtml(schedule.day || 'Hari belum ada')}</span>
                                        <span class="meta-pill">${app.escapeHtml(scheduleTime)}</span>
                                    </div>
                                    <div>
                                        <h2 data-display class="stack-item-title">${app.escapeHtml(courseInfo.name || 'Tanpa judul')}</h2>
                                        <p class="stack-item-copy">${app.escapeHtml(lecturerName)}</p>
                                    </div>
                                </div>
                                <div>
                                    ${app.renderKeyValueList([
                                        { label: 'Kelas', value: className },
                                        { label: 'Ruang', value: roomName },
                                        { label: 'Waktu', value: scheduleTime },
                                    ])}
                                </div>
                            </div>
                            <div class="stack-item-aside">
                                <div class="surface-card-soft">
                                    <p class="section-label">Aksi kelas</p>
                                    <div class="surface-card-actions">
                                        ${classUrl ? `<a href="${classUrl}" class="primary-button w-full justify-center">Detail kelas</a>` : '<span class="secondary-button w-full justify-center opacity-50">Detail tidak tersedia</span>'}
                                        ${detailApiUrl ? `<button type="button" class="secondary-button w-full justify-center" data-meeting-toggle data-detail-api="${detailApiUrl}">Lihat pertemuan</button>` : ''}
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="class-record-meetings hidden" data-meeting-panel>
                            <div class="empty-shell">Memuat daftar pertemuan.</div>
                        </div>
                    </article>
                `;
            };

            const renderMeetingLinks = (panel, payload) => {
                const materials = payload.class_detail?.course_materials || [];

                if (!materials.length) {
                    panel.innerHTML = '<div class="empty-shell">Belum ada pertemuan yang tersedia.</div>';

                    return;
                }

                panel.innerHTML = `
                    <div class="surface-stack">
                        ${materials.map((material, index) => {
                            const meetingUrl = app.buildMeetingUrlFromHref(material.join_link?.href);
                            const labelNumber = material.meeting_number || index + 1;

                            if (!meetingUrl) {
                                return `
                                    <div class="surface-card-soft">
                                        <p class="stack-item-kicker">Pertemuan ${app.escapeHtml(labelNumber)}</p>
                                        <p class="surface-card-copy">Detail meeting belum tersedia.</p>
                                    </div>
                                `;
                            }

                            return `
                                <a href="${meetingUrl}" class="secondary-button justify-between">
                                    <span>Pertemuan ${app.escapeHtml(labelNumber)}</span>
                                    <span aria-hidden="true">/</span>
                                </a>
                            `;
                        }).join('')}
                    </div>
                `;
            };

            const renderSource = (payload) => {
                renderSummary(payload);

                const classes = payload.classes || [];

                if (!classes.length) {
                    listHost.innerHTML = '<div class="empty-shell">Belum ada kelas pada sumber ini.</div>';

                    return;
                }

                listHost.innerHTML = classes.map(renderCard).join('');
                bindMeetingPanels();
            };

            const bindMeetingPanels = () => {
                listHost.querySelectorAll('[data-meeting-toggle]').forEach((button) => {
                    button.addEventListener('click', async () => {
                        const article = button.closest('article');
                        const panel = article.querySelector('[data-meeting-panel]');

                        if (!panel) {
                            return;
                        }

                        const alreadyOpen = !panel.classList.contains('hidden');

                        if (alreadyOpen) {
                            panel.classList.add('hidden');
                            button.textContent = 'Lihat pertemuan';

                            return;
                        }

                        panel.classList.remove('hidden');
                        panel.innerHTML = '<div class="empty-shell">Memuat daftar pertemuan.</div>';
                        button.textContent = 'Tutup pertemuan';

                        const cachedDetail = app.getCachedResponse(button.dataset.detailApi);

                        if (cachedDetail) {
                            renderMeetingLinks(panel, cachedDetail.payload);
                        }

                        try {
                            const payload = await app.request(button.dataset.detailApi);
                            renderMeetingLinks(panel, payload);
                        } catch (error) {
                            app.showNotice(error.payload?.message || error.message, 'error');
                            panel.innerHTML = '<div class="empty-shell">Gagal memuat daftar pertemuan.</div>';
                            button.textContent = 'Lihat pertemuan';
                        }
                    });
                });
            };

            app.primeCachedView(sourceConfig.endpoint, (payload) => {
                renderSource(payload);
            }, 'daftar kelas utama');

            const ready = await app.ensureAuthenticated();

            if (!ready) {
                return;
            }

            const loadSource = async () => {
                const usedCachedPayload = app.primeCachedView(sourceConfig.endpoint, (payload) => {
                    renderSource(payload);
                }, 'daftar kelas utama');

                if (!usedCachedPayload) {
                    listHost.innerHTML = '<div class="empty-shell">Memuat data kelas.</div>';
                }

                try {
                    const payload = await app.request(sourceConfig.endpoint);
                    renderSource(payload);
                    app.markViewSynced('Daftar kelas utama');
                } catch (error) {
                    app.showNotice(error.payload?.message || error.message, 'error');
                    listHost.innerHTML = '<div class="empty-shell">Gagal memuat daftar kelas.</div>';
                }
            };

            app.registerSyncHandler('class-index', () => loadSource());

            await loadSource();
        });
    </script>
@endpush
