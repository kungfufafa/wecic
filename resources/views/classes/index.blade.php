@extends('layouts.app')

@section('title', 'Kelas')
@section('page_id', 'class-index')

@section('content')

    <div id="class-summary" class="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ([
            ['label' => 'Total kelas', 'note' => 'Jumlah kelas pada sumber aktif.'],
            ['label' => 'Mahasiswa', 'note' => 'Identitas aktif.'],
            ['label' => 'Semester', 'note' => 'Semester terdeteksi.'],
            ['label' => 'Sumber', 'note' => 'Asal data aktif.'],
        ] as $summary)
            <div class="mycic-metric">
                <div class="mycic-metric-label">{{ $summary['label'] }}</div>
                <div class="mycic-metric-value" data-summary-value>-</div>
                <div class="mycic-metric-note">{{ $summary['note'] }}</div>
            </div>
        @endforeach
    </div>

    <div class="mb-4 flex flex-col gap-3" id="class-list">
        <div class="mycic-empty py-8">
            <p class="mycic-empty-title text-ink-subtle">Memuat daftar kelas...</p>
        </div>
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
                    <div class="mycic-card-interactive" data-class-card>
                        <div class="flex flex-col gap-3 md:flex-row md:items-center">
                            <div class="min-w-0 flex-1">
                                <div class="mb-1 flex items-center gap-2 text-caption text-ink-subtle">
                                    ${app.renderBadge(courseInfo.code || '-', 'brand')}
                                    <span>${app.escapeHtml(lecturerName)}</span>
                                </div>
                                <h3 class="mb-2 text-subtitle font-[590] text-ink">
                                    ${classUrl ? `<a href="${classUrl}" class="text-ink no-underline hover:text-brand">${app.escapeHtml(courseInfo.name || 'Tanpa judul')}</a>` : app.escapeHtml(courseInfo.name || 'Tanpa judul')}
                                </h3>
                                <div class="flex flex-wrap gap-3 text-caption text-ink-subtle">
                                    <span class="inline-flex items-center gap-1">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 7a2 2 0 0 1 2 -2h12a2 2 0 0 1 2 2v12a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2v-12z" /><path d="M16 3v4" /><path d="M8 3v4" /><path d="M4 11h16" /><path d="M11 15h1" /><path d="M12 15v3" /></svg>
                                        <span class="${app.textToneClass('brand')}">${app.escapeHtml(schedule.day || '-')}</span> &middot; ${app.escapeHtml(scheduleTime)}
                                    </span>
                                    <span class="inline-flex items-center gap-1">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M3 19l18 0" /><path d="M5 6m0 1a1 1 0 0 1 1 -1h12a1 1 0 0 1 1 1v12a1 1 0 0 1 -1 1h-12a1 1 0 0 1 -1 -1z" /></svg>
                                        ${app.escapeHtml(className)}
                                    </span>
                                    <span class="inline-flex items-center gap-1">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M3 7l6 -3l6 3l6 -3l0 13l-6 3l-6 -3l-6 3l0 -13" /><path d="M9 4l0 13" /><path d="M15 7l0 13" /></svg>
                                        ${app.escapeHtml(roomName)}
                                    </span>
                                </div>
                            </div>
                            <div class="flex shrink-0 flex-row gap-2 md:flex-col">
                                ${classUrl ? `<a href="${classUrl}" class="${app.buttonClass('primary', { small: true })}">Buka kelas</a>` : app.renderDisabledButton('Tidak tersedia')}
                                ${detailApiUrl ? `<button type="button" class="${app.buttonClass('secondary', { small: true })}" data-meeting-toggle data-detail-api="${detailApiUrl}">Lihat pertemuan</button>` : ''}
                            </div>
                        </div>
                        <div class="mt-3 hidden" data-meeting-panel>
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
                    <div class="mycic-divider pt-3">
                        <div class="mb-2 text-caption font-medium text-ink-subtle">Daftar Pertemuan</div>
                        <div class="flex flex-wrap gap-2">
                            ${materials.map((material, index) => {
                                const meetingUrl = app.buildMeetingUrlFromHref(material.join_link?.href);
                                const labelNumber = material.meeting_number || index + 1;

                                if (!meetingUrl) {
                                    return `
                                        <span class="${app.buttonClass('secondary', { small: true })} pointer-events-none cursor-help opacity-50" title="Detail meeting belum tersedia">
                                            P${app.escapeHtml(labelNumber)}
                                        </span>
                                    `;
                                }

                                return `
                                    <a href="${meetingUrl}" class="${app.buttonClass('ghost', { small: true })}" title="Buka Pertemuan ${app.escapeHtml(labelNumber)}">
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
                    listHost.innerHTML = app.renderEmptyState('Belum ada kelas pada sumber ini.', 'secondary');

                    return;
                }

                listHost.innerHTML = classes.map(renderCard).join('');
                bindMeetingPanels();
            };

            const bindMeetingPanels = () => {
                listHost.querySelectorAll('[data-meeting-toggle]').forEach((button) => {
                    button.addEventListener('click', async () => {
                        const article = button.closest('[data-class-card]');
                        const panel = article?.querySelector('[data-meeting-panel]');

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
                    listHost.innerHTML = '<div class="mycic-empty py-8"><p class="mycic-empty-title text-ink-subtle">Memuat data kelas...</p></div>';
                }

                try {
                    const payload = await app.request(sourceConfig.endpoint, { fresh: force });
                    renderSource(payload);
                    app.markViewSynced('Daftar kelas utama', payload, { force });
                } catch (error) {
                    app.showNotice(error.payload?.message || error.message, 'error');
                    listHost.innerHTML = app.renderEmptyState('Gagal memuat daftar kelas.', 'danger');
                }
            };

            app.registerSyncHandler('class-index', loadSource);

            await loadSource();
        });
    </script>
@endpush
