{{--
    Pertemuan script, the hero: course, meeting number, topic, lecturer,
    date and time, and the attendance badge. Part of render() opened in
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
                const attendanceBadgeMarkup = attendanceRecorded
                    ? app.renderBadge('Hadir', 'success')
                    : (attendanceExpired
                        ? app.renderBadge('Ditutup', 'danger')
                        : (attendanceState === 'past'
                            ? app.renderBadge('Lewat', 'danger')
                            : app.renderBadge(attendanceNotOpen || attendanceState === 'upcoming' ? 'Belum dibuka' : 'Belum', 'warning')));

                hero.innerHTML = `
                    <div class="mycic-hero">
                        <div class="flex flex-col gap-3">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div class="flex flex-wrap items-center gap-2">
                                    <a href="${app.escapeHtml(backHref(detail.class_url))}" class="mycic-hero-pill">Kembali</a>
                                    <span class="mycic-badge-neutral">${app.escapeHtml(courseName)}</span>
                                    <span class="mycic-badge-brand font-mono tabular-nums">${app.escapeHtml(meetingLabel)}</span>
                                </div>
                                ${attendanceBadgeMarkup}
                            </div>
                            <div>
                                <h1 class="mycic-page-heading">
                                    ${app.escapeHtml(mainTopicClean)}
                                </h1>
                                <div class="mycic-page-subheading">
                                    ${safeLecturer} · <span class="font-mono tabular-nums">${safeDate}${safeTime !== '-' ? ` · ${safeTime}` : ''}${attendanceRecorded && attendanceInfo.time ? ` · ${app.escapeHtml(attendanceInfo.time)}` : ''}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                `;

