{{--
    Jadwal & Kelas script, the Timeline view: a card per day with its
    classes on a vertical rail (time on the left, a dot, the class) and
    the free hours between them as hollow stops. Part of the
    DOMContentLoaded handler opened in classes/index.blade.php: it shares
    that scope (app, the elements, constants and state in setup).
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

                    // Each class is a stop on the day's rail: its time on the left, a dot,
                    // the class; a free hour is a hollow stop.
                    const stops = [];

                    classesToRender.forEach((c, index) => {
                        stops.push({ type: 'class', c });
                        const nextClass = classesToRender[index + 1];

                        if (nextClass && c.endMin !== null && nextClass.startMin !== null && nextClass.startMin - c.endMin >= 15) {
                            stops.push({ type: 'gap', from: c.endMin, to: nextClass.startMin });
                        }
                    });

                    const renderStop = (stop, index) => {
                        const isLast = index === stops.length - 1;
                        const rail = isLast ? '' : '<span class="absolute top-4 -bottom-5 w-px bg-border" aria-hidden="true"></span>';

                        if (stop.type === 'gap') {
                            return `
                                <li class="relative flex gap-3 pb-5 last:pb-0">
                                    <div class="w-10 shrink-0 pt-0.5 text-end text-xs tabular-nums text-ink-subtle sm:w-12">${formatMinutesToTime(stop.from)}</div>
                                    <div class="relative flex w-3 shrink-0 justify-center">
                                        <span class="mt-1.5 size-2.5 rounded-full border border-dashed border-ink-subtle bg-panel ring-4 ring-panel" aria-hidden="true"></span>
                                        ${rail}
                                    </div>
                                    <p class="min-w-0 flex-1 pt-0.5 text-xs text-ink-subtle tabular-nums">Jeda ${formatDuration(stop.to - stop.from)} · ${formatMinutesToTime(stop.from)}–${formatMinutesToTime(stop.to)}</p>
                                </li>
                            `;
                        }

                        const c = stop.c;

                        return `
                            <li class="relative flex gap-3 pb-5 last:pb-0">
                                <div class="w-10 shrink-0 pt-0.5 text-end text-xs font-medium tabular-nums text-ink-muted sm:w-12">
                                    ${app.escapeHtml(c.startTimeFormatted || '-')}
                                    ${c.endTimeFormatted ? `<div class="font-normal text-ink-subtle">${app.escapeHtml(c.endTimeFormatted)}</div>` : ''}
                                </div>
                                <div class="relative flex w-3 shrink-0 justify-center">
                                    <span class="mt-1.5 size-2.5 rounded-full ring-4 ring-panel ${c.palette.dot}" aria-hidden="true"></span>
                                    ${rail}
                                </div>
                                <div class="min-w-0 flex-1" data-class-card>
                                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                        <div class="min-w-0 flex-1">
                                            <h4 class="text-sm font-medium leading-snug text-ink">
                                                ${c.classUrl ? `<a href="${c.classUrl}" class="text-ink no-underline transition-colors hover:text-brand-text">${app.escapeHtml(c.courseName)}</a>` : app.escapeHtml(c.courseName)}
                                            </h4>
                                            <div class="mt-1 flex flex-wrap items-center gap-x-1.5 text-xs text-ink-subtle tabular-nums">
                                                ${c.courseCode ? `<span class="font-mono">${app.escapeHtml(c.courseCode)}</span><span aria-hidden="true">&middot;</span>` : ''}
                                                <span>${formatDuration(c.durationMinutes)}</span>
                                            </div>
                                            <div class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-xs text-ink-subtle">
                                                <span class="inline-flex items-center gap-1">${app.renderIcon('map-pin', 'sm')}${app.escapeHtml(c.roomName)}</span>
                                                <span class="inline-flex items-center gap-1">${app.renderIcon('users', 'sm')}${app.escapeHtml(c.className)}</span>
                                                <span class="inline-flex items-center gap-1">${app.renderIcon('user', 'sm')}${app.escapeHtml(c.lecturerName)}</span>
                                            </div>
                                        </div>
                                        <div class="flex shrink-0 flex-wrap items-center gap-2">
                                            ${c.classUrl ? `<a href="${c.classUrl}" class="${app.buttonClass('secondary', { small: true })}">Buka kelas</a>` : ''}
                                            ${c.detailApiUrl ? `<button type="button" class="${app.buttonClass('secondary', { small: true })}" data-meeting-toggle data-detail-api="${c.detailApiUrl}">Lihat pertemuan</button>` : ''}
                                        </div>
                                    </div>
                                    <div class="mt-3 hidden" data-meeting-panel>
                                        ${renderMeetingPanelNote('Memuat daftar pertemuan.')}
                                    </div>
                                </div>
                            </li>
                        `;
                    };

                    return `
                        <div class="${app.ui.card} overflow-hidden" data-slot="card">
                            <!-- Day Header -->
                            <div class="${app.ui.cardHeader}">
                                <div class="flex min-w-0 flex-wrap items-center gap-2">
                                    <div class="${app.ui.sectionTitleRow}">
                                        <h3 class="${app.ui.cardTitle}">${d}</h3>
                                        <span class="${app.ui.sectionCount}">${count > 0 ? `${count} kelas` : ''}</span>
                                    </div>
                                    ${isToday ? `<span class="${app.ui.badge.brand}">Hari ini</span>` : ''}
                                </div>
                                ${count > 0 ? `<div class="shrink-0 text-xs text-ink-subtle tabular-nums">${formatDuration(group.totalMinutes)}</div>` : ''}
                            </div>

                            <div class="${app.ui.cardBody}">
                                ${count === 0 ? `
                                    <div class="py-4 text-center text-sm text-ink-subtle">
                                        ${searchLower ? 'Tidak ada hasil.' : 'Libur'}
                                    </div>
                                ` : `<ol class="flex flex-col">${stops.map(renderStop).join('')}</ol>`}
                            </div>
                        </div>
                    `;
                }).join('');

                bindMeetingPanels(timelineHost);
            };

