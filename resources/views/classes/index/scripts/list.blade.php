{{--
    Jadwal & Kelas script, the Daftar view (one card, a divided row per
    class) and the classes without a fixed schedule. Part of the
    DOMContentLoaded handler opened in classes/index.blade.php: it shares
    that scope (app, the elements, constants and state in setup).
--}}
            // A class as a row of the Daftar list: day and time badge on the left, the
            // course, and its actions.
            const renderCard = (c) => {
                const isToday = c.day === TODAY_DAY;
                const ongoing = isClassOngoingNow(c);

                return `
                    <li class="flex gap-3 px-4 py-3.5 sm:gap-4 sm:px-5 ${c.palette.accentBorder}${ongoing ? ' bg-surface-tint/30' : ''}" data-class-card>
                        <div class="flex w-16 shrink-0 flex-col items-center justify-center rounded-lg border px-2 py-2 text-center sm:w-20 ${isToday ? 'border-brand/20 bg-surface-tint/60' : 'border-border-light bg-surface/60'}">
                            <div class="text-xs font-semibold ${isToday ? 'text-brand-text' : 'text-ink'}">${app.escapeHtml(c.day || '-')}</div>
                            <div class="mt-0.5 text-xs font-semibold tabular-nums text-ink">${app.escapeHtml(c.startTimeFormatted || '-')}</div>
                            ${c.endTimeFormatted && c.endTimeFormatted !== '-' ? `<div class="text-[11px] tabular-nums text-ink-subtle">${app.escapeHtml(c.endTimeFormatted)}</div>` : ''}
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-col gap-3 md:flex-row md:items-center">
                                <div class="min-w-0 flex-1">
                                    <div class="mb-1 flex min-w-0 flex-wrap items-center gap-1.5">
                                        <span class="${c.palette.badge} font-mono">${app.escapeHtml(c.courseCode || 'KELAS')}</span>
                                        <span class="${app.ui.badge.neutral}">${app.escapeHtml(c.className)}</span>
                                        ${ongoing ? `<span class="${app.ui.badge.brand}">Sedang berlangsung</span>` : ''}
                                    </div>
                                    <h3 class="mb-1.5 text-sm font-semibold leading-snug text-ink">
                                        ${c.classUrl ? `<a href="${c.classUrl}" class="text-ink no-underline transition-colors hover:text-brand-text">${app.escapeHtml(c.courseName)}</a>` : app.escapeHtml(c.courseName)}
                                    </h3>
                                    <div class="flex flex-wrap gap-x-4 gap-y-1.5 text-xs text-ink-muted">
                                        <span class="inline-flex min-w-0 items-center gap-1">
                                            ${app.renderIcon('user', 'sm', 'size-3.5! text-ink-subtle')}
                                            <span class="truncate">${app.escapeHtml(c.lecturerName)}</span>
                                        </span>
                                        <span class="inline-flex items-center gap-1">
                                            ${app.renderIcon('map-pin', 'sm', 'size-3.5! text-ink-subtle')}
                                            ${app.escapeHtml(c.roomName)}
                                        </span>
                                    </div>
                                </div>
                                <div class="flex shrink-0 flex-row flex-wrap gap-2">
                                    ${c.classUrl ? `<a href="${c.classUrl}" class="${app.buttonClass('secondary', { small: true })}">Buka kelas</a>` : app.renderDisabledButton('Tidak tersedia')}
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

            const renderClassList = (rows) => `
                <div class="${app.ui.card} overflow-hidden" data-slot="card">
                    <ul class="${app.ui.list}">${rows.map(renderCard).join('')}</ul>
                </div>
            `;

            const renderCardList = (classes, selectedDay, searchQuery = '') => {
                let filtered = classes;
                if (selectedDay !== 'all') {
                    filtered = filtered.filter((c) => c.day === selectedDay);
                }

                if (searchQuery) {
                    const q = searchQuery.toLowerCase();
                    filtered = filtered.filter((c) =>
                        c.courseName.toLowerCase().includes(q) ||
                        c.courseCode.toLowerCase().includes(q) ||
                        c.lecturerName.toLowerCase().includes(q) ||
                        c.roomName.toLowerCase().includes(q)
                    );
                }

                if (!filtered.length) {
                    const msg = searchQuery
                        ? `Tidak ada kelas yang cocok dengan "${app.escapeHtml(searchQuery)}".`
                        : selectedDay !== 'all'
                        ? `Tidak ada kelas pada hari ${selectedDay}.`
                        : 'Belum ada kelas pada sumber ini.';
                    listHost.innerHTML = app.renderEmptyState(msg, 'secondary');
                    return;
                }

                // Chronological sorting: Senin -> Sabtu, then by startMin
                const dayOrder = { 'Senin': 1, 'Selasa': 2, 'Rabu': 3, 'Kamis': 4, 'Jumat': 5, 'Sabtu': 6, 'Minggu': 7 };
                const sorted = [...filtered].sort((a, b) => {
                    const dA = dayOrder[a.day] || 99;
                    const dB = dayOrder[b.day] || 99;
                    if (dA !== dB) return dA - dB;
                    return (a.startMin ?? 9999) - (b.startMin ?? 9999);
                });

                listHost.innerHTML = renderClassList(sorted);
                bindMeetingPanels(listHost);
            };

            const renderUnscheduledClasses = (unscheduled) => {
                if (!unscheduled || !unscheduled.length) {
                    unscheduledHost.classList.add('hidden');
                    unscheduledHost.innerHTML = '';
                    return;
                }

                unscheduledHost.classList.remove('hidden');
                unscheduledHost.innerHTML = `
                    <div class="${app.ui.card} overflow-hidden" data-slot="card">
                        <div class="${app.ui.cardHeader}">
                            <div class="${app.ui.sectionTitleRow}">
                                <h3 class="${app.ui.cardTitle}">Tanpa jadwal tetap</h3>
                                <span class="${app.ui.sectionCount}">${unscheduled.length}</span>
                            </div>
                        </div>
                        <ul class="${app.ui.list}">${unscheduled.map(renderCard).join('')}</ul>
                    </div>
                `;
                bindMeetingPanels(unscheduledHost);
            };

