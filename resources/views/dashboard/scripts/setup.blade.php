{{--
    Beranda script, the page's constants, shared state and helpers (render
    signatures, re-check timers, whenIdle). Every later part uses them.
    Part of the DOMContentLoaded handler opened in dashboard.blade.php: it
    shares that scope (app, esc, the constants and state in setup).
--}}
            const app = window.portalApp;
            const esc = (value) => app.escapeHtml(value);
            const todayIso = () => new Date().toLocaleDateString('sv-SE');

            const ACTION_PREVIEW_LIMIT = 3;
            const ASSIGNMENT_PREVIEW_LIMIT = 3;
            const RECHECK_LIMIT = 3;
            const RECHECK_DELAY_MS = 20000;
            const ANNOUNCEMENT_READ_KEY = 'mycic-announcement-read';
            const SECONDARY_REFRESH_AFTER_MS = 10 * 60 * 1000;
            const SECONDARY_REFRESH_COOLDOWN_MS = 60 * 1000;
            // Snapshots behind the hero, the metrics, the task list and the action centre: a
            // pull-to-refresh asks the server to rebuild the stale ones in the background (GET
            // ?fresh=1 only forces the main dashboard dataset; the task list crawls every class
            // and meeting page, which never runs inline).
            const SECONDARY_DATASETS = ['portal_context', 'krs', 'advising', 'grades', 'payments', 'skkm', 'announcements', 'exams', 'assignments'];
            const SOURCE_LABELS = {
                portal_context: 'info periode',
                krs: 'KRS',
                advising: 'perwalian',
                grades: 'nilai',
                payments: 'tagihan',
                skkm: 'SKKM',
                announcements: 'pengumuman',
                assignments: 'tugas',
                exams: 'ujian',
                dashboard: 'jadwal',
            };
            const METRIC_VALUE_TONES = ['text-success', 'text-danger', 'text-warning', 'text-ink-subtle'];
            // A long value in a half-width card: smaller on a phone and in the four-column strip at lg.
            const METRIC_LONG_VALUE_CLASSES = ['text-lg', 'sm:text-2xl', 'lg:text-xl', 'xl:text-2xl'];
            const ICONS = {
                clock: '<svg xmlns="http://www.w3.org/2000/svg" class="size-3.5 shrink-0" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12a9 9 0 1 0 18 0a9 9 0 0 0 -18 0" /><path d="M12 7v5l3 3" /></svg>',
                check: '<svg xmlns="http://www.w3.org/2000/svg" class="size-5" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12l5 5l10 -10" /></svg>',
                alert: '<svg xmlns="http://www.w3.org/2000/svg" class="size-3.5" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 9v4" /><path d="M10.363 3.591l-8.106 13.534a1.914 1.914 0 0 0 1.636 2.871h16.214a1.914 1.914 0 0 0 1.636 -2.87l-8.106 -13.536a1.914 1.914 0 0 0 -3.274 0z" /><path d="M12 16h.01" /></svg>',
                external: '<svg xmlns="http://www.w3.org/2000/svg" class="size-3.5" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 6h-6a2 2 0 0 0 -2 2v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-6" /><path d="M11 13l9 -9" /><path d="M15 4h5v5" /></svg>',
            };

            let loadDashboard = async () => {};
            let loadPendingAssignments = async () => {};
            let loadActionCenter = async () => {};
            let loadAnnouncements = async () => {};
            let latestClassesSummary = null;
            let latestAssignments = [];
            let latestDashboardPayload = null;
            let latestAssignmentsPayload = null;
            let latestActionPayload = null;
            let latestAnnouncementPayload = null;
            let ownActionItemsRequest = null;
            let assignmentsExpanded = false;
            let lastSecondaryRefreshAt = 0;
            let lastActionFetchAt = 0;
            let lastDashboardFetchAt = 0;
            let renderedDay = todayIso();
            const renderSignatures = new Map();
            const recheckTimers = new Map();
            const recheckAttempts = new Map();

            const panelTone = (color) => app.statusPanelClass(color === 'primary' ? 'brand' : color);
            const textTone = (color) => app.textToneClass(color === 'primary' ? 'brand' : color);

            /**
             * True when `value` differs from what this widget last rendered. Background syncs
             * re-run every widget; an unchanged one is left alone so a typed attendance code or
             * a chosen file is never wiped.
             */
            const hasChanged = (key, value) => {
                const signature = JSON.stringify(value ?? null);

                if (renderSignatures.get(key) === signature) {
                    return false;
                }

                renderSignatures.set(key, signature);

                return true;
            };

            /**
             * "KRS", "KRS dan tagihan", "KRS, nilai, dan tagihan".
             */
            const joinSourceLabels = (sources) => {
                const labels = [...new Set((sources || []).map((source) => SOURCE_LABELS[source] || source))];

                if (labels.length <= 1) {
                    return labels.join('');
                }

                if (labels.length === 2) {
                    return labels.join(' dan ');
                }

                return `${labels.slice(0, -1).join(', ')}, dan ${labels[labels.length - 1]}`;
            };

            const studentInfo = () => {
                const fromDashboard = latestDashboardPayload?.data?.user_info;

                if (fromDashboard && (fromDashboard.name || fromDashboard.nama)) {
                    return fromDashboard;
                }

                try {
                    return app.getAuthSnapshot()?.data?.user_info || {};
                } catch (error) {
                    return {};
                }
            };

            /**
             * A snapshot that is still being built is re-read a few times when no realtime
             * connection will announce it. `run` gets `{ background: true }` to pass on to its read.
             */
            const scheduleRecheck = (key, isPending, run) => {
                if (!isPending) {
                    window.clearTimeout(recheckTimers.get(key));
                    recheckTimers.delete(key);
                    recheckAttempts.delete(key);

                    return;
                }

                // One re-read waits at a time; repeated renders of the same data do not stack.
                if (recheckTimers.has(key)) {
                    return;
                }

                const attempt = (recheckAttempts.get(key) || 0) + 1;

                if (attempt > RECHECK_LIMIT) {
                    return;
                }

                recheckAttempts.set(key, attempt);
                recheckTimers.set(key, window.setTimeout(() => {
                    recheckTimers.delete(key);

                    // With realtime, the finished sync re-runs the widget by itself.
                    if (app.realtimeConnected || !navigator.onLine) {
                        return;
                    }

                    // The page re-reads on its own: not the student's activity.
                    void run({ background: true });
                }, RECHECK_DELAY_MS * attempt));
            };

            const whenIdle = (callback) => {
                if (typeof window.requestIdleCallback === 'function') {
                    window.requestIdleCallback(callback, { timeout: 2000 });
                } else {
                    window.setTimeout(callback, 600);
                }
            };

