{{--
    Jadwal & Kelas script, the KPI strip (Kelas, Semester, Hari aktif, Hari
    ini) and the week strip that filters by day: a segmented strip with the
    selected day raised (aria-pressed) and today marked with a blue dot
    (aria-current="date"). Part of the DOMContentLoaded
    handler opened in classes/index.blade.php: it shares that scope (app,
    the elements, constants and state in setup).
--}}
            const renderSummary = (payload, parsedClasses, dayMap) => {
                const semester = payload.semester_info?.semester || payload.semester_info?.label || payload.academic_summary?.semester || '-';

                summaryCards[0].textContent = String(parsedClasses.length || '0');
                summaryCards[1].textContent = semester;

                let activeDaysCount = 0;
                let totalWeeklyMinutes = 0;

                DAYS_LIST.forEach((d) => {
                    if (dayMap[d].classes.length > 0) {
                        activeDaysCount++;
                        totalWeeklyMinutes += dayMap[d].totalMinutes;
                    }
                });

                if (activeDaysMetric) {
                    activeDaysMetric.textContent = String(activeDaysCount);
                }
                if (totalHoursMetric) {
                    const totalHours = (totalWeeklyMinutes / 60).toFixed(1).replace('.0', '');
                    totalHoursMetric.textContent = `${totalHours} jam / minggu`;
                }

                const todayGroup = dayMap[TODAY_DAY];
                const todayCount = todayGroup ? todayGroup.classes.length : 0;

                if (todayMetric) {
                    todayMetric.textContent = String(todayCount);
                }
                if (todayNote) {
                    todayNote.textContent = todayCount > 0 ? `kelas · ${TODAY_DAY}` : `${TODAY_DAY}, libur`;
                }
            };

            // Full class strings per state: cells of a bordered 1px-divider strip.
            const DAY_SEGMENT_BASE = 'flex min-h-11 min-w-0 cursor-pointer select-none flex-col items-start gap-1 p-3.5 text-start transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brand/40 sm:p-4';
            const DAY_SEGMENT_SELECTED = 'bg-surface-tint text-ink ring-1 ring-inset ring-brand/25';
            const DAY_SEGMENT_TODAY = 'bg-panel text-ink hover:bg-surface/60';
            const DAY_SEGMENT_IDLE = 'bg-panel text-ink-muted hover:bg-surface/60 hover:text-ink';

            const renderDaySummaryStrip = (dayMap) => {
                dayStripHost.innerHTML = DAYS_LIST.map((d) => {
                    const group = dayMap[d];
                    const isToday = d === TODAY_DAY;
                    const count = group.classes.length;
                    const hasClasses = count > 0;
                    const isSelected = selectedDayFilter === d;
                    const gapNote = group.gaps.length > 0
                        ? `<span class="text-xs text-ink-subtle tabular-nums">${group.gaps.length} jeda</span>`
                        : '';
                    const stateClass = isSelected ? DAY_SEGMENT_SELECTED : (isToday ? DAY_SEGMENT_TODAY : DAY_SEGMENT_IDLE);
                    const labelClass = isSelected ? 'text-brand-text font-semibold' : (isToday ? 'text-ink font-semibold' : 'text-ink-muted font-medium');
                    const countClass = hasClasses
                        ? (isSelected ? 'text-brand-text font-semibold' : 'text-ink font-semibold')
                        : 'text-ink-subtle font-normal';

                    // aria-pressed marks the selected day; aria-current="date" marks today.
                    return `
                        <div role="button" tabindex="0" aria-pressed="${isSelected ? 'true' : 'false'}"${isToday ? ' aria-current="date"' : ''} class="${DAY_SEGMENT_BASE} ${stateClass}" data-day-trigger="${d}" title="${isSelected ? 'Tampilkan semua hari' : `Filter ${d}`}">
                            <span class="flex w-full items-center gap-1.5">
                                <span class="text-xs ${labelClass}">${d}</span>
                                ${isToday ? `<span class="block size-1.5 shrink-0 rounded-full ${app.ui.fill.brand}" aria-hidden="true"></span><span class="sr-only">Hari ini</span>` : ''}
                            </span>
                            <span class="text-sm leading-tight tabular-nums ${countClass}">
                                ${hasClasses ? `${count} kelas` : 'Libur'}
                            </span>
                            ${gapNote}
                        </div>
                    `;
                }).join('');

                dayStripHost.querySelectorAll('[data-day-trigger]').forEach((el) => {
                    onActivate(el, () => {
                        const day = el.dataset.dayTrigger;
                        setDayFilter(selectedDayFilter === day ? 'all' : day);
                        // The strip is re-rendered: keep focus on the same day.
                        dayStripHost.querySelector(`[data-day-trigger="${day}"]`)?.focus();
                    });
                });
            };

