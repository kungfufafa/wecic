{{--
    Beranda script, today's schedule with its attendance forms, and
    renderDashboard(). Part of the DOMContentLoaded handler opened in
    dashboard.blade.php: it shares that scope (app, esc, the constants and
    state in setup).
--}}
            // ---- Jadwal hari ini & absen ---------------------------------------------------------

            const renderTodaySchedule = (classesSummary) => {
                const summaryHost = document.getElementById('dashboard-class-summary');
                const todayClasses = classesSummary.today_classes || [];
                const dayLabel = classesSummary.day || 'Hari ini';

                latestClassesSummary = classesSummary;
                renderHeroSummary();

                // The snapshot may still describe yesterday while today's is being fetched.
                const snapshotIsToday = !classesSummary.date || classesSummary.date === todayIso();
                const titleNode = document.getElementById('dashboard-today-title');
                const dateNode = document.getElementById('dashboard-today-date');
                const noticeNode = document.getElementById('dashboard-today-notice');
                const longDate = classesSummary.date
                    ? new Date(`${classesSummary.date}T00:00:00`).toLocaleDateString('id-ID', { weekday: 'long', day: 'numeric', month: 'long' })
                    : dayLabel;

                titleNode.textContent = snapshotIsToday ? 'Hari ini' : `Jadwal ${dayLabel}`;
                dateNode.textContent = snapshotIsToday ? longDate : `${longDate} · data lama`;
                noticeNode.innerHTML = snapshotIsToday ? '' : `
                    <div class="${app.alertTone('info')} mb-3 flex items-center gap-3">
                        <span class="${app.ui.spinner} text-info" aria-hidden="true"></span>
                        <span>Mengambil jadwal hari ini… menampilkan jadwal ${esc(dayLabel)}.</span>
                    </div>
                `;

                // Where the quick QR button in the mobile nav should go. An older day's
                // schedule points at that day's meetings, never at today's attendance.
                const scannable = snapshotIsToday
                    ? todayClasses.find((klass) => klass.attendance?.actionable && klass.attendance?.meeting_url)
                    : null;
                // Attendance is read after the schedule (class and meeting pages); until then it is "checking".
                const attendanceChecking = todayClasses.some((klass) => klass.attendance?.state === 'checking');
                app.setQuickQrTarget(
                    scannable ? `${scannable.attendance.meeting_url}&qr=1` : null,
                    !snapshotIsToday
                        ? 'Jadwal hari ini masih dimuat.'
                        : (attendanceChecking
                            ? 'Status absensi masih dicek.'
                            : (todayClasses.length
                                ? 'Absensi hari ini belum dibuka dosen.'
                                : `Tidak ada kelas ${dayLabel}.`))
                );

                if (!hasChanged('today', { date: classesSummary.date, day: classesSummary.day, todayClasses, snapshotIsToday })) {
                    return;
                }

                summaryHost.removeAttribute('aria-busy');

                if (!todayClasses.length) {
                    summaryHost.innerHTML = app.renderEmptyState(`Tidak ada kelas ${dayLabel}.`, 'secondary', '', { icon: 'calendar' });

                    return;
                }

                // A changed schedule re-renders the forms; keep any code already typed in.
                const typedCodes = new Map();
                summaryHost.querySelectorAll('[data-attendance-card]').forEach((form) => {
                    const typed = form.querySelector('[data-field="kode"]')?.value;

                    if (typed) {
                        typedCodes.set(form.dataset.attendanceKey, typed);
                    }
                });

                // A vertical timeline: the start time, a rail with a dot (gray, blue while
                // attendance is open, or the attendance status) and the class card.
                summaryHost.innerHTML = `<ol class="flex flex-col" aria-label="Jadwal ${esc(dayLabel)}">${todayClasses.map((klass, index) => {
                    const attendance = klass.attendance || {};
                    const targetReliable = attendance.target_reliable !== false;
                    // Only today's schedule takes attendance: an older day's form would
                    // send today's code to that day's meeting.
                    const actionable = snapshotIsToday && Boolean(attendance.actionable);
                    let statusColor = 'secondary';
                    if (actionable) statusColor = 'primary';
                    else if (attendance.status?.already_marked && targetReliable) statusColor = 'success';
                    else if (snapshotIsToday && attendance.available && targetReliable) statusColor = 'warning';

                    const railDot = { primary: app.ui.fill.brand, success: app.ui.fill.success, warning: app.ui.fill.warning }[statusColor] || app.ui.fill.neutral;
                    const [startTime = '', endTime = ''] = String(klass.schedule?.time || '').split(/\s*[-–]\s*/).map((part) => part.trim());
                    const isLast = index === todayClasses.length - 1;

                    const materialTitle = attendance.today_material?.title;
                    const materialIsToday = attendance.today_material?.is_today !== false;
                    const materialLabel = materialTitle && materialTitle !== 'Materi terbaru belum terdeteksi.' ? materialTitle : null;
                    // Without today's material, "Buka" leads to the class page, not an old meeting.
                    const openUrl = targetReliable ? attendance.meeting_url : (attendance.class_url || attendance.meeting_url);
                    const attendanceKey = attendance.meeting_url || [klass.course_code, klass.class_name, index].filter(Boolean).join('|');
                    // The meeting page shows no attendance window: not open yet, or closed after class (not attended).
                    const attendanceNote = targetReliable ? app.todayAttendanceNote(attendance.status) : null;

                    return `
                    <li class="relative flex gap-3 pb-5 last:pb-0">
                    <div class="w-10 shrink-0 pt-0.5 text-end text-xs font-medium tabular-nums text-ink-muted sm:w-12">
                        ${esc(startTime || '–')}
                        ${endTime ? `<div class="font-normal text-ink-subtle"><span class="sr-only">sampai </span>${esc(endTime)}</div>` : ''}
                    </div>
                    <div class="relative flex w-3 shrink-0 justify-center" aria-hidden="true">
                        <span class="mt-1.5 size-2.5 rounded-full ${railDot}"></span>
                        ${isLast ? '' : '<span class="absolute top-4 -bottom-5 w-px bg-border"></span>'}
                    </div>
                    <div class="${app.ui.card} min-w-0 flex-1 overflow-hidden" data-slot="card">
                        <div class="${app.ui.cardBody}">
                            <div class="flex flex-wrap items-start gap-3">
                                <div class="flex min-w-0 flex-1 flex-col gap-1">
                                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-ink-subtle">
                                        <span class="font-mono font-medium">${esc([klass.course_code, klass.class_name].filter(Boolean).join(' · ') || 'Kelas')}</span>
                                    </div>
                                    <h3 class="${app.ui.cardTitle}">${esc(klass.course_name || 'Kelas')}</h3>
                                    <div class="text-[13px] text-ink-muted">
                                        ${esc(klass.lecturer_name || '-')} · ${esc(klass.room || '-')}
                                    </div>
                                </div>
                                <div class="shrink-0">
                                    ${openUrl
                                        ? `<a href="${esc(openUrl)}" class="${app.buttonClass('secondary', { small: true })}">Buka</a>`
                                        : ''}
                                </div>
                            </div>

                            ${(attendance.meeting_number || materialLabel) && (materialIsToday || materialLabel) ? `
                                <div class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1 border-t border-border-light pt-3 text-[13px] text-ink-subtle">
                                    ${materialIsToday && attendance.meeting_number ? `<span class="${app.ui.badge.neutral} tabular-nums">P${esc(attendance.meeting_number)}</span>` : ''}
                                    ${materialLabel ? `<span class="text-ink-muted">${materialIsToday ? '' : 'Materi terakhir: '}${esc(materialLabel)}</span>` : ''}
                                </div>
                            ` : ''}
                        </div>

                        <div class="border-t border-border-light bg-surface/40 px-4 py-3 sm:px-5">
                            ${!snapshotIsToday && !attendance.status?.already_marked ? `
                                <div class="text-sm text-ink-subtle">Menunggu jadwal hari ini.</div>
                            ` : attendance.state === 'checking' ? `
                                <div class="flex items-center gap-2 text-sm text-ink-subtle" role="status">
                                    <span class="${app.ui.spinner}" aria-hidden="true"></span>
                                    <span>Mengecek status absensi…</span>
                                </div>
                            ` : attendance.state === 'unavailable' ? `
                                <div class="text-sm text-ink-subtle">Status absensi tidak terbaca. Buka kelas untuk absen.</div>
                            ` : !targetReliable ? `
                                <div class="text-sm text-ink-subtle">Materi hari ini belum diunggah dosen.</div>
                            ` : attendanceNote && !actionable ? `
                                <div class="text-sm font-medium ${textTone(statusColor)}">${esc(attendanceNote)}</div>
                            ` : actionable ? `
                                ${attendanceNote ? `<div class="mb-2 text-xs text-ink-subtle">${esc(attendanceNote)}</div>` : ''}
                                <form class="flex flex-wrap items-center gap-2" data-attendance-card="${index}" data-attendance-key="${esc(attendanceKey)}">
                                    <div class="w-full min-w-0 md:w-auto md:min-w-[12rem] md:flex-1">
                                        <label class="sr-only" for="dashboard-attendance-code-${index}">Kode absen ${esc(klass.course_name || 'kelas ini')}</label>
                                        <input type="text" class="${app.ui.input}" id="dashboard-attendance-code-${index}" data-field="kode" placeholder="Kode absen" autocomplete="off" autocapitalize="off" spellcheck="false" required>
                                    </div>
                                    <button type="button" class="${app.buttonClass('secondary')}" data-qr-scan>Scan QR</button>
                                    <div class="shrink-0">
                                        <input type="file" class="peer sr-only" id="qr-file-${index}" data-qr-file accept="image/*">
                                        <label class="${app.buttonClass('secondary')} peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-brand" for="qr-file-${index}">Upload QR</label>
                                    </div>
                                    <button type="submit" class="${app.buttonClass('primary')} md:ms-auto" data-online-required data-outbox-capable>Kirim</button>
                                </form>
                            ` : attendance.status?.already_marked ? `
                                <div class="flex items-center gap-2 text-sm font-medium ${textTone(statusColor)}">
                                    <span class="size-1.5 shrink-0 rounded-full bg-success" aria-hidden="true"></span>
                                    <span>${esc(attendance.status?.label || 'Hadir')}${attendance.status?.time ? ` · ${esc(attendance.status.time)}` : ''}</span>
                                </div>
                            ` : attendance.available ? `
                                <div class="flex flex-wrap items-center justify-between gap-2 ${textTone(statusColor)}">
                                    <span class="flex items-center gap-2 text-sm font-medium">
                                        <span class="size-1.5 shrink-0 rounded-full bg-warning" aria-hidden="true"></span>
                                        <span>Absensi tersedia</span>
                                    </span>
                                    <a href="${esc(attendance.meeting_url || '/class')}" class="${app.buttonClass('secondary', { small: true })}">Buka</a>
                                </div>
                            ` : `
                                <div class="text-sm text-ink-subtle">Belum ada absensi.</div>
                            `}
                        </div>
                    </div>
                    </li>
                    `;
                }).join('')}</ol>`;

                summaryHost.querySelectorAll('[data-attendance-card]').forEach((form) => {
                    const classIndex = Number(form.dataset.attendanceCard);
                    const klass = Number.isInteger(classIndex) ? todayClasses[classIndex] : null;
                    const attendancePayload = klass?.attendance?.payload || null;
                    const shortcutContext = klass?.attendance?.context || null;
                    const codeInput = form.querySelector('[data-field="kode"]');

                    if (typedCodes.has(form.dataset.attendanceKey)) {
                        codeInput.value = typedCodes.get(form.dataset.attendanceKey);
                    }

                    if (!attendancePayload && !shortcutContext) {
                        return;
                    }

                    app.bindAttendanceQrTools(form, codeInput, {
                        // The schedule may have re-rendered while the camera was open:
                        // send the code with this class's form as it is on screen now.
                        onDetected: (code) => {
                            const liveForm = Array.from(summaryHost.querySelectorAll('[data-attendance-card]'))
                                .find((candidate) => candidate.dataset.attendanceKey === form.dataset.attendanceKey);
                            const liveInput = liveForm?.querySelector('[data-field="kode"]');

                            if (!liveForm || !liveInput) {
                                app.showNotice('Absensi kelas ini sudah tidak bisa diisi dari jadwal. Muat ulang beranda untuk melihat statusnya.', 'warning');

                                return;
                            }

                            liveInput.value = code;
                            app.showNotice('QR terbaca. Mengirim absensi...', 'info');
                            app.requestFormSubmit(liveForm);
                        },
                    });

                    form.addEventListener('submit', async (event) => {
                        event.preventDefault();

                        if (!app.requireOnlineAction('Absen butuh koneksi internet.', { queueable: true })) {
                            return;
                        }

                        const kode = codeInput.value.trim();

                        await app.guardFormSubmit(form, () => app.withSubmitLoading(form.querySelector('button[type="submit"]'), async () => {
                            try {
                                const body = attendancePayload
                                    ? {
                                        ...attendancePayload,
                                        kode,
                                    }
                                    : {
                                        kode,
                                        shortcut_context: shortcutContext,
                                    };

                                const result = await app.request('/api/attendance', {
                                    method: 'POST',
                                    json: body,
                                });

                                // Offline session: the code waits in the outbox, not at the portal.
                                if (app.handleQueuedActionResult(result)) {
                                    return;
                                }

                                app.showNotice(
                                    result?.data?.status === 'already_marked'
                                        ? 'Kehadiran kamu sudah tercatat untuk pertemuan ini.'
                                        : 'Absensi berhasil dikirim.',
                                    'success'
                                );
                                app.clearCachedResponse('/api/dashboard');
                                app.clearCachedResponse('/api/attendance/today');

                                if (klass?.attendance?.meeting_url) {
                                    app.clearCachedResponse(
                                        klass.attendance.meeting_url.replace('/meeting/', '/api/meeting/')
                                    );
                                }

                                // The server already shows this class as attended, so
                                // re-reading its snapshot is instant (no portal scrape).
                                await loadDashboard();
                            } catch (error) {
                                if (error.isTimeout) {
                                    app.showNotice('Portal lambat merespons. Mengecek status absensi...', 'warning');
                                    await loadDashboard();

                                    return;
                                }

                                app.showNotice(error.payload?.message || error.message, 'error');
                            }
                        }));
                    });
                });

                app.refreshOnlineRequiredState();
            };

            const renderDashboard = (payload) => {
                if (!payload || typeof payload !== 'object') {
                    return;
                }

                const data = payload.data || {};
                latestDashboardPayload = payload;

                if (data.user_info && (data.user_info.name || data.user_info.nama || data.user_info.student_id || data.user_info.nim)) {
                    app.hydrateUser(payload);
                }

                const pendingSources = Array.isArray(data.pending_sources) ? data.pending_sources : [];

                const attendanceChecking = (data.classes_summary?.today_classes || [])
                    .some((klass) => klass?.attendance?.state === 'checking');

                renderPeriod(data.portal_context, pendingSources);
                renderMetrics(data);
                renderTodaySchedule(data.classes_summary || {});
                scheduleRecheck('dashboard', pendingSources.length > 0 || attendanceChecking, (options) => loadDashboard(options));
            };

