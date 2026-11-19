{{--
    Jadwal & Kelas script, the Daftar view (one card, a divided row per
    class) and the classes without a fixed schedule. Part of the
    DOMContentLoaded handler opened in classes/index.blade.php: it shares
    that scope (app, the elements, constants and state in setup).
--}}
            // A class as a row of the Daftar list: day and time on the left, the
            // course, and its actions.
            const renderCard = (c) => {
                return `
                    <li class="flex gap-3 px-4 py-3 sm:gap-4 sm:px-5" data-class-card>
                        <div class="w-12 shrink-0 pt-0.5 text-xs sm:w-16">
                            <div class="font-medium text-ink">${app.escapeHtml(c.day || '-')}</div>
                            <div class="mt-0.5 tabular-nums text-ink-subtle">${app.escapeHtml(c.startTimeFormatted || '-')}</div>
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-col gap-3 md:flex-row md:items-center">
                                <div class="min-w-0 flex-1">
                                    <div class="mb-1 flex min-w-0 flex-wrap items-center gap-x-1.5 text-xs text-ink-subtle">
                                        <span class="font-mono">${app.escapeHtml(c.courseCode || '-')}</span>
                                        <span class="truncate before:me-1.5 before:content-['·']">${app.escapeHtml(c.lecturerName)}</span>
                                    </div>
                                    <h3 class="mb-1.5 text-sm font-medium leading-snug text-ink">
                                        ${c.classUrl ? `<a href="${c.classUrl}" class="text-ink no-underline transition-colors hover:text-brand-text">${app.escapeHtml(c.courseName)}</a>` : app.escapeHtml(c.courseName)}
                                    </h3>
                                    <div class="flex flex-wrap gap-x-4 gap-y-1.5 text-xs text-ink-muted">
                                        <span class="inline-flex items-center gap-1">
                                            ${app.renderIcon('clock', 'sm', 'text-ink-subtle')}
                                            <span class="tabular-nums">${app.escapeHtml(c.formattedTime)}</span>
                                        </span>
                                        <span class="inline-flex items-center gap-1">
                                            ${app.renderIcon('users', 'sm', 'text-ink-subtle')}
                                            ${app.escapeHtml(c.className)}
                                        </span>
                                        <span class="inline-flex items-center gap-1">
                                            ${app.renderIcon('map-pin', 'sm', 'text-ink-subtle')}
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

