{{--
    Beranda script, the loaders, their sync handlers, and the first load.
    Part of the DOMContentLoaded handler opened in dashboard.blade.php: it
    shares that scope (app, esc, the constants and state in setup).
--}}
            // ---- Load ----------------------------------------------------------------------------

            app.primeCachedView('/api/announcement', renderAnnouncements, 'pengumuman');
            app.primeCachedView('/api/action-items', renderActionCenter, 'pusat tindakan');
            app.primeCachedView('/api/assignment/pending', renderPendingAssignments, 'tugas belum dikumpulkan');
            app.primeCachedView('/api/dashboard', renderDashboard, 'ringkasan beranda');

            const ready = await app.ensureAuthenticated();
            // The last schedule read that answered, so the task list can put its line back.
            let dashboardSync = null;

            if (!ready) {
                return;
            }

            loadDashboard = async ({ force = false, background = false } = {}) => {
                lastDashboardFetchAt = Date.now();

                try {
                    const payload = await app.request('/api/dashboard', { fresh: force, background });
                    renderDashboard(payload);
                    dashboardSync = { payload, force };
                    app.markViewSynced('Beranda', payload, { force });
                } catch (error) {
                    dashboardSync = null;
                    app.setSyncStatus('Gagal memuat beranda.', 'error');
                    if (periodHost.hasAttribute('aria-busy')) {
                        renderPeriod(null, []);
                    }

                    markMetricsUnavailable();

                    const summaryHost = document.getElementById('dashboard-class-summary');
                    const shownSchedule = latestClassesSummary && (!latestClassesSummary.date || latestClassesSummary.date === todayIso());

                    if (shownSchedule) {
                        // Today's schedule from the device stays usable; say it may be outdated.
                        const noticeNode = document.getElementById('dashboard-today-notice');
                        noticeNode.innerHTML = `
                            <div class="${app.alertTone('warning')} mb-3 flex flex-wrap items-center justify-between gap-2" role="status">
                                <span>Jadwal belum bisa diperbarui. Yang tampil adalah data tersimpan.</span>
                                <button type="button" class="${app.buttonClass('secondary', { small: true })}" data-dashboard-retry>Coba lagi</button>
                            </div>
                        `;
                        noticeNode.querySelector('[data-dashboard-retry]')?.addEventListener('click', () => {
                            noticeNode.innerHTML = '';
                            void loadDashboard({ force });
                        });
                    } else {
                        renderSignatures.delete('today');
                        app.mountErrorState(
                            summaryHost,
                            'Jadwal hari ini gagal dimuat.',
                            () => loadDashboard({ force }),
                            { subtitle: app.describeError(error) }
                        );
                    }
                }

                if (force) {
                    void queueSecondaryRefresh();
                }
            };

            loadPendingAssignments = async ({ force = false, background = false } = {}) => {
                try {
                    // A snapshot read: a forced refresh rebuilds the task list in the
                    // background (queueSecondaryRefresh) instead of crawling every class here.
                    const payload = await app.request('/api/assignment/pending', { fresh: false, background });
                    renderPendingAssignments(payload);

                    // The action centre only reuses the assignments snapshot; this read just built it.
                    if (latestActionPayload?.meta?.sources?.assignments?.status === 'missing') {
                        void loadActionCenter({ background });
                    }

                    // The strip keeps the schedule's age, not the task list's: the task crawl
                    // visits every class and meeting page, so its older time would read as a
                    // stale schedule. Writing it again also clears an earlier "Gagal memuat tugas."
                    if (dashboardSync) {
                        app.markViewSynced('Beranda', dashboardSync.payload, { force: dashboardSync.force });
                    }
                } catch (error) {
                    app.setSyncStatus('Gagal memuat tugas.', 'error');
                    renderSignatures.delete('assignments');
                    app.mountErrorState(
                        document.getElementById('dashboard-pending-assignments'),
                        'Daftar tugas gagal dimuat.',
                        () => loadPendingAssignments({ force }),
                        { subtitle: app.describeError(error) }
                    );
                }
            };

            loadActionCenter = async ({ force = false, background = false } = {}) => {
                const shared = app.actionItemsRequest;

                // A realtime update (or the nav badges) already started a read: reuse it.
                if (!force && shared && shared !== ownActionItemsRequest) {
                    ownActionItemsRequest = shared;
                    await shared;

                    const cached = app.getCachedResponse('/api/action-items');

                    if (cached?.payload) {
                        renderActionCenter(cached.payload);

                        return;
                    }

                    // That read failed and left nothing behind: ask once more so the card can say why.
                }

                lastActionFetchAt = Date.now();

                // Built from snapshots only, so this never waits on the portal.
                const request = app.request('/api/action-items', { fresh: force, timeout: 15000, background });
                ownActionItemsRequest = request
                    .then((payload) => app.extractActionItems(payload))
                    .catch(() => app.actionItems || []);
                // The nav badges read this same response instead of asking again.
                app.actionItemsRequest = ownActionItemsRequest;

                try {
                    const payload = await request;

                    if (!payload) {
                        return;
                    }

                    renderActionCenter(payload);
                    app.receiveActionItems(app.extractActionItems(payload));
                } catch (error) {
                    if (latestActionPayload) {
                        // Keep the list on screen; the sync strip says the refresh failed.
                        app.setSyncStatus('Pusat tindakan belum bisa diperbarui.', 'warning');

                        return;
                    }

                    renderSignatures.delete('actions');
                    actionsHost.removeAttribute('aria-busy');
                    app.mountErrorState(
                        actionsHost,
                        'Pusat tindakan gagal dimuat.',
                        () => loadActionCenter({ force }),
                        { subtitle: app.describeError(error) }
                    );
                }
            };

            loadAnnouncements = async ({ force = false, background = false } = {}) => {
                try {
                    // A snapshot read: pull-to-refresh rebuilds the feed in the background
                    // (queueSecondaryRefresh) instead of scraping three portal pages here.
                    const payload = await app.request('/api/announcement', { fresh: false, background });

                    renderAnnouncements(payload);
                } catch (error) {
                    if (announcementsRendered) {
                        return;
                    }

                    renderSignatures.delete('announcements');
                    announcementsHost.removeAttribute('aria-busy');
                    app.mountErrorState(
                        announcementsHost,
                        'Pengumuman gagal dimuat.',
                        () => loadAnnouncements({ force }),
                        { subtitle: app.describeError(error) }
                    );
                }
            };

            app.registerSyncHandler('dashboard-overview', loadDashboard, { aliases: ['portal-context'] });
            app.registerSyncHandler('dashboard-pending-assignments', loadPendingAssignments);
            app.registerSyncHandler('dashboard-action-items', loadActionCenter);
            app.registerSyncHandler('dashboard-announcements', loadAnnouncements, { aliases: ['announcement-index'] });

            // The announcement card is secondary: it loads once the first paint is done.
            const announcementsLoaded = new Promise((resolve) => {
                whenIdle(() => resolve(loadAnnouncements()));
            });

            // The task list is read once the schedule has answered: a stale schedule then queues
            // its re-sync before the task list queues its crawl of every class and meeting page,
            // and the worker runs them in that order.
            const dashboardLoaded = loadDashboard();

            await Promise.allSettled([
                dashboardLoaded,
                dashboardLoaded.then(() => loadPendingAssignments()),
                loadActionCenter(),
                announcementsLoaded,
            ]);
