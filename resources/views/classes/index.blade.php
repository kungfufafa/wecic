@extends('layouts.app')

@section('title', 'Kelas')
@section('page_id', 'class-index')

@section('content')
    <section class="grid gap-6">
        <div class="app-panel">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="section-label">Kelas</p>
                    <h1 data-display class="mt-4 text-3xl font-semibold text-[color:var(--ink)] sm:text-4xl">Daftar kelas</h1>
                </div>
                <div class="grid w-full grid-cols-3 gap-2 lg:w-auto">
                    <button type="button" class="secondary-button" data-class-source="main">Utama</button>
                    <button type="button" class="ghost-button" data-class-source="lms">LMS</button>
                    <button type="button" class="ghost-button" data-class-source="schedule">Jadwal</button>
                </div>
            </div>
        </div>

        <div id="class-summary" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <article class="summary-tile">
                <span class="summary-tile-value" data-summary-value data-summary-mode="metric">-</span>
                <span>Total kelas</span>
            </article>
            <article class="summary-tile">
                <span class="summary-tile-value is-compact" data-summary-value data-summary-mode="compact">-</span>
                <span>Mahasiswa</span>
            </article>
            <article class="summary-tile">
                <span class="summary-tile-value is-compact" data-summary-value data-summary-mode="compact">-</span>
                <span>Semester</span>
            </article>
            <article class="summary-tile">
                <span class="summary-tile-value is-compact" data-summary-value data-summary-mode="compact">-</span>
                <span>Sumber</span>
            </article>
        </div>

        <div id="class-list" class="grid gap-4">
            <div class="empty-shell">Memuat daftar kelas.</div>
        </div>
    </section>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', async () => {
            const app = window.portalApp;
            const sourceMap = {
                main: { label: 'Utama', endpoint: '/api/class' },
                lms: { label: 'LMS', endpoint: '/api/lms/class' },
                schedule: { label: 'Jadwal', endpoint: '/api/schedule/class' },
            };

            const summaryCards = document.querySelectorAll('[data-summary-value]');
            const listHost = document.getElementById('class-list');
            let currentSource = 'main';

            const setActive = (current) => {
                document.querySelectorAll('[data-class-source]').forEach((button) => {
                    button.className = button.dataset.classSource === current ? 'secondary-button' : 'ghost-button';
                });
            };

            const renderSummary = (payload, source) => {
                const classes = payload.classes || [];
                const identity = payload.user_info || payload.student_info || {};
                const semester = payload.semester_info?.semester || payload.semester_info?.label || payload.academic_summary?.semester || '-';

                summaryCards[0].textContent = classes.length || '0';
                summaryCards[1].textContent = identity.nim || identity.student_id || '-';
                summaryCards[2].textContent = semester;
                summaryCards[3].textContent = sourceMap[source].label;
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
                    <article class="class-record">
                        <div class="class-record-shell">
                            <div class="class-record-course">
                                <p class="class-record-code">${app.escapeHtml(courseInfo.code || '-')}</p>
                                <h2 data-display class="class-record-title">${app.escapeHtml(courseInfo.name || 'Tanpa judul')}</h2>
                                <p class="class-record-lecturer">${app.escapeHtml(lecturerName)}</p>
                            </div>
                            <dl class="class-record-stats">
                                <div class="class-record-stat">
                                    <dt>Hari</dt>
                                    <dd>${app.escapeHtml(schedule.day || '-')}</dd>
                                </div>
                                <div class="class-record-stat">
                                    <dt>Jam</dt>
                                    <dd>${app.escapeHtml(scheduleTime)}</dd>
                                </div>
                                <div class="class-record-stat">
                                    <dt>Ruang</dt>
                                    <dd>${app.escapeHtml(roomName)}</dd>
                                </div>
                                <div class="class-record-stat">
                                    <dt>Kelas</dt>
                                    <dd>${app.escapeHtml(className)}</dd>
                                </div>
                            </dl>
                            <div class="class-record-actions">
                                ${classUrl ? `<a href="${classUrl}" class="primary-button">Detail kelas</a>` : '<span class="secondary-button col-span-2 opacity-50 lg:col-span-1">Detail tidak tersedia</span>'}
                                ${detailApiUrl ? `<button type="button" class="secondary-button" data-meeting-toggle data-detail-api="${detailApiUrl}">Pertemuan</button>` : ''}
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
                    <div class="flex flex-wrap gap-2">
                        ${materials.map((material, index) => {
                            const meetingUrl = app.buildMeetingUrlFromHref(material.join_link?.href);
                            const labelNumber = material.meeting_number || index + 1;

                            if (!meetingUrl) {
                                return `<span class="ghost-button opacity-50">Pertemuan ${app.escapeHtml(labelNumber)}</span>`;
                            }

                            return `
                                <a href="${meetingUrl}" class="secondary-button">
                                    Pertemuan ${app.escapeHtml(labelNumber)}
                                </a>
                            `;
                        }).join('')}
                    </div>
                `;
            };

            const renderSource = (payload, source) => {
                renderSummary(payload, source);

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
                            button.textContent = 'Pertemuan';

                            return;
                        }

                        panel.classList.remove('hidden');
                        panel.innerHTML = '<div class="empty-shell">Memuat daftar pertemuan.</div>';
                        button.textContent = 'Tutup';

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
                            button.textContent = 'Pertemuan';
                        }
                    });
                });
            };

            app.primeCachedView(sourceMap[currentSource].endpoint, (payload) => {
                setActive(currentSource);
                renderSource(payload, currentSource);
            }, 'daftar kelas utama');

            const ready = await app.ensureAuthenticated();

            if (!ready) {
                return;
            }

            const loadSource = async (source) => {
                currentSource = source;
                setActive(source);
                const usedCachedPayload = app.primeCachedView(sourceMap[source].endpoint, (payload) => {
                    setActive(source);
                    renderSource(payload, source);
                }, `daftar kelas ${sourceMap[source].label.toLowerCase()}`);

                if (!usedCachedPayload) {
                    listHost.innerHTML = '<div class="empty-shell">Memuat data kelas.</div>';
                }

                try {
                    const payload = await app.request(sourceMap[source].endpoint);
                    renderSource(payload, source);
                    app.markViewSynced(`Daftar kelas ${sourceMap[source].label.toLowerCase()}`);
                } catch (error) {
                    app.showNotice(error.payload?.message || error.message, 'error');
                    listHost.innerHTML = '<div class="empty-shell">Gagal memuat daftar kelas.</div>';
                }
            };

            app.registerSyncHandler('class-index', () => loadSource(currentSource));

            document.querySelectorAll('[data-class-source]').forEach((button) => {
                button.addEventListener('click', () => loadSource(button.dataset.classSource));
            });

            await loadSource('main');
        });
    </script>
@endpush
