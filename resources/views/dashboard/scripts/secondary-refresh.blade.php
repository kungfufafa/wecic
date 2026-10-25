{{--
    Beranda script, asking the server to rebuild stale snapshots behind
    the page after a pull-to-refresh. Part of the DOMContentLoaded handler
    opened in dashboard.blade.php: it shares that scope (app, esc, the
    constants and state in setup).
--}}
            // ---- Background refresh of the secondary snapshots -----------------------------------

            const secondarySourceStates = () => {
                const states = {
                    ...(latestDashboardPayload?.data?.metrics?.sources || {}),
                    ...(latestActionPayload?.meta?.sources || {}),
                };
                const context = latestDashboardPayload?.data?.portal_context;
                const announcementMeta = latestAnnouncementPayload?.meta;

                if (!states.portal_context && context?.extracted_at) {
                    states.portal_context = { status: 'ready', synced_at: context.extracted_at };
                }

                if (!states.announcements && announcementMeta?.synced_at && announcementMeta.data_origin !== 'device_cache') {
                    states.announcements = { status: announcementMeta.status === 'syncing' ? 'syncing' : 'ready', synced_at: announcementMeta.synced_at };
                }

                return states;
            };

            const staleSecondaryDatasets = () => {
                const states = secondarySourceStates();

                return app.filterRefreshableDomains(SECONDARY_DATASETS.filter((dataset) => {
                    const state = states[dataset];

                    if (!state) {
                        return true;
                    }

                    // Already being built or refreshed in the background.
                    if (state.status === 'syncing' || state.status === 'pending') {
                        return false;
                    }

                    const syncedAt = app.toDate(state.synced_at)?.getTime();

                    return !syncedAt || Date.now() - syncedAt > SECONDARY_REFRESH_AFTER_MS;
                }));
            };

            /**
             * Pull-to-refresh: rebuild the stale snapshots behind the hero, metrics, action
             * centre and announcements in one background job; the realtime sync re-renders them.
             * At most once a minute, since it shares the portal action rate limit.
             */
            const queueSecondaryRefresh = async () => {
                if (!navigator.onLine || Date.now() - lastSecondaryRefreshAt < SECONDARY_REFRESH_COOLDOWN_MS) {
                    return;
                }

                const domains = staleSecondaryDatasets();

                if (!domains.length) {
                    return;
                }

                lastSecondaryRefreshAt = Date.now();

                try {
                    await app.request('/api/refresh', {
                        method: 'POST',
                        json: { domains },
                    });
                } catch (error) {
                    // The snapshots on screen stay usable; the next pull tries again.
                    lastSecondaryRefreshAt = 0;
                }
            };

