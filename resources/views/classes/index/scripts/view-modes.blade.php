{{--
    Jadwal & Kelas script, switching between the Grid, Timeline and Daftar
    views, the day filter, and the search. Part of the DOMContentLoaded
    handler opened in classes/index.blade.php: it shares that scope (app,
    the elements, constants and state in setup).
--}}
            const switchView = (targetView, { persist = true } = {}) => {
                currentViewMode = targetView;

                if (persist) {
                    try {
                        localStorage.setItem('mycic-class-view-mode', targetView);
                    } catch (error) {
                        // Storage blocked: the choice lasts for this visit only.
                    }
                }

                viewTabs.forEach((tab) => {
                    const isCurrent = tab.dataset.viewTarget === targetView;
                    tab.setAttribute('aria-selected', isCurrent ? 'true' : 'false');
                    if (isCurrent) {
                        tab.className = 'mycic-view-tab inline-flex items-center gap-1.5 min-h-10 whitespace-nowrap px-3 py-2 text-xs sm:text-sm font-bold rounded-lg transition-all bg-panel text-brand shadow-xs border border-border/40';
                    } else {
                        tab.className = 'mycic-view-tab inline-flex items-center gap-1.5 min-h-10 whitespace-nowrap px-3 py-2 text-xs sm:text-sm font-semibold rounded-lg transition-all text-ink-muted hover:text-ink';
                    }
                });

                Object.keys(viewPanels).forEach((k) => {
                    if (k === targetView) {
                        viewPanels[k].classList.remove('hidden');
                    } else {
                        viewPanels[k].classList.add('hidden');
                    }
                });
            };

            viewTabs.forEach((tab) => {
                tab.addEventListener('click', () => {
                    switchView(tab.dataset.viewTarget);
                });
            });

            switchView(currentViewMode, { persist: persistViewMode });

            const setDayFilter = (day) => {
                selectedDayFilter = day;
                const { dayMap, unscheduled } = processDayGroups(cachedClassesData);
                renderDaySummaryStrip(dayMap);
                renderBlockingGrid(dayMap);
                renderTimeline(dayMap);
                renderCardList(cachedClassesData, selectedDayFilter, currentSearchQuery);
                renderUnscheduledClasses(unscheduled);
            };

            if (searchInput) {
                searchInput.addEventListener('input', (e) => {
                    currentSearchQuery = e.target.value;
                    const { dayMap, unscheduled } = processDayGroups(cachedClassesData);
                    renderBlockingGrid(dayMap);
                    renderTimeline(dayMap);
                    renderCardList(cachedClassesData, selectedDayFilter, currentSearchQuery);
                    renderUnscheduledClasses(unscheduled);
                });
            }

