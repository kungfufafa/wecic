{{--
    Pertemuan script, the plain page header (app.ui.pageHero…): the back
    link, the course and meeting number as a muted line, the topic, and the
    lecturer, date and time as meta. The attendance status is shown once, in
    the Presensi card (action-cards), not here as well. Part of render() opened in
    meetings/show/scripts/render.blade.php: it shares that scope (payload,
    detail and the meeting, attendance and assignment state derived there)
    and the handler's (app, the helpers and the action slots).
--}}
                // Render Hero
                const hero = document.getElementById('meeting-hero');
                const safeLecturer = app.escapeHtml(lecturerInfo.name || '-');
                const safeDate = app.escapeHtml(scheduleInfo.date || '-');
                const safeTime = app.escapeHtml(scheduleInfo.start_time || '-');
                const safeAttendanceStatus = app.escapeHtml(attendanceStatusText || (attendanceRecorded ? 'Hadir' : 'Belum Absen'));

                const attendanceExpired = attendanceView === 'closed';

                hero.innerHTML = `
                    <header class="${app.ui.pageHero}" data-slot="page-hero">
                        <a href="${app.escapeHtml(backHref(detail.class_url))}" class="${app.ui.backLink} mb-2">${BACK_ICON}<span>Kembali</span></a>
                        <div class="${app.ui.pageHeroRow}">
                            <div class="min-w-0">
                                <div class="mb-1 flex min-w-0 flex-wrap items-center gap-x-1.5 gap-y-1 text-[13px] text-ink-muted">
                                    <span class="min-w-0 truncate">${app.escapeHtml(courseName)}</span>
                                    <span class="tabular-nums before:me-1.5 before:content-['·']">${app.escapeHtml(meetingLabel)}</span>
                                </div>
                                <h1 class="${app.ui.pageHeroTitle}">
                                    ${app.escapeHtml(mainTopicClean)}
                                </h1>
                                <div class="${app.ui.pageHeroMeta}" data-slot="page-hero-meta">
                                    <span class="inline-flex items-center gap-1.5">${app.renderIcon('user', 'sm')}${safeLecturer}</span>
                                    <span class="inline-flex items-center gap-1.5 tabular-nums">${app.renderIcon('calendar', 'sm')}${safeDate}${safeTime !== '-' ? ` · ${safeTime}` : ''}</span>
                                </div>
                            </div>
                        </div>
                    </header>
                `;
