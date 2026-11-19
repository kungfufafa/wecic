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
                            <div class="mycic-card-header flex items-center justify-between gap-2 bg-surface/60">
                                <div class="flex items-center gap-2">
                                    <h3 class="mycic-card-title">${d}</h3>
                                    ${isToday ? '<span class="inline-flex items-center rounded-md bg-brand px-2 py-0.5 text-xs font-medium text-brand-ink">Hari ini</span>' : ''}
                                    <span class="mycic-badge-neutral font-mono tabular-nums">
                                        ${count > 0 ? `${count} kelas` : 'Libur'}
                                    </span>
                                </div>
                                ${count > 0 ? `<div class="font-mono text-caption text-ink-subtle tabular-nums">${formatDuration(group.totalMinutes)}</div>` : ''}
                            </div>

                            <div class="mycic-card-body flex flex-col gap-3">
                                ${count === 0 ? `
                                    <div class="py-4 text-center text-sm text-ink-subtle">
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
                                                <div class="flex items-center gap-3 py-1 font-mono text-xs text-ink-subtle tabular-nums">
                                                    <span class="h-px flex-1 border-t border-dashed border-border" aria-hidden="true"></span>
                                                    <span>Jeda ${formatDuration(gapDur)} · ${formatMinutesToTime(c.endMin)}–${formatMinutesToTime(nextClass.startMin)}</span>
                                                    <span class="h-px flex-1 border-t border-dashed border-border" aria-hidden="true"></span>
                                                </div>
                                            `;
                                        }
                                    }

                                    return `
                                        <div class="rounded-[var(--radius-control)] border ${p.border} ${p.accent} border-l-[3px] ${p.bg} p-4 transition-colors duration-150" data-class-card>
                                            <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                                <div class="min-w-0 flex-1">
                                                    <div class="flex items-center gap-2 mb-1 text-caption">
                                                        <span class="inline-flex items-center rounded-md px-2 py-0.5 font-mono text-xs font-medium tabular-nums ${p.timeBadge}">
                                                            ${app.escapeHtml(c.formattedTime)}
                                                        </span>
                                                        ${c.courseCode ? `<span class="mycic-badge-neutral font-mono">${app.escapeHtml(c.courseCode)}</span>` : ''}
                                                        <span class="font-mono text-ink-subtle tabular-nums">&middot; ${formatDuration(c.durationMinutes)}</span>
                                                    </div>
                                                    <h4 class="text-sm font-medium leading-snug text-ink">
                                                        ${c.classUrl ? `<a href="${c.classUrl}" class="text-ink no-underline transition-colors hover:text-brand-text">${app.escapeHtml(c.courseName)}</a>` : app.escapeHtml(c.courseName)}
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

