{{--
    Jadwal & Kelas script, renderSource(), the cached first paint,
    loadSource() with its sync handler, and the first load. Part of the
    DOMContentLoaded handler opened in classes/index.blade.php: it shares
    that scope (app, the elements, constants and state in setup).
--}}
            const renderSource = (payload) => {
                cachedPayload = payload;
                const rawClasses = payload.classes || [];
                const parsedClasses = rawClasses.map(parseClassItem);
                cachedClassesData = parsedClasses;

                const { dayMap, unscheduled } = processDayGroups(parsedClasses);

                renderSummary(payload, parsedClasses, dayMap);
                renderDaySummaryStrip(dayMap);
                renderBlockingGrid(dayMap);
                renderTimeline(dayMap);
                renderCardList(parsedClasses, selectedDayFilter, currentSearchQuery);
                renderUnscheduledClasses(unscheduled);
                switchView(currentViewMode, { persist: false });
            };

            app.primeCachedView(endpoint, renderSource, 'daftar kelas utama');

            const ready = await app.ensureAuthenticated();
            if (!ready) {
                return;
            }

            const loadSource = async ({ force = false, background = false } = {}) => {
                const usedCachedPayload = app.primeCachedView(endpoint, renderSource, 'daftar kelas utama');

                if (!usedCachedPayload) {
                    blockingGridHost.innerHTML = '<div class="mycic-empty py-12"><p class="mycic-empty-title text-ink-subtle">Memuat jadwal...</p></div>';
                    timelineHost.innerHTML = '<div class="mycic-empty py-12"><p class="mycic-empty-title text-ink-subtle">Memuat jadwal...</p></div>';
                    listHost.innerHTML = '<div class="mycic-empty py-8"><p class="mycic-empty-title text-ink-subtle">Memuat jadwal...</p></div>';
                }

                try {
                    const payload = await app.request(endpoint, { fresh: force, background });
                    renderSource(payload);
                    app.markViewSynced('Daftar kelas utama', payload, { force });
                } catch (error) {
                    app.setSyncStatus('Jadwal kelas gagal dimuat.', 'error');
                    [blockingGridHost, timelineHost, listHost].forEach((host) => {
                        app.mountErrorState(host, 'Jadwal kelas gagal dimuat.', () => loadSource({ force }), { subtitle: app.describeError(error) });
                    });
                }
            };

            app.registerSyncHandler('class-index', loadSource);

            await loadSource();
