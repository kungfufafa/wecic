@extends('layouts.app')

@section('title', 'Kelas')
@section('page_id', 'class-index')

@section('content')

    <div id="class-summary" class="row row-cards mb-4">
        @foreach ([
            ['label' => 'Total kelas', 'note' => 'Jumlah kelas pada sumber aktif.'],
            ['label' => 'Mahasiswa', 'note' => 'Identitas aktif.'],
            ['label' => 'Semester', 'note' => 'Semester terdeteksi.'],
            ['label' => 'Sumber', 'note' => 'Asal data aktif.'],
        ] as $summary)
            <div class="col-sm-6 col-xl-3">
                <div class="card card-sm h-100">
                    <div class="card-body">
                        <div class="text-secondary text-uppercase small mb-1">{{ $summary['label'] }}</div>
                        <div class="h1 mb-1" data-summary-value>-</div>
                        <div class="text-secondary small">{{ $summary['note'] }}</div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="list-group mb-4 gap-3" id="class-list">
        <div class="list-group-item py-4 text-center text-secondary rounded" style="border-top-width: 1px;">Memuat daftar kelas...</div>
    </div>
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

                summaryCards[0].textContent = classes.length || '0';
                summaryCards[1].textContent = identity.nim || identity.student_id || '-';
                summaryCards[2].textContent = semester;
                summaryCards[3].textContent = sourceConfig.label;

                const heroCopy = document.getElementById('class-hero-copy');

                if (heroCopy) {
                    heroCopy.textContent = classes.length
                        ? `${classes.length} kelas berhasil dimuat untuk ${semester === '-' ? 'semester aktif' : semester}. Gunakan daftar ini untuk berpindah cepat antara materi, presensi, dan meeting.`
                        : 'Belum ada kelas yang tampil pada sumber ini. Coba segarkan data saat portal kampus kembali merespons.';
                }
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
                    <div class="list-group-item px-3 py-3 rounded" style="border-top-width: 1px;">
                        <div class="row g-3 align-items-center">
                            <div class="col">
                                <div class="text-secondary small mb-1 d-flex align-items-center gap-2">
                                    <span class="badge bg-primary-lt">${app.escapeHtml(courseInfo.code || '-')}</span>
                                    <span>${app.escapeHtml(lecturerName)}</span>
                                </div>
                                <h4 class="h3 mb-2">
                                    ${classUrl ? `<a href="${classUrl}" class="text-reset">${app.escapeHtml(courseInfo.name || 'Tanpa judul')}</a>` : app.escapeHtml(courseInfo.name || 'Tanpa judul')}
                                </h4>
                                <div class="text-secondary small d-flex flex-wrap gap-3">
                                    <span class="d-inline-flex align-items-center gap-1">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-sm" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 7a2 2 0 0 1 2 -2h12a2 2 0 0 1 2 2v12a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2v-12z" /><path d="M16 3v4" /><path d="M8 3v4" /><path d="M4 11h16" /><path d="M11 15h1" /><path d="M12 15v3" /></svg>
                                        <span class="text-primary">${app.escapeHtml(schedule.day || '-')}</span> &middot; ${app.escapeHtml(scheduleTime)}
                                    </span>
                                    <span class="d-inline-flex align-items-center gap-1">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-sm"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M3 19l18 0" /><path d="M5 6m0 1a1 1 0 0 1 1 -1h12a1 1 0 0 1 1 1v12a1 1 0 0 1 -1 1h-12a1 1 0 0 1 -1 -1z" /></svg>
                                        ${app.escapeHtml(className)}
                                    </span>
                                    <span class="d-inline-flex align-items-center gap-1">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-sm"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M3 7l6 -3l6 3l6 -3l0 13l-6 3l-6 -3l-6 3l0 -13" /><path d="M9 4l0 13" /><path d="M15 7l0 13" /></svg>
                                        ${app.escapeHtml(roomName)}
                                    </span>
                                </div>
                            </div>
                            <div class="col-12 col-md-auto">
                                <div class="d-flex flex-row flex-md-column gap-2">
                                    ${classUrl ? `<a href="${classUrl}" class="btn btn-primary btn-sm">Buka kelas</a>` : app.renderDisabledButton('Tidak tersedia')}
                                    ${detailApiUrl ? `<button type="button" class="btn btn-outline-primary btn-sm" data-meeting-toggle data-detail-api="${detailApiUrl}">Lihat pertemuan</button>` : ''}
                                </div>
                            </div>
                        </div>
                        <div class="mt-3 d-none" data-meeting-panel>
                            ${app.renderEmptyState('Memuat daftar pertemuan.', 'secondary')}
                        </div>
                    </div>
                `;
            };

            const renderMeetingLinks = (panel, payload) => {
                const materials = payload.class_detail?.course_materials || [];

                if (!materials.length) {
                    panel.innerHTML = app.renderEmptyState('Belum ada pertemuan yang tersedia.', 'secondary');

                    return;
                }

                panel.innerHTML = `
                    <div class="pt-3 border-top">
                        <div class="text-secondary small mb-2 fw-medium">Daftar Pertemuan</div>
                        <div class="d-flex flex-wrap gap-2">
                            ${materials.map((material, index) => {
                                const meetingUrl = app.buildMeetingUrlFromHref(material.join_link?.href);
                                const labelNumber = material.meeting_number || index + 1;

                                if (!meetingUrl) {
                                    return `
                                        <span class="btn btn-sm btn-outline-secondary disabled cursor-help" title="Detail meeting belum tersedia">
                                            P${app.escapeHtml(labelNumber)}
                                        </span>
                                    `;
                                }

                                return `
                                    <a href="${meetingUrl}" class="btn btn-sm btn-primary-lt" title="Buka Pertemuan ${app.escapeHtml(labelNumber)}">
                                        P${app.escapeHtml(labelNumber)}
                                    </a>
                                `;
                            }).join('')}
                        </div>
                    </div>
                `;
            };

            const renderSource = (payload) => {
                renderSummary(payload);

                const classes = payload.classes || [];

                if (!classes.length) {
                    listHost.innerHTML = `
                        <div class="list-group-item py-4 rounded" style="border-top-width: 1px;">
                            ${app.renderEmptyState('Belum ada kelas pada sumber ini.', 'secondary')}
                        </div>
                    `;

                    return;
                }

                listHost.innerHTML = classes.map(renderCard).join('');
                bindMeetingPanels();
            };

            const bindMeetingPanels = () => {
                listHost.querySelectorAll('[data-meeting-toggle]').forEach((button) => {
                    button.addEventListener('click', async () => {
                        const article = button.closest('.list-group-item');
                        const panel = article?.querySelector('[data-meeting-panel]');

                        if (!panel) {
                            return;
                        }

                        const alreadyOpen = !panel.classList.contains('d-none');

                        if (alreadyOpen) {
                            panel.classList.add('d-none');
                            button.textContent = 'Lihat pertemuan';

                            return;
                        }

                        panel.classList.remove('d-none');
                        panel.innerHTML = app.renderEmptyState('Memuat daftar pertemuan.', 'secondary');
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
                            panel.innerHTML = app.renderEmptyState('Gagal memuat daftar pertemuan.', 'danger');
                            button.textContent = 'Lihat pertemuan';
                        }
                    });
                });
            };

            app.primeCachedView(sourceConfig.endpoint, renderSource, 'daftar kelas utama');

            const ready = await app.ensureAuthenticated();

            if (!ready) {
                return;
            }

            const loadSource = async ({ force = false } = {}) => {
                const usedCachedPayload = app.primeCachedView(sourceConfig.endpoint, renderSource, 'daftar kelas utama');

                if (!usedCachedPayload) {
                    listHost.innerHTML = '<div class="list-group-item py-4 text-center text-secondary rounded" style="border-top-width: 1px;">Memuat data kelas...</div>';
                }

                try {
                    const payload = await app.request(sourceConfig.endpoint, { fresh: force });
                    renderSource(payload);
                    app.markViewSynced('Daftar kelas utama', payload, { force });
                } catch (error) {
                    app.showNotice(error.payload?.message || error.message, 'error');
                    listHost.innerHTML = `
                        <div class="list-group-item py-4 rounded" style="border-top-width: 1px;">
                            ${app.renderEmptyState('Gagal memuat daftar kelas.', 'danger')}
                        </div>
                    `;
                }
            };

            app.registerSyncHandler('class-index', loadSource);

            await loadSource();
        });
    </script>
@endpush
