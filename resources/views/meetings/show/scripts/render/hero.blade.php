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
                    ? `<span class="inline-flex items-center rounded-full border border-emerald-400/30 bg-emerald-500/20 px-2.5 py-0.5 text-xs font-semibold text-emerald-200">Hadir</span>`
                    : (attendanceExpired
                        ? `<span class="inline-flex items-center rounded-full border border-rose-400/30 bg-rose-500/20 px-2.5 py-0.5 text-xs font-semibold text-rose-200">Ditutup</span>`
                        : (attendanceState === 'past'
                            ? `<span class="inline-flex items-center rounded-full border border-rose-400/30 bg-rose-500/20 px-2.5 py-0.5 text-xs font-semibold text-rose-200">Lewat</span>`
                            : `<span class="inline-flex items-center rounded-full border border-amber-400/30 bg-amber-500/20 px-2.5 py-0.5 text-xs font-semibold text-amber-200">${attendanceNotOpen || attendanceState === 'upcoming' ? 'Belum dibuka' : 'Belum'}</span>`));

                hero.innerHTML = `
                    <div class="mycic-hero">
                        <div class="flex flex-col gap-3">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div class="flex flex-wrap items-center gap-2">
                                    <a href="${app.escapeHtml(backHref(detail.class_url))}" class="mycic-hero-pill">Kembali</a>
                                    <span class="inline-flex items-center rounded-lg border border-white/20 bg-white/10 px-2.5 py-1 text-xs font-semibold text-white/90">${app.escapeHtml(courseName)}</span>
                                    <span class="inline-flex items-center rounded-lg bg-accent px-2.5 py-1 text-xs font-bold text-slate-900">${app.escapeHtml(meetingLabel)}</span>
                                </div>
                                ${attendanceBadgeMarkup}
                            </div>
                            <div>
                                <h1 class="text-xl font-extrabold tracking-tight text-white sm:text-2xl">
                                    ${app.escapeHtml(mainTopicClean)}
                                </h1>
                                <div class="mt-1.5 text-sm text-white/80">
                                    ${safeLecturer} · ${safeDate}${safeTime !== '-' ? ` · ${safeTime}` : ''}${attendanceRecorded && attendanceInfo.time ? ` · ${app.escapeHtml(attendanceInfo.time)}` : ''}
                                </div>
                            </div>
                        </div>
                    </div>
                `;

