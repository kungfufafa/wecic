{{--
    Pertemuan script, the plain page header (app.ui.pageHero…):
    the course and meeting number as a muted line, the topic, and the
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
                const heroAttendanceBadge = attendanceRecorded
                    ? app.renderBadge('Hadir', 'success')
                    : (attendanceExpired
                        ? app.renderBadge('Presensi ditutup', 'danger')
                        : (attendanceState === 'open' && !attendanceNotOpen ? app.renderBadge('Presensi terbuka', 'brand') : ''));
                const heroAssignmentBadge = assignmentAlreadyUploaded
                    ? app.renderBadge('Tugas terkirim', 'success')
                    : (assignmentStatus === 'upload_unavailable' ? app.renderBadge('Tugas ditutup', 'danger') : '');

                hero.innerHTML = `
                    <header class="${app.ui.pageHero}" data-slot="page-hero">
                        <div class="${app.ui.pageHeroRow}">
                            <div class="min-w-0">
                                <div class="mb-1.5 flex min-w-0 flex-wrap items-center gap-2">
                                    <span class="${app.ui.badge.brand} tabular-nums">${app.escapeHtml(meetingLabel)}</span>
                                    <span class="inline-flex min-w-0 items-center rounded-md bg-surface px-2 py-0.5 text-xs font-medium text-ink-muted ring-1 ring-inset ring-border">
                                        <span class="truncate">${app.escapeHtml(courseName)}</span>
                                    </span>
                                </div>
                                <h1 class="${app.ui.pageHeroTitle}">
                                    ${app.escapeHtml(mainTopicClean)}
                                </h1>
                                <div class="${app.ui.pageHeroMeta}" data-slot="page-hero-meta">
                                    <span class="inline-flex items-center gap-1.5">${app.renderIcon('user', 'sm')}${safeLecturer}</span>
                                    <span class="inline-flex items-center gap-1.5 tabular-nums">${app.renderIcon('calendar', 'sm')}${safeDate}${safeTime !== '-' ? ` · ${safeTime}` : ''}</span>
                                    ${heroAttendanceBadge}
                                    ${heroAssignmentBadge}
                                </div>
                            </div>
                        </div>
                    </header>
                `;
