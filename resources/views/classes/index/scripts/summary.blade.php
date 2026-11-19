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
                        ? `<div class="mt-1 font-mono text-caption font-medium text-warning tabular-nums">${group.gaps.length} jeda</div>`
                        : '';

                    return `
                        <div role="button" tabindex="0" aria-pressed="${isSelected ? 'true' : 'false'}" class="rounded-[var(--radius-control)] border p-3 transition-colors duration-150 cursor-pointer focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand ${
                            isSelected
                                ? 'border-brand bg-surface-tint text-brand-text'
                                : isToday
                                ? 'border-ink-subtle/40 bg-surface/60'
                                : 'border-border bg-panel hover:border-ink-subtle/35 hover:bg-surface/40'
                        }" data-day-trigger="${d}" title="${isSelected ? 'Tampilkan semua hari' : `Filter ${d}`}">
                            <div class="flex items-center justify-between gap-1.5 mb-1">
                                <span class="text-caption font-medium text-ink">${d}</span>
                                ${isToday ? '<span class="inline-flex h-1.5 w-1.5 shrink-0 rounded-full bg-brand" aria-hidden="true"></span><span class="sr-only">Hari ini</span>' : ''}
                            </div>
                            <div class="font-mono text-sm font-medium tabular-nums ${hasClasses ? 'text-brand-text' : 'text-ink-subtle'}">
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

