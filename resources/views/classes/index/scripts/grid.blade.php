{{--
    Jadwal & Kelas script, the Grid view: overlapping classes side by side
    in each day column, and on a phone the grid scrolled to today's column.
    Part of the DOMContentLoaded handler opened in classes/index.blade.php:
    it shares that scope (app, the elements, constants and state in setup).
--}}
            // Layout overlapping classes side by side within each day column
            const calculateClassColumnLayout = (classes) => {
                const sorted = [...classes].sort((a, b) => {
                    if (a.startMin !== b.startMin) return (a.startMin ?? 0) - (b.startMin ?? 0);
                    return (b.durationMinutes ?? 0) - (a.durationMinutes ?? 0);
                });

                const clusters = [];
                let currentCluster = [];
                let clusterEnd = -1;

                sorted.forEach((c) => {
                    if (c.startMin === null) return;
                    if (currentCluster.length === 0) {
                        currentCluster.push(c);
                        clusterEnd = c.endMin ?? (c.startMin + 90);
                    } else if (c.startMin < clusterEnd) {
                        currentCluster.push(c);
                        clusterEnd = Math.max(clusterEnd, c.endMin ?? (c.startMin + 90));
                    } else {
                        clusters.push(currentCluster);
                        currentCluster = [c];
                        clusterEnd = c.endMin ?? (c.startMin + 90);
                    }
                });
                if (currentCluster.length > 0) {
                    clusters.push(currentCluster);
                }

                const layoutMap = new Map();
                clusters.forEach((cluster) => {
                    const columns = [];
                    cluster.forEach((c) => {
                        let placed = false;
                        for (let i = 0; i < columns.length; i++) {
                            if (c.startMin >= columns[i]) {
                                columns[i] = c.endMin ?? (c.startMin + 90);
                                layoutMap.set(c.id, { colIndex: i, totalCols: 1 });
                                placed = true;
                                break;
                            }
                        }
                        if (!placed) {
                            columns.push(c.endMin ?? (c.startMin + 90));
                            layoutMap.set(c.id, { colIndex: columns.length - 1, totalCols: 1 });
                        }
                    });
                    const numCols = columns.length;
                    cluster.forEach((c) => {
                        const entry = layoutMap.get(c.id);
                        if (entry) entry.totalCols = numCols;
                    });
                });

                return layoutMap;
            };

            const renderBlockingGrid = (dayMap) => {
                const daysToRender = selectedDayFilter === 'all'
                    ? DAYS_LIST
                    : DAYS_LIST.filter((d) => d === selectedDayFilter);

                let minHour = 7;
                let maxHour = 18;

                DAYS_LIST.forEach((d) => {
                    dayMap[d].classes.forEach((c) => {
                        if (c.startMin !== null) {
                            const startH = Math.floor(c.startMin / 60);
                            if (startH < minHour) minHour = startH;
                        }
                        if (c.endMin !== null) {
                            const endH = Math.ceil(c.endMin / 60);
                            if (endH > maxHour) maxHour = endH;
                        }
                    });
                });

                const totalHours = maxHour - minHour;
                const totalGridMinutes = totalHours * 60;
                const hourHeightPx = 68;
                const totalGridHeightPx = totalHours * hourHeightPx;

                const hoursArray = [];
                for (let h = minHour; h <= maxHour; h++) {
                    hoursArray.push(h);
                }

                // Grid template columns inline style ensures zero dependency on dynamic Tailwind purging
                const gridColsStyle = daysToRender.length === 1
                    ? 'grid-template-columns: 64px 1fr;'
                    : `grid-template-columns: 64px repeat(${daysToRender.length}, minmax(140px, 1fr));`;

                const containerWidthClass = daysToRender.length === 1 ? 'min-w-full' : 'min-w-[700px] md:min-w-full';

                // Current time calculation for live time indicator
                const now = new Date();
                const nowMinutesFromMidnight = now.getHours() * 60 + now.getMinutes();
                const isWithinGridHours = nowMinutesFromMidnight >= (minHour * 60) && nowMinutesFromMidnight <= (maxHour * 60);
                const nowTopPct = isWithinGridHours
                    ? ((nowMinutesFromMidnight - (minHour * 60)) / totalGridMinutes) * 100
                    : null;
                const nowFormattedTime = `${String(now.getHours()).padStart(2, '0')}:${String(now.getMinutes()).padStart(2, '0')}`;

                const searchLower = currentSearchQuery.trim().toLowerCase();

                let html = `
                    <div class="${containerWidthClass}">
                        <!-- Header Row -->
                        <div class="grid border-b border-border bg-surface/90 sticky top-0 z-30 backdrop-blur-sm rounded-t-lg" style="${gridColsStyle}">
                            <div class="p-2.5 text-center text-xs font-bold uppercase text-ink-subtle border-r border-border flex items-center justify-center sticky left-0 z-40 bg-surface/95 backdrop-blur-sm">
                                Jam
                            </div>
                            ${daysToRender.map((d) => {
                                const group = dayMap[d];
                                const isToday = d === TODAY_DAY;
                                const count = group.classes.length;

                                return `
                                    <div class="p-2.5 text-center border-r border-border last:border-r-0 ${
                                        isToday ? 'bg-brand/10 font-bold' : ''
                                    }" ${isToday ? 'data-day-column-header="today"' : ''}>
                                        <div class="flex items-center justify-center gap-1.5">
                                            <span class="text-xs sm:text-sm font-bold text-ink">${d}</span>
                                            ${isToday ? '<span class="rounded bg-brand px-1 py-0.5 text-[9px] font-bold text-white uppercase tracking-tight">Hari Ini</span>' : ''}
                                        </div>
                                        <div class="text-[11px] text-ink-subtle mt-0.5">
                                            ${count > 0 ? `${count} Kelas` : '<span class="text-ink-subtle/80">Libur</span>'}
                                        </div>
                                    </div>
                                `;
                            }).join('')}
                        </div>

                        <!-- Grid Body -->
                        <div class="grid relative" style="height: ${totalGridHeightPx}px; ${gridColsStyle}">
                            <!-- Time Axis (Sticky on left for effortless mobile horizontal scrolling) -->
                            <div class="relative border-r border-border bg-surface/85 backdrop-blur-sm select-none sticky left-0 z-20">
                                ${hoursArray.map((h, i) => {
                                    return `
                                        <div class="absolute left-0 right-0 text-center text-[11px] font-medium text-ink-subtle pt-1 border-b border-border/40" style="top: ${i * hourHeightPx}px; height: ${hourHeightPx}px;">
                                            ${String(h).padStart(2, '0')}:00
                                        </div>
                                    `;
                                }).join('')}
                            </div>

                            <!-- Day Columns -->
                            ${daysToRender.map((d) => {
                                const group = dayMap[d];
                                const isToday = d === TODAY_DAY;
                                const layoutMap = calculateClassColumnLayout(group.classes);

                                return `
                                    <div class="relative border-r border-border last:border-r-0 ${
                                        isToday ? 'bg-brand/[0.02]' : ''
                                    }">
                                        <!-- Background Hour Lines -->
                                        ${hoursArray.map((_, i) => {
                                            if (i === hoursArray.length - 1) return '';
                                            return `
                                                <div class="absolute inset-x-0 border-b border-border/40 pointer-events-none" style="top: ${i * hourHeightPx}px; height: ${hourHeightPx}px;">
                                                    <div class="w-full border-b border-dashed border-border/20" style="margin-top: ${hourHeightPx / 2}px;"></div>
                                                </div>
                                            `;
                                        }).join('')}

                                        ${group.classes.length === 0 ? `
                                            <div class="absolute inset-0 flex items-center justify-center pointer-events-none">
                                                <div class="text-xs font-medium text-ink-subtle">Libur</div>
                                            </div>
                                        ` : ''}

                                        <!-- Live Current Time Indicator Line (on Today's Column) -->
                                        ${isToday && nowTopPct !== null ? `
                                            <div class="absolute left-0 right-0 z-30 pointer-events-none flex items-center" style="top: ${nowTopPct}%;">
                                                <div class="h-2.5 w-2.5 -ml-1 rounded-full bg-red-600 ring-4 ring-red-500/20 shadow-xs"></div>
                                                <div class="flex-1 border-b-2 border-red-500 dark:border-red-400"></div>
                                                <span class="rounded bg-red-600 px-1 py-0.5 text-[9px] font-bold text-white shadow-xs">
                                                    ${nowFormattedTime}
                                                </span>
                                            </div>
                                        ` : ''}

                                        <!-- Gap Blocks (Jam Kosong) -->
                                        ${group.gaps.map((gap) => {
                                            const gapTopPct = ((gap.startMin - (minHour * 60)) / totalGridMinutes) * 100;
                                            const gapHeightPct = (gap.durationMinutes / totalGridMinutes) * 100;

                                            return `
                                                <div class="absolute left-1 right-1 rounded-md border border-dashed border-amber-500/50 bg-amber-500/10 dark:bg-amber-500/15 p-1 flex items-center justify-center text-center transition-all duration-150 hover:bg-amber-500/20 z-10 overflow-hidden"
                                                     style="top: ${gapTopPct}%; height: ${gapHeightPct}%; min-height: 24px;"
                                                     title="${gap.startTime} – ${gap.endTime}">
                                                    <span class="text-[11px] font-bold text-amber-900 dark:text-amber-200 truncate">${gap.formattedDuration}</span>
                                                </div>
                                            `;
                                        }).join('')}

                                        <!-- Class Blocks (Side-by-side if overlapping) -->
                                        ${group.classes.map((c) => {
                                            if (c.startMin === null) return '';
                                            const topPct = ((c.startMin - (minHour * 60)) / totalGridMinutes) * 100;
                                            const heightPct = Math.max(((c.durationMinutes) / totalGridMinutes) * 100, 4.5);
                                            const p = c.palette;

                                            const layout = layoutMap.get(c.id) || { colIndex: 0, totalCols: 1 };
                                            const widthPct = 100 / layout.totalCols;
                                            const leftPct = layout.colIndex * widthPct;
                                            // Two classes sharing a time slot are a real conflict, not just a tight layout.
                                            const hasConflict = layout.totalCols > 1;

                                            const matchesSearch = !searchLower || (
                                                c.courseName.toLowerCase().includes(searchLower) ||
                                                c.courseCode.toLowerCase().includes(searchLower) ||
                                                c.lecturerName.toLowerCase().includes(searchLower) ||
                                                c.roomName.toLowerCase().includes(searchLower)
                                            );

                                            return `
                                                <div role="button" tabindex="0" aria-haspopup="dialog" aria-label="${app.escapeHtml(`${c.courseName}, ${c.day || ''} ${c.formattedTime}`)}" class="absolute rounded-lg border ${p.border} ${p.accent} border-l-4 ${p.bg} p-2 cursor-pointer transition-all duration-150 hover:shadow-md hover:scale-[1.01] hover:z-20 focus-visible:z-20 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand flex flex-col justify-between overflow-hidden shadow-xs group ${
                                                    matchesSearch ? 'opacity-100 ring-1 ring-border/20' : 'opacity-30 hover:opacity-100'
                                                }"
                                                     style="top: ${topPct}%; height: ${heightPct}%; left: calc(${leftPct}% + 4px); width: calc(${widthPct}% - 8px); min-height: 52px;"
                                                     data-class-modal-trigger="${c.id}">
                                                    <div class="min-w-0 flex-1">
                                                        <div class="flex items-center justify-between gap-1 mb-0.5">
                                                            <span class="rounded px-1.5 py-0.5 text-[10px] font-extrabold ${p.timeBadge}">
                                                                ${app.escapeHtml(c.formattedTime)}
                                                            </span>
                                                            ${hasConflict
                                                                ? '<span class="rounded bg-red-600 px-1 py-0.5 text-[9px] font-bold uppercase tracking-tight text-white" title="Jadwal bentrok dengan kelas lain">Bentrok</span>'
                                                                : (c.courseCode ? `<span class="text-[9px] font-semibold text-ink-subtle uppercase truncate">${app.escapeHtml(c.courseCode)}</span>` : '')}
                                                        </div>
                                                        <div class="text-xs font-bold text-ink line-clamp-2 leading-tight group-hover:text-brand" title="${app.escapeHtml(c.courseName)}">
                                                            ${app.escapeHtml(c.courseName)}
                                                        </div>
                                                    </div>
                                                    <div class="mt-1 flex items-center justify-between gap-1 text-[10px] text-ink-subtle truncate pt-1 border-t border-black/5 dark:border-white/5">
                                                        <span class="truncate font-medium">${app.escapeHtml(c.roomName)}</span>
                                                        <span class="truncate hidden sm:inline">${app.escapeHtml((c.lecturerName || '').split(',')[0])}</span>
                                                    </div>
                                                </div>
                                            `;
                                        }).join('')}
                                    </div>
                                `;
                            }).join('')}
                        </div>
                    </div>
                `;

                blockingGridHost.innerHTML = html;
                bindModalTriggers();
                scrollGridToToday();
            };

            // On a phone the grid is wider than the screen: start on today's column.
            const scrollGridToToday = () => {
                if (selectedDayFilter !== 'all' || blockingGridHost.clientWidth >= blockingGridHost.scrollWidth) {
                    return;
                }

                const header = blockingGridHost.querySelector('[data-day-column-header="today"]');

                if (!header) {
                    return;
                }

                const timeAxisWidth = 64;
                blockingGridHost.scrollLeft = Math.max(0, header.offsetLeft - timeAxisWidth - 8);
            };

