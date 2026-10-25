{{--
    Jadwal & Kelas script, the Timeline view, day by day with the free hours
    between classes. Part of the DOMContentLoaded handler opened in
    classes/index.blade.php: it shares that scope (app, the elements,
    constants and state in setup).
--}}
            const renderTimeline = (dayMap) => {
                const daysToRender = selectedDayFilter === 'all'
                    ? DAYS_LIST
                    : DAYS_LIST.filter((d) => d === selectedDayFilter);

                const searchLower = currentSearchQuery.trim().toLowerCase();

                timelineHost.innerHTML = daysToRender.map((d) => {
                    const group = dayMap[d];
                    const isToday = d === TODAY_DAY;
                    const classesToRender = searchLower
                        ? group.classes.filter((c) =>
                            c.courseName.toLowerCase().includes(searchLower) ||
                            c.courseCode.toLowerCase().includes(searchLower) ||
                            c.lecturerName.toLowerCase().includes(searchLower) ||
                            c.roomName.toLowerCase().includes(searchLower)
                        )
                        : group.classes;

                    const count = classesToRender.length;

                    return `
                        <div class="mycic-card overflow-hidden">
                            <!-- Day Header -->
                            <div class="flex items-center justify-between border-b border-border bg-surface/60 px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <h3 class="text-subtitle font-bold text-ink">${d}</h3>
                                    ${isToday ? '<span class="rounded bg-brand px-2 py-0.5 text-xs font-bold text-white uppercase tracking-tight">Hari Ini</span>' : ''}
                                    <span class="rounded-full bg-surface-tint px-2.5 py-0.5 text-xs font-semibold text-ink-muted">
                                        ${count > 0 ? `${count} kelas` : 'Libur'}
                                    </span>
                                </div>
                                ${count > 0 ? `<div class="text-caption text-ink-subtle">${formatDuration(group.totalMinutes)}</div>` : ''}
                            </div>

                            <div class="p-4 flex flex-col gap-3">
                                ${count === 0 ? `
                                    <div class="rounded-lg border border-dashed border-border p-4 text-center text-sm text-ink-subtle">
                                        ${searchLower ? 'Tidak ada hasil.' : 'Libur'}
                                    </div>
                                ` : ''}

                                ${classesToRender.map((c, index) => {
                                    const p = c.palette;
                                    const nextClass = classesToRender[index + 1];
                                    let gapHtml = '';

                                    if (nextClass && c.endMin !== null && nextClass.startMin !== null && nextClass.startMin > c.endMin) {
                                        const gapDur = nextClass.startMin - c.endMin;
                                        if (gapDur >= 15) {
                                            gapHtml = `
                                                <div class="my-1 rounded-lg border border-dashed border-amber-500/40 bg-amber-500/10 px-3 py-2 text-xs font-semibold text-amber-950 dark:text-amber-100">
                                                    ☕ ${formatDuration(gapDur)} · ${formatMinutesToTime(c.endMin)}–${formatMinutesToTime(nextClass.startMin)}
                                                </div>
                                            `;
                                        }
                                    }

                                    return `
                                        <div class="rounded-lg border ${p.border} ${p.accent} border-l-4 ${p.bg} p-3.5 transition-all">
                                            <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                                <div class="min-w-0 flex-1">
                                                    <div class="flex items-center gap-2 mb-1 text-caption">
                                                        <span class="rounded px-2 py-0.5 text-xs font-bold ${p.timeBadge}">
                                                            ${app.escapeHtml(c.formattedTime)}
                                                        </span>
                                                        ${c.courseCode ? `<span class="rounded bg-black/5 dark:bg-white/10 px-1.5 py-0.5 text-xs font-semibold text-ink-subtle uppercase">${app.escapeHtml(c.courseCode)}</span>` : ''}
                                                        <span class="text-ink-subtle">&middot; ${formatDuration(c.durationMinutes)}</span>
                                                    </div>
                                                    <h4 class="text-subtitle font-bold text-ink">
                                                        ${c.classUrl ? `<a href="${c.classUrl}" class="hover:text-brand">${app.escapeHtml(c.courseName)}</a>` : app.escapeHtml(c.courseName)}
                                                    </h4>
                                                    <div class="mt-2 flex flex-wrap gap-3 text-caption text-ink-subtle">
                                                        <span>${app.escapeHtml(c.roomName)}</span>
                                                        <span>${app.escapeHtml(c.className)}</span>
                                                        <span>${app.escapeHtml(c.lecturerName)}</span>
                                                    </div>
                                                </div>
                                                <div class="flex items-center gap-2 shrink-0 mt-2 sm:mt-0">
                                                    ${c.classUrl ? `<a href="${c.classUrl}" class="${app.buttonClass('primary', { small: true })}">Buka kelas</a>` : ''}
                                                    ${c.detailApiUrl ? `<button type="button" class="${app.buttonClass('secondary', { small: true })}" data-meeting-toggle data-detail-api="${c.detailApiUrl}">Lihat pertemuan</button>` : ''}
                                                </div>
                                            </div>
                                            <div class="mt-3 hidden" data-meeting-panel>
                                                ${app.renderEmptyState('Memuat daftar pertemuan.', 'secondary')}
                                            </div>
                                        </div>
                                        ${gapHtml}
                                    `;
                                }).join('')}

                            </div>
                        </div>
                    `;
                }).join('');

                bindMeetingPanels(timelineHost);
            };

