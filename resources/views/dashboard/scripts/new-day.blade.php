{{--
    Beranda script, labels that depend on the date (countdowns, "Hari
    ini") when the day changes or the tab is shown again. Part of the
    DOMContentLoaded handler opened in dashboard.blade.php: it shares that
    scope (app, esc, the constants and state in setup).
--}}
            // ---- Time-sensitive labels (countdowns, "Hari ini") ----------------------------------

            const refreshForNewDay = () => {
                if (todayIso() === renderedDay) {
                    return false;
                }

                renderedDay = todayIso();
                renderSignatures.clear();
                renderActionCenter(latestActionPayload);
                renderAnnouncements(latestAnnouncementPayload);
                renderDashboard(latestDashboardPayload);

                if (latestAssignmentsPayload) {
                    renderPendingAssignments(latestAssignmentsPayload);
                }

                void Promise.allSettled([loadDashboard({ background: true }), loadPendingAssignments({ background: true }), loadActionCenter({ background: true })]);

                return true;
            };

            // A new day and a tab shown again re-read on the page's own initiative: those reads
            // never count as the student's activity, so an open tab still idles out.
            window.setInterval(refreshForNewDay, 60000);
            document.addEventListener('visibilitychange', () => {
                if (document.visibilityState !== 'visible' || refreshForNewDay() || !navigator.onLine) {
                    return;
                }

                // Back after a while: re-read the snapshots (never a forced portal scrape).
                if (Date.now() - lastActionFetchAt > 5 * 60 * 1000) {
                    void loadActionCenter({ background: true });
                }

                if (Date.now() - lastDashboardFetchAt > 10 * 60 * 1000) {
                    void loadDashboard({ background: true });
                }
            });

