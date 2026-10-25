{{--
    Jadwal & Kelas script, the Daftar view's class cards and the classes
    without a fixed schedule. Part of the DOMContentLoaded handler opened in
    classes/index.blade.php: it shares that scope (app, the elements,
    constants and state in setup).
--}}
            const renderCard = (c) => {
                return `
                    <div class="mycic-card-interactive" data-class-card>
                        <div class="flex flex-col gap-3 md:flex-row md:items-center">
                            <div class="min-w-0 flex-1">
                                <div class="mb-1 flex items-center gap-2 text-caption text-ink-subtle">
                                    ${app.renderBadge(c.courseCode || '-', 'brand')}
                                    <span>${app.escapeHtml(c.lecturerName)}</span>
                                </div>
                                <h3 class="mb-2 text-subtitle font-bold text-ink">
                                    ${c.classUrl ? `<a href="${c.classUrl}" class="text-ink no-underline hover:text-brand">${app.escapeHtml(c.courseName)}</a>` : app.escapeHtml(c.courseName)}
                                </h3>
                                <div class="flex flex-wrap gap-3 text-caption text-ink-subtle">
                                    <span class="inline-flex items-center gap-1">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 7a2 2 0 0 1 2 -2h12a2 2 0 0 1 2 2v12a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2v-12z" /><path d="M16 3v4" /><path d="M8 3v4" /><path d="M4 11h16" /><path d="M11 15h1" /><path d="M12 15v3" /></svg>
                                        <span class="${app.textToneClass('brand')}">${app.escapeHtml(c.day || '-')}</span> &middot; ${app.escapeHtml(c.formattedTime)}
                                    </span>
                                    <span class="inline-flex items-center gap-1">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M3 19l18 0" /><path d="M5 6m0 1a1 1 0 0 1 1 -1h12a1 1 0 0 1 1 1v12a1 1 0 0 1 -1 1h-12a1 1 0 0 1 -1 -1z" /></svg>
                                        ${app.escapeHtml(c.className)}
                                    </span>
                                    <span class="inline-flex items-center gap-1">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M3 7l6 -3l6 3l6 -3l0 13l-6 3l-6 -3l-6 3l0 -13" /><path d="M9 4l0 13" /><path d="M15 7l0 13" /></svg>
                                        ${app.escapeHtml(c.roomName)}
                                    </span>
                                </div>
                            </div>
                            <div class="flex shrink-0 flex-row gap-2 md:flex-col">
                                ${c.classUrl ? `<a href="${c.classUrl}" class="${app.buttonClass('primary', { small: true })}">Buka kelas</a>` : app.renderDisabledButton('Tidak tersedia')}
                                ${c.detailApiUrl ? `<button type="button" class="${app.buttonClass('secondary', { small: true })}" data-meeting-toggle data-detail-api="${c.detailApiUrl}">Lihat pertemuan</button>` : ''}
                            </div>
                        </div>
                        <div class="mt-3 hidden" data-meeting-panel>
                            ${app.renderEmptyState('Memuat daftar pertemuan.', 'secondary')}
                        </div>
                    </div>
                `;
            };

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

                listHost.innerHTML = sorted.map(renderCard).join('');
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
                    <div class="mycic-card p-4">
                        <h3 class="text-subtitle font-bold text-ink mb-3">Tanpa jadwal tetap (${unscheduled.length})</h3>
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            ${unscheduled.map(renderCard).join('')}
                        </div>
                    </div>
                `;
                bindMeetingPanels(unscheduledHost);
            };

