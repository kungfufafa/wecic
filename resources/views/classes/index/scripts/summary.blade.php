{{--
    Jadwal & Kelas script, the summary tiles (Kelas, Semester, Hari aktif)
    and the day strip that filters by day. Part of the DOMContentLoaded
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
            };

            const renderDaySummaryStrip = (dayMap) => {
                dayStripHost.innerHTML = DAYS_LIST.map((d) => {
                    const group = dayMap[d];
                    const isToday = d === TODAY_DAY;
                    const count = group.classes.length;
                    const hasClasses = count > 0;
                    const isSelected = selectedDayFilter === d;
                    const gapNote = group.gaps.length > 0
                        ? `<div class="text-[10px] mt-1 text-amber-700 dark:text-amber-400 font-medium">${group.gaps.length} jeda</div>`
                        : '';

                    return `
                        <div role="button" tabindex="0" aria-pressed="${isSelected ? 'true' : 'false'}" class="rounded-lg border p-3 transition-all duration-150 cursor-pointer focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand ${
                            isSelected
                                ? 'border-brand bg-brand/5 shadow-xs ring-2 ring-brand/20'
                                : isToday
                                ? 'border-brand/40 bg-surface/70'
                                : 'border-border bg-panel hover:border-brand/30 hover:bg-surface/40'
                        }" data-day-trigger="${d}" title="${isSelected ? 'Tampilkan semua hari' : `Filter ${d}`}">
                            <div class="flex items-center justify-between gap-1 mb-1">
                                <span class="text-xs font-bold text-ink">${d}</span>
                                ${isToday ? '<span class="rounded bg-brand px-1.5 py-0.5 text-[10px] font-bold text-white uppercase tracking-tight">Hari Ini</span>' : ''}
                            </div>
                            <div class="text-sm font-extrabold ${hasClasses ? 'text-brand' : 'text-ink-subtle'}">
                                ${hasClasses ? `${count} kelas` : 'Libur'}
                            </div>
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

